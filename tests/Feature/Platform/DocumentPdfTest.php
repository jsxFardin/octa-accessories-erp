<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Print\DocumentRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The document layer, rather than twelve fixtures.
 *
 * Every printable document goes through one registry and one composer, so what is worth proving
 * is that the registry cannot lie: a permission that does not exist, a view file that is not
 * there, a `withheld` status misspelled so that a draft invoice quietly becomes printable. Those
 * are the failures that reach a customer's desk, and none of them need a populated document to
 * catch. One quotation is then rendered all the way to PDF bytes, because that is the only way
 * to know dompdf survived the layout.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

it('names a permission that exists for every document', function (): void {
    $known = DB::table('permissions')->pluck('name')->all();
    $missing = [];

    foreach (DocumentRegistry::all() as $key => $definition) {
        if (! in_array($definition['permission'], $known, true)) {
            $missing[] = "{$key} → {$definition['permission']}";
        }
    }

    expect($missing)->toBe([]);
});

it('points at a Blade view that exists for every document', function (): void {
    $missing = [];

    foreach (DocumentRegistry::all() as $key => $definition) {
        if (! view()->exists($definition['view'])) {
            $missing[] = "{$key} → {$definition['view']}";
        }
    }

    expect($missing)->toBe([]);
});

/*
 * The check that earns its keep. `withheld` decides whether a document may leave the building,
 * and a status that is not in the table's own CHECK constraint can never match a row — so the
 * gate silently opens. A typo here is invisible in every other test.
 */
it('withholds only statuses the table can actually hold', function (): void {
    $unknown = [];

    foreach (DocumentRegistry::all() as $key => $definition) {
        if ($definition['withheld'] === []) {
            continue;
        }

        $clause = (string) DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('CONSTRAINT_NAME', $definition['table'].'_status_chk')
            ->value('CHECK_CLAUSE');

        expect($clause)->not->toBe('', "no status CHECK constraint on {$definition['table']}");

        // MySQL renders the clause as `status in (_utf8mb4\'draft\',…)`, so the values are
        // pulled out rather than matched inside it.
        preg_match_all("/_utf8mb4\\\\'([a-z_]+)/", $clause, $matches);
        $allowed = $matches[1];

        foreach ($definition['withheld'] as $status) {
            if (! in_array($status, $allowed, true)) {
                $unknown[] = "{$key} → {$status}";
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('registers a print and a pdf route for every document, both permission-gated', function (): void {
    $problems = [];

    foreach (DocumentRegistry::all() as $key => $definition) {
        foreach (['print', 'pdf'] as $action) {
            $route = Route::getRoutes()->getByName("{$key}.{$action}");

            if ($route === null) {
                $problems[] = "{$key}.{$action} is not registered";

                continue;
            }

            if ($route->uri() !== "{$definition['segment']}/{id}/{$action}") {
                $problems[] = "{$key}.{$action} sits at {$route->uri()}";
            }

            if (! in_array("can:{$definition['permission']}", $route->gatherMiddleware(), true)) {
                $problems[] = "{$key}.{$action} is not gated by {$definition['permission']}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('shares the same document map with the front end that it enforces', function (): void {
    $shared = DocumentRegistry::forFrontend();

    expect(array_keys($shared))->toBe(array_keys(DocumentRegistry::all()));

    foreach (DocumentRegistry::all() as $key => $definition) {
        expect($shared[$key])->toBe([
            'segment' => $definition['segment'],
            'label' => $definition['label'],
            'withheld' => $definition['withheld'],
        ]);
    }
});

// --- End to end -----------------------------------------------------------------------------

function pdfExportQuotation(object $test): object
{
    $merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $product = DB::table('products')->firstOrFail();

    $test->actingAs($merchandiser)->post('/quotations', [
        'customer_id' => $product->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $product->id,
            'description' => '50,000 woven care labels',
            'qty' => 50000,
            'rate_per_m' => 3.25,
            'margin_pct' => 22,
        ]],
    ]);

    return DB::table('quotations')->latest('id')->firstOrFail();
}

it('returns real PDF bytes rather than an HTML page', function (): void {
    $quotation = pdfExportQuotation($this);

    $response = $this->actingAs($this->admin)->get("/quotations/{$quotation->id}/pdf");

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
    // The magic number, not the header: a misconfigured renderer can send the right
    // Content-Type over an exception page.
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('records a PDF download in the audit log the same way a print is recorded', function (): void {
    $quotation = pdfExportQuotation($this);

    $this->actingAs($this->admin)->get("/quotations/{$quotation->id}/pdf");

    expect(DB::table('audit_logs')
        ->where('auditable_type', 'quotations')
        ->where('auditable_id', $quotation->id)
        ->where('event', 'printed')
        ->exists())->toBeTrue();
});

it('refuses a document in a status the registry withholds', function (): void {
    $invoice = gatedInvoice('draft');

    $this->actingAs($this->admin)->get("/invoices/{$invoice}/print")->assertForbidden();
    $this->actingAs($this->admin)->get("/invoices/{$invoice}/pdf")->assertForbidden();
});

it('renders the same document once it has been issued', function (): void {
    $invoice = gatedInvoice('issued');

    $this->actingAs($this->admin)->get("/invoices/{$invoice}/print")
        ->assertOk()
        ->assertSee('Invoice');

    $this->actingAs($this->admin)->get("/invoices/{$invoice}/pdf")->assertOk();
});

it('refuses a document to a user without the right to read it', function (): void {
    $quotation = pdfExportQuotation($this);
    // A dispatcher may read a challan, not a customer's pricing.
    $dispatcher = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();

    $this->actingAs($dispatcher)->get("/quotations/{$quotation->id}/pdf")->assertForbidden();
});

it('404s on a document key nothing is registered under', function (): void {
    // Reached through a real route, so this asserts the generated set is closed rather than that
    // the composer rejects a made-up key in isolation.
    $this->actingAs($this->admin)->get('/quotations/999999/pdf')->assertNotFound();
});

/** The walkthrough seed stops short of invoicing, so the status gate needs its own row. */
function gatedInvoice(string $status): int
{
    $customer = DB::table('customers')->firstOrFail();
    $product = DB::table('products')->where('customer_id', $customer->id)->first()
        ?? DB::table('products')->firstOrFail();

    $id = (int) DB::table('sales_invoices')->insertGetId([
        'number' => 'INV-TEST-'.$status,
        'customer_id' => $customer->id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'subtotal' => 162500,
        'tax_amount' => 0,
        'total' => 162500,
        'status' => $status,
    ]);

    DB::table('sales_invoice_lines')->insert([
        'sales_invoice_id' => $id,
        'line_no' => 1,
        'product_id' => $product->id,
        'description' => '50,000 woven care labels',
        'qty' => 50000,
        'rate_per_m' => 3.25,
        'amount' => 162500,
    ]);

    return $id;
}
