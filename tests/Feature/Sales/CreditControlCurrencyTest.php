<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * BR-46/BR-51 — the credit decision is made entirely in the factory's own currency.
 *
 * `customers.credit_limit` is a base-currency figure, like `min_order_value` beside it on the
 * same row (BR-21 compares that against the cost-sheet subtotal, which BR-22 computes in the
 * base currency). The customer's `currency_id` says what they are *traded* in, not what their
 * limits are stated in.
 *
 * The defect this covers: neither of the other two operands was converted.
 *
 * - open exposure was `SUM(total - received_amount)` across every currency at face value — the
 *   BR-50 defect, in a financial control rather than a report; and
 * - the order being confirmed was compared in whatever currency it was raised in, so a
 *   USD 10,000 order was measured against a taka limit as the number `10000`.
 *
 * Both understate exposure, so the control failed silently and always in the direction of
 * letting the order through.
 */
beforeEach(function (): void {
    $this->sales = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
    $this->md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();
    $this->states = app(SalesOrderStateMachine::class);

    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->eur = DB::table('currencies')->where('code', 'EUR')->firstOrFail();

    $this->usdRate = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->usd->id)->orderByDesc('effective_on')->value('rate_to_base');
    $this->eurRate = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->eur->id)->orderByDesc('effective_on')->value('rate_to_base');

    $template = SalesOrder::query()->firstOrFail();
    $this->customerId = (int) $template->customer_id;

    // A product with a current spec — an order line needs both (S3).
    $this->specLine = DB::table('product_specs')
        ->where('status', 'current')
        ->orderBy('id')
        ->first(['id', 'product_id'])
        ?? DB::table('product_specs')->orderBy('id')->first(['id', 'product_id']);

    // A customer with no history of its own, so each case controls its whole exposure.
    DB::table('sales_invoices')->where('customer_id', $this->customerId)->delete();

    $this->setLimit = function (float $limit): void {
        DB::table('customers')->where('id', $this->customerId)->update(['credit_limit' => $limit]);
    };

    /** An open invoice in a given currency, at that currency's published rate. */
    $this->invoice = function (object $currency, float $rate, float $total) use ($template): void {
        SalesInvoice::query()->create([
            'number' => 'INV-QA-BR46-'.substr(uniqid(), -8),
            'customer_id' => $this->customerId,
            'sales_order_id' => $template->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_id' => $currency->id,
            'exchange_rate' => $rate,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'received_amount' => 0,
            'status' => 'issued',
        ]);
    };

    /** An order in a given currency, at that currency's published rate. */
    $this->order = function (object $currency, float $rate, float $total): SalesOrder {
        return SalesOrder::query()->create([
            'customer_id' => $this->customerId,
            'order_date' => now()->toDateString(),
            'currency_id' => $currency->id,
            'exchange_rate' => $rate,
            'subtotal' => $total,
            'tax_amount' => 0,
            'total' => $total,
            'priority' => 'normal',
            'status' => 'draft',
            'created_by' => $this->sales->id,
        ]);
    };
});

it('br46: adds up a single-currency exposure exactly as before', function (): void {
    // The base case must not move: BDT limit, BDT invoice, BDT order.
    ($this->setLimit)(100_000.0);
    ($this->invoice)($this->base, 1.0, 30_000.0);

    $order = ($this->order)($this->base, 1.0, 20_000.0);
    $check = $this->states->creditCheck($order);

    expect($check['exposure'])->toBe(50_000.0)
        ->and($check['credit_limit'])->toBe(100_000.0)
        ->and($check['on_hold'])->toBeFalse();
});

it('br46: converts a foreign open invoice before measuring exposure', function (): void {
    // USD 1,000 outstanding is BDT 122,500 — over a BDT 100,000 limit on its own.
    // At face value it was the number `1000`, and the order sailed through.
    ($this->setLimit)(100_000.0);
    ($this->invoice)($this->usd, $this->usdRate, 1_000.0);

    $order = ($this->order)($this->base, 1.0, 10_000.0);
    $check = $this->states->creditCheck($order);

    expect($check['exposure'])->toBe(round(1_000.0 * $this->usdRate + 10_000.0, 4))
        ->and($check['on_hold'])->toBeTrue()
        ->and($check['excess'])->toBeGreaterThan(0.0);
});

it('br46: converts a foreign order before measuring exposure', function (): void {
    // USD 5,000 is BDT 612,500. As the number `5000` it sat inside a BDT 500,000 limit.
    ($this->setLimit)(500_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 5_000.0);
    $check = $this->states->creditCheck($order);

    expect($check['exposure'])->toBe(round(5_000.0 * $this->usdRate, 4))
        ->and($check['on_hold'])->toBeTrue();
});

it('br46: normalises every currency in a mixed set of open invoices', function (): void {
    ($this->setLimit)(1_000_000.0);
    ($this->invoice)($this->base, 1.0, 50_000.0);
    ($this->invoice)($this->usd, $this->usdRate, 2_000.0);
    ($this->invoice)($this->eur, $this->eurRate, 1_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 1_000.0);

    $expected = round(
        50_000.0
        + 2_000.0 * $this->usdRate
        + 1_000.0 * $this->eurRate
        + 1_000.0 * $this->usdRate,
        4,
    );

    expect($this->states->creditCheck($order)['exposure'])->toBe($expected);
});

it('br46: uses the snapshotted rate, not a rate supplied with the request', function (): void {
    // BR-58 refuses a foreign order booked at parity, so the request cannot even store the
    // rate it would need for the credit check to under-read. The decision is taken from the
    // rate the document itself holds.
    ($this->setLimit)(500_000.0);

    $this->actingAs($this->sales)->post('/sales-orders', [
        'customer_id' => $this->customerId,
        'order_date' => now()->toDateString(),
        'currency_id' => $this->usd->id,
        'exchange_rate' => 1,
        'priority' => 'normal',
        'lines' => [[
            'product_id' => $this->specLine->product_id,
            'product_spec_id' => $this->specLine->id,
            'description' => 'QA BR-46 line',
            'ordered_qty' => 1000,
            'rate_per_m' => 5000,
        ]],
    ])->assertSessionHasErrors('exchange_rate');

    // And a document that *is* booked correctly is measured at its own rate, whatever a later
    // request might claim.
    $order = ($this->order)($this->usd, $this->usdRate, 5_000.0);

    expect($this->states->creditCheck($order)['exposure'])
        ->toBe(round(5_000.0 * $this->usdRate, 4))
        ->not->toBe(5_000.0);
});

it('br46: holds only above the limit, never at or below it', function (): void {
    ($this->setLimit)(100_000.0);

    // Below.
    $below = ($this->order)($this->base, 1.0, 99_999.0);
    expect($this->states->creditCheck($below)['on_hold'])->toBeFalse();

    // Exactly at the limit — BR-46 holds when exposure is *greater than* the limit.
    $at = ($this->order)($this->base, 1.0, 100_000.0);
    expect($this->states->creditCheck($at)['on_hold'])->toBeFalse()
        ->and($this->states->creditCheck($at)['excess'])->toBe(0.0);

    // Above.
    $above = ($this->order)($this->base, 1.0, 100_001.0);
    expect($this->states->creditCheck($above)['on_hold'])->toBeTrue()
        ->and($this->states->creditCheck($above)['excess'])->toBe(1.0);
});

it('br46: a zero limit still means no limit set', function (): void {
    ($this->setLimit)(0.0);
    ($this->invoice)($this->usd, $this->usdRate, 100_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 100_000.0);

    expect($this->states->creditCheck($order)['on_hold'])->toBeFalse();
});

it('br46: a foreign order over the limit is actually held at confirmation', function (): void {
    // The decision, not just the arithmetic: the order must land on credit_hold.
    ($this->setLimit)(500_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 5_000.0);

    DB::table('sales_order_lines')->insert([
        'sales_order_id' => $order->id,
        'line_no' => 1,
        'product_id' => $this->specLine->product_id,
        'product_spec_id' => $this->specLine->id,
        'description' => 'QA BR-46 confirmation line',
        'ordered_qty' => 1000,
        'rate_per_m' => 5000,
        'line_total' => 5_000.0,
        'status' => 'open',
    ]);

    // `sales_order.confirm` is a sales-manager permission; the MD releases holds, not sets them.
    $this->actingAs($this->sales)
        ->post("/sales-orders/{$order->id}/transition", ['to' => 'confirmed']);

    expect($order->fresh()->status)->toBe('credit_hold');
});

/*
 * BR-46 as a state-machine guard, not only a controller branch.
 *
 * The credit decision is taken in `SalesOrderController::transition()`, which diverts a
 * breaching order to `credit_hold`. That is the intended behaviour and it is unchanged. But the
 * rule lived only there, while every other rule on this document is enforced inside the state
 * machine — so any other caller reaching `transition($order, 'confirmed')` would have walked
 * straight past the credit control. This proves the guard now refuses it at the domain layer.
 */
it('br46: refuses a direct state-machine confirmation of an over-limit draft', function (): void {
    ($this->setLimit)(500_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 5_000.0);

    expect(fn () => $this->states->transition($order, 'confirmed'))
        ->toThrow(App\Support\States\TransitionDenied::class);

    expect($order->fresh()->status)->toBe('draft');
});

it('br46: leaves a within-limit draft confirmable at the domain layer', function (): void {
    ($this->setLimit)(10_000_000.0);

    $order = ($this->order)($this->usd, $this->usdRate, 5_000.0);

    DB::table('sales_order_lines')->insert([
        'sales_order_id' => $order->id,
        'line_no' => 1,
        'product_id' => $this->specLine->product_id,
        'product_spec_id' => $this->specLine->id,
        'description' => 'QA BR-46 within-limit line',
        'ordered_qty' => 1000,
        'rate_per_m' => 5000,
        'line_total' => 5_000.0,
        'status' => 'open',
    ]);

    $this->actingAs($this->sales)
        ->post("/sales-orders/{$order->id}/transition", ['to' => 'confirmed']);

    expect($order->fresh()->status)->toBe('confirmed');
});
