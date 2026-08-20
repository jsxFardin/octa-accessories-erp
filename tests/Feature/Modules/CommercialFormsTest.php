<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * The commercial spine, exercised through the routes the screens actually post to.
 *
 * These pages were placeholders until now; a page that renders is not a page that works, so
 * every one of these submits a real payload and checks what landed in the database.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
});

/**
 * Each test runs inside a transaction that rolls back, so nothing may lean on a record an
 * earlier test left behind — every fixture is built where it is used.
 */
function makeQuotation(object $test, string $status = 'draft'): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'customer_id' => $test->customer->id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '50,000 woven care labels',
            'qty' => 50000,
            'rate_per_m' => 3.25,
            'margin_pct' => 22,
        ]],
    ]);

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    if ($status !== 'draft') {
        $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    }

    if ($status === 'accepted') {
        $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    }

    return $quotation->fresh();
}

function makeInquiry(object $test): Inquiry
{
    $test->actingAs($test->merchandiser)->post('/inquiries', [
        'customer_id' => $test->customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [['description' => 'Care label', 'qty' => 1000]],
    ]);

    return Inquiry::query()->latest('id')->firstOrFail();
}

// --- Inquiry -----------------------------------------------------------------------------

it('creates an inquiry with its lines and leaves it unnumbered', function (): void {
    $this->actingAs($this->merchandiser)
        ->post('/inquiries', [
            'customer_id' => $this->customer->id,
            'inquiry_date' => now()->toDateString(),
            'required_by' => now()->addWeeks(4)->toDateString(),
            'source' => 'email',
            'notes' => 'Repeat of last season with a new care symbol.',
            'lines' => [
                ['description' => 'Centre-fold satin care label', 'product_type' => 'woven', 'qty' => 50000, 'target_rate_per_m' => 3.2],
                ['description' => 'Hang tag 50 × 90', 'product_type' => 'offset_tag', 'qty' => 20000],
            ],
        ])
        ->assertRedirect();

    $inquiry = Inquiry::query()->latest('id')->firstOrFail();

    expect($inquiry->lines)->toHaveCount(2)
        ->and($inquiry->status)->toBe('draft')
        // BR-34 — a draft shows "(unnumbered)"; the number comes on submit.
        ->and($inquiry->number)->toBeNull()
        ->and((float) $inquiry->lines->first()->qty)->toBe(50000.0);
});

it('rejects an inquiry with no lines', function (): void {
    $this->actingAs($this->merchandiser)
        ->post('/inquiries', [
            'customer_id' => $this->customer->id,
            'inquiry_date' => now()->toDateString(),
            'lines' => [],
        ])
        ->assertSessionHasErrors('lines');
});

it('numbers an inquiry when it is submitted', function (): void {
    $inquiry = Inquiry::query()->create([
        'customer_id' => $this->customer->id,
        'inquiry_date' => now()->toDateString(),
        'status' => 'draft',
        'created_by' => $this->merchandiser->id,
    ]);

    $inquiry->lines()->create(['line_no' => 1, 'description' => 'Test', 'qty' => 1000]);

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => 'open'])
        ->assertRedirect();

    expect($inquiry->fresh()->number)->toStartWith('INQ-');
});

it('will not submit an inquiry that has no lines', function (): void {
    $inquiry = Inquiry::query()->create([
        'customer_id' => $this->customer->id,
        'inquiry_date' => now()->toDateString(),
        'status' => 'draft',
    ]);

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => 'open'])
        ->assertSessionHas('error');

    expect($inquiry->fresh()->status)->toBe('draft');
});

it('demands a reason before an inquiry is marked lost', function (): void {
    $inquiry = makeInquiry($this);

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => 'lost'])
        ->assertSessionHasErrors('lost_reason');
});

it('edits a draft inquiry through the same form it was created on', function (): void {
    $inquiry = makeInquiry($this);

    $this->actingAs($this->merchandiser)->get("/inquiries/{$inquiry->id}/edit")->assertOk();

    $this->actingAs($this->merchandiser)->put("/inquiries/{$inquiry->id}", [
        'customer_id' => $this->customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [
            ['description' => 'Care label, revised', 'qty' => 2500],
            ['description' => 'Size tab', 'qty' => 500],
        ],
    ])->assertRedirect();

    $lines = DB::table('inquiry_lines')->where('inquiry_id', $inquiry->id)->orderBy('line_no')->get();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->description)->toBe('Care label, revised')
        ->and((float) $lines[0]->qty)->toBe(2500.0);
});

it('refuses to edit an inquiry once it has been quoted', function (): void {
    $inquiry = makeInquiry($this);

    // Quoting is what freezes the lines: the quotation was costed from them.
    $inquiry->forceFill(['status' => 'quoted'])->save();

    $this->actingAs($this->merchandiser)->get("/inquiries/{$inquiry->id}/edit")->assertForbidden();

    $this->actingAs($this->merchandiser)->put("/inquiries/{$inquiry->id}", [
        'customer_id' => $this->customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [['description' => 'Sneaked in', 'qty' => 1]],
    ])->assertSessionHas('error');

    expect(DB::table('inquiry_lines')->where('inquiry_id', $inquiry->id)->where('description', 'Sneaked in')->exists())
        ->toBeFalse();
});

// --- Quotation ---------------------------------------------------------------------------

it('creates a quotation and builds a cost sheet for every line', function (): void {
    $this->actingAs($this->merchandiser)
        ->post('/quotations', [
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => '50,000 woven care labels',
                'qty' => 50000,
                'rate_per_m' => 3.25,
                'margin_pct' => 22,
            ]],
        ])
        ->assertRedirect();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $line = $quotation->lines->first();

    expect($quotation->lines)->toHaveCount(1)
        ->and($quotation->status)->toBe('draft');

    // Q1 — a line without a cost sheet cannot be sent, so one is built on save.
    $sheet = DB::table('cost_sheets')->where('quotation_line_id', $line->id)->first();

    expect($sheet)->not->toBeNull()
        ->and((float) $sheet->rate_per_m)->toBeGreaterThan(0)
        // Every sheet line names the rule that produced it (02-database-schema §3.4).
        ->and(DB::table('cost_sheet_lines')->where('cost_sheet_id', $sheet->id)->whereNotNull('formula_ref')->count())
        ->toBeGreaterThan(0);
});

it('prices a line live from the calculator endpoint', function (): void {
    $response = $this->actingAs($this->merchandiser)
        ->postJson('/cost-sheets/calculate', [
            'product_id' => $this->product->id,
            'qty' => 50000,
            'margin_pct' => 25,
        ]);

    $response->assertOk();

    $sheet = $response->json('sheet');

    // BR-20 — the realised margin is the quoted share of the *price*.
    expect($sheet['rate_per_m'])->toBeGreaterThan(0)
        ->and(round($sheet['margin_amount'] / $sheet['selling_value'] * 100, 2))->toBe(25.0);
});

it('refuses to send a quotation whose line has no rate', function (): void {
    $quotation = makeQuotation($this);
    $quotation->lines()->update(['rate_per_m' => 0]);

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])
        ->assertSessionHas('error');

    expect($quotation->fresh()->status)->toBe('draft');
});

it('sends a quotation, numbers it and locks its cost sheets', function (): void {
    $quotation = makeQuotation($this);

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])
        ->assertRedirect();

    $quotation->refresh();

    expect($quotation->status)->toBe('sent')
        ->and($quotation->number)->toStartWith('QTN-')
        // Q1 — the snapshot goes read-only on send.
        ->and(DB::table('cost_sheets')->whereIn('quotation_line_id', $quotation->lines->pluck('id'))->where('is_locked', false)->count())
        ->toBe(0);
});

it('will not edit a sent quotation', function (): void {
    $quotation = makeQuotation($this, 'sent');

    $this->actingAs($this->merchandiser)->get("/quotations/{$quotation->id}/edit")->assertForbidden();
});

it('converts an accepted quotation into a draft order', function (): void {
    $quotation = makeQuotation($this, 'accepted');

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-TEST-1'])
        ->assertRedirect();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect($order->status)->toBe('draft')
        ->and($order->lines)->toHaveCount($quotation->lines->count())
        ->and($order->customer_po_no)->toBe('PO-TEST-1');
});

// --- Sales order -------------------------------------------------------------------------

it('creates a draft sales order with its lines and tolerance band', function (): void {
    $this->actingAs($this->merchandiser)
        ->post('/sales-orders', [
            'customer_id' => $this->customer->id,
            'customer_po_no' => 'PO-DIRECT-1',
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->addWeeks(3)->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'priority' => 'normal',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_spec_id' => $this->product->currentSpec->id,
                'ordered_qty' => 50000,
                'rate_per_m' => 3.25,
                'under_tolerance_pct' => 5,
                'over_tolerance_pct' => 5,
            ]],
        ])
        ->assertRedirect();

    $order = SalesOrder::query()->where('customer_po_no', 'PO-DIRECT-1')->firstOrFail();
    $line = $order->lines->first();

    // BR-1 — line value is quantity over 1000 times the per-M rate.
    expect((float) $line->line_total)->toBe(162.5)
        ->and((float) $order->total)->toBe(162.5)
        ->and($order->status)->toBe('draft')
        ->and($order->number)->toBeNull();
});

it('demands an amendment reason once an order is confirmed', function (): void {
    $order = SalesOrder::query()->where('status', 'confirmed')->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->put("/sales-orders/{$order->id}", [
            'customer_id' => $order->customer_id,
            'order_date' => $order->order_date->toDateString(),
            'delivery_date' => now()->addWeeks(6)->toDateString(),
            'currency_id' => $order->currency_id,
            'exchange_rate' => $order->exchange_rate,
            'priority' => 'high',
            'lines' => $order->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_spec_id' => $line->product_spec_id,
                'ordered_qty' => $line->ordered_qty,
                'rate_per_m' => $line->rate_per_m,
            ])->all(),
        ])
        ->assertSessionHas('error');
});

it('records an amendment row when a confirmed order changes', function (): void {
    $order = SalesOrder::query()->where('status', 'confirmed')->firstOrFail();
    $before = DB::table('so_amendments')->where('sales_order_id', $order->id)->count();

    $this->actingAs($this->merchandiser)
        ->put("/sales-orders/{$order->id}", [
            'customer_id' => $order->customer_id,
            'order_date' => $order->order_date->toDateString(),
            'delivery_date' => now()->addWeeks(6)->toDateString(),
            'currency_id' => $order->currency_id,
            'exchange_rate' => $order->exchange_rate,
            'priority' => 'high',
            'amendment_reason' => 'Customer moved the shipment to week 42.',
            'lines' => $order->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_spec_id' => $line->product_spec_id,
                'ordered_qty' => $line->ordered_qty,
                'rate_per_m' => $line->rate_per_m,
            ])->all(),
        ])
        ->assertRedirect();

    // S2 — no silent edits: the change, its reason and its author are a row.
    $amendments = DB::table('so_amendments')->where('sales_order_id', $order->id)->get();

    expect($amendments)->toHaveCount($before + 2)
        ->and($amendments->last()->reason)->toBe('Customer moved the shipment to week 42.')
        ->and($order->fresh()->revision_no)->toBe(1);
});

// --- Duplication -------------------------------------------------------------------------

it('copies a quotation into a fresh draft', function (): void {
    $original = makeQuotation($this, 'sent');

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$original->id}/duplicate")
        ->assertRedirect();

    $copy = Quotation::query()->latest('id')->firstOrFail();

    expect($copy->id)->not->toBe($original->id)
        ->and($copy->status)->toBe('draft')
        // BR-34: a draft carries no number until it is sent.
        ->and($copy->number)->toBeNull()
        ->and($copy->customer_id)->toBe($original->customer_id)
        ->and($copy->lines()->count())->toBe($original->lines()->count());
});

it('re-costs a duplicated quotation at today\'s rates rather than copying the old price', function (): void {
    $original = makeQuotation($this);

    // A cost sheet per line is what makes the copy honest — an input that moved since the
    // original was quoted has to show up as a new number (Q1).
    $this->actingAs($this->merchandiser)->post("/quotations/{$original->id}/duplicate");

    $copy = Quotation::query()->latest('id')->firstOrFail();
    $sheets = DB::table('cost_sheets')->whereIn('quotation_line_id', $copy->lines()->pluck('id'))->count();

    expect($sheets)->toBe($copy->lines()->count());
});

it('will not duplicate for someone who may not raise a quotation', function (): void {
    $quotation = makeQuotation($this);
    $operator = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    $this->actingAs($operator)->post("/quotations/{$quotation->id}/duplicate")->assertForbidden();
});

it('serialises date-only fields as calendar days, not UTC timestamps', function (): void {
    $this->actingAs($this->merchandiser)->post('/sales-orders', [
        'customer_id' => $this->customer->id,
        'customer_po_no' => 'PO-DATE-1',
        'order_date' => '2026-08-20',
        'delivery_date' => '2026-08-31',
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1,
        'priority' => 'normal',
        'lines' => [[
            'product_id' => $this->product->id,
            'product_spec_id' => $this->product->currentSpec->id,
            'ordered_qty' => 1000,
            'rate_per_m' => 3.25,
            'under_tolerance_pct' => 5,
            'over_tolerance_pct' => 5,
        ]],
    ])->assertRedirect();

    $order = SalesOrder::query()->where('customer_po_no', 'PO-DATE-1')->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->get("/sales-orders/{$order->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.order_date', '2026-08-20')
            ->where('order.delivery_date', '2026-08-31'));
});

it('names the field and the line in a validation message', function (): void {
    // "The lines.0.product_id field is required" names a row the user cannot see (line 1 is
    // index 0) and a column nobody typed. Every message in the application goes through
    // DocumentValidator, so this holds the wording for all of them.
    $response = $this->actingAs($this->merchandiser)->post('/inquiries', [
        'inquiry_date' => now()->toDateString(),
        'lines' => [
            ['description' => '', 'qty' => ''],
            ['description' => 'Second line', 'qty' => 0],
        ],
    ]);

    $response->assertRedirect()->assertSessionHasErrors();

    $messages = session('errors')->getBag('default')->getMessages();

    expect($messages['customer_id'][0])->toBe('The customer field is required.')
        ->and($messages['lines.0.description'][0])->toBe('The line 1 description field is required.')
        ->and($messages['lines.0.qty'][0])->toBe('The line 1 quantity field is required.')
        ->and($messages['lines.1.qty'][0])->toContain('line 2 quantity');
});
