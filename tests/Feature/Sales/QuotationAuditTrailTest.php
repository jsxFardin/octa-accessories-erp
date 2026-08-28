<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Support\Audit\DocumentTrail;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * F-01/F-02 — a quotation's history, on the quotation.
 *
 * The rows were being written the whole time: `Auditable` has been on `Quotation` for a while
 * and `audit_logs` holds a hundred and fifty rows against quotations. No detail page asked for
 * them, so the screen ended at the document total and "who sent this, and when" was answerable
 * only from the global admin log by someone holding `audit_log.view_any`.
 *
 * These tests hold the trail on the page, and hold the field-level detail behind the
 * permission that has always gated it.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
    $this->customer = Customer::query()->active()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

function trailQuotation(object $test): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'customer_id' => $test->customer->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '20,000 woven care labels',
            'qty' => 20000,
            'rate_per_m' => 3.10,
        ]],
    ])->assertSessionHasNoErrors();

    return Quotation::query()->latest('id')->firstOrFail();
}

it('shows the quotation its own activity trail', function (): void {
    $quotation = trailQuotation($this);

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $trail = $page->toArray()['props']['trail'];

            expect($trail)->not->toBeEmpty();

            $events = array_column($trail, 'event');

            expect($events)->toContain('created')
                ->and($events)->toContain('status_changed');
        });
});

it('describes a status change in words rather than as a status code', function (): void {
    $quotation = trailQuotation($this);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);

    $trail = app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser);

    $labels = array_column($trail, 'label');

    expect($labels)->toContain('Status changed from draft to sent');
});

it('names the order a quotation was converted into, with a link to it', function (): void {
    $quotation = trailQuotation($this);

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/convert", [])->assertRedirect();

    $order = DB::table('sales_orders')->where('quotation_id', $quotation->id)->firstOrFail();

    $entry = collect(app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser))
        ->firstWhere('event', 'converted');

    expect($entry)->not->toBeNull()
        ->and($entry['label'])->toStartWith('Converted to')
        ->and($entry['href'])->toBe("/sales-orders/{$order->id}");
});

it('records the rejection reason on the trail entry that carries it', function (): void {
    $quotation = trailQuotation($this);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", [
        'to' => 'rejected',
        'reject_reason' => 'Customer went with a cheaper supplier.',
    ]);

    $descriptions = array_filter(array_column(
        app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser),
        'description',
    ));

    expect(implode(' ', $descriptions))->toContain('cheaper supplier');
});

it('withholds the recorded field values from a viewer without the audit permission', function (): void {
    $quotation = trailQuotation($this);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);

    expect($this->merchandiser->hasPermission('audit_log.view_any'))->toBeFalse();

    $forMerchandiser = app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser);
    $forAdmin = app(DocumentTrail::class)->for($quotation->refresh(), $this->admin);

    // The narrative is for anyone who may read the document; the before/after values are the
    // auditor's view and stay behind `audit_log.view_any`.
    foreach ($forMerchandiser as $entry) {
        expect($entry)->not->toHaveKey('detail');
    }

    expect(collect($forAdmin)->contains(fn (array $entry): bool => isset($entry['detail'])))->toBeTrue();
});

it('opens the trail with a created entry even where the audit table has none', function (): void {
    // Documents seeded or imported before auditing covered them have no `created` row, and a
    // trail starting at "status changed to sent" reads as though the document appeared from
    // nowhere. The entry is derived from the document's own columns and says so — the audit
    // table is never written to in order to make a screen look complete.
    $quotation = trailQuotation($this);

    $before = DB::table('audit_logs')->count();

    DB::table('audit_logs')
        ->where('auditable_type', Quotation::class)
        ->where('auditable_id', $quotation->id)
        ->where('event', 'created')
        ->delete();

    $trail = app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser);

    expect($trail[0]['event'])->toBe('created')
        ->and($trail[0]['derived'])->toBeTrue()
        ->and($trail[0]['actor'])->toBe($this->merchandiser->name);

    // Nothing was written to make that entry exist.
    expect(DB::table('audit_logs')->count())->toBeLessThan($before);
});

it('sends the quotation screen a cost sheet with real values, not a wall of zeros', function (): void {
    // F-01. The payload is asserted on *values* rather than on arithmetic: a sheet of zeros
    // satisfies `qty × rate = amount` perfectly, which is exactly how the defect survived a
    // check written to catch a mis-stated rate. The database was never wrong — the shaping was.
    $quotation = trailQuotation($this);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $line = collect($page->toArray()['props']['lines'])
                ->first(fn (array $l): bool => ($l['cost_lines'] ?? []) !== []);

            expect($line)->not->toBeNull('the quotation line has no cost sheet to render');

            $rows = $line['cost_lines'];

            // Every row identifies itself...
            foreach ($rows as $row) {
                expect($row['cost_type'])->not->toBe('', 'a cost row rendered with no cost type')
                    ->and($row['formula_ref'])->not->toBeEmpty();
            }

            // ...and the sheet as a whole carries real quantities and real money.
            expect(collect($rows)->contains(fn (array $r): bool => (float) $r['qty'] > 0))->toBeTrue()
                ->and(collect($rows)->contains(fn (array $r): bool => (float) $r['amount'] > 0))->toBeTrue()
                ->and((float) $line['cost_sheet']['total_cost'])->toBeGreaterThan(0.0)
                ->and((float) $line['cost_sheet']['unit_cost'])->toBeGreaterThan(0.0);

            // And the rows that claim to be rate rows genuinely multiply out.
            foreach ($rows as $row) {
                if ($row['basis'] !== 'rate' || (float) $row['amount'] === 0.0) {
                    continue;
                }

                expect(abs((float) $row['qty'] * (float) $row['rate'] - (float) $row['amount']))
                    ->toBeLessThan(max(0.05, abs((float) $row['amount']) * 0.001),
                        "{$row['cost_type']} does not reconcile");
            }
        });
});

it('keeps the quotation list total equal to the detail total', function (): void {
    $quotation = trailQuotation($this);

    $detail = $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")->viewData('page')['props']['quotation']['total'];

    $row = collect($this->actingAs($this->merchandiser)
        ->get('/quotations?sort=-id')->viewData('page')['props']['quotations']['data'])
        ->firstWhere('id', $quotation->id);

    expect(abs((float) $row['total'] - (float) $detail))->toBeLessThan(0.01);
});

it('reads print events recorded against the table name as well as the model', function (): void {
    // The print path records `recordTable('quotations', …)`, not the model class. Those rows
    // are real history and were invisible to a morph query.
    $quotation = trailQuotation($this);

    app(App\Support\Audit\AuditLogger::class)
        ->recordTable('quotations', (int) $quotation->id, 'printed');

    $events = array_column(app(DocumentTrail::class)->for($quotation->refresh(), $this->merchandiser), 'event');

    expect($events)->toContain('printed');
});
