<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * BR-46 at the amendment boundary.
 *
 * The credit decision was taken only on `draft → confirmed`. Everything that determines credit
 * exposure — the customer, the lines, their quantities and rates, the currency and its rate —
 * stayed amendable afterwards under S2, and `update()` ran no credit logic at all. So the
 * control was avoidable without breaking any of its own rules: confirm an order small, amend it
 * large, and it sits in `confirmed` at a value that never passed the gate.
 *
 * Demonstrated: a USD 100 order (BDT 12,250) confirmed inside a BDT 500,000 limit, amended to
 * USD 100,000 — BDT 12,250,000, twenty-four times the limit — stayed confirmed and executable,
 * with production and dispatch both reachable from that status.
 *
 * The same path also reassigned `customer_id` on a confirmed, invoiced order, which is the
 * second shape of the bypass: confirm against a customer with room, then move the order onto
 * one without.
 *
 * The guard sits on the update path, beside the one BR-53/S1 put there. What it does on a
 * breach depends on how far the order has gone: a `confirmed` order is moved to `credit_hold`,
 * which is BR-46's own mechanism, while an order already `in_production` or part delivered has
 * the amendment refused instead — holding it would be a lie about where the goods are.
 */
beforeEach(function (): void {
    $this->sales = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->states = app(SalesOrderStateMachine::class);

    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->usdRate = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->usd->id)->orderByDesc('effective_on')->value('rate_to_base');

    $template = SalesOrder::query()->firstOrFail();
    $this->customerId = (int) $template->customer_id;

    $this->spec = DB::table('product_specs')->where('status', 'current')->orderBy('id')->first(['id', 'product_id'])
        ?? DB::table('product_specs')->orderBy('id')->first(['id', 'product_id']);

    DB::table('sales_invoices')->where('customer_id', $this->customerId)->delete();

    $this->setLimit = fn (float $limit) => DB::table('customers')
        ->where('id', $this->customerId)->update(['credit_limit' => $limit]);

    /** A confirmed order at a given value, carrying one line. */
    $this->confirmedOrder = function (float $total, ?object $currency = null, ?float $rate = null, ?int $customerId = null): SalesOrder {
        $order = SalesOrder::query()->create([
            'customer_id' => $customerId ?? $this->customerId,
            'order_date' => now()->toDateString(),
            'currency_id' => ($currency ?? $this->usd)->id,
            'exchange_rate' => $rate ?? $this->usdRate,
            'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
            'priority' => 'normal', 'status' => 'confirmed', 'created_by' => $this->sales->id,
        ]);

        DB::table('sales_order_lines')->insert([
            'sales_order_id' => $order->id, 'line_no' => 1,
            'product_id' => $this->spec->product_id, 'product_spec_id' => $this->spec->id,
            'description' => 'BR-46 amendment line', 'ordered_qty' => 1000,
            'rate_per_m' => $total, 'line_total' => $total, 'status' => 'open',
        ]);

        return $order->refresh();
    };

    /** The amendment payload the edit screen posts. */
    $this->amend = function (SalesOrder $order, array $overrides = []) {
        $line = DB::table('sales_order_lines')->where('sales_order_id', $order->id)->first();

        return $this->actingAs($overrides['as'] ?? $this->sales)->put("/sales-orders/{$order->id}", array_merge([
            'customer_id' => $order->customer_id,
            'order_date' => now()->toDateString(),
            'currency_id' => $order->currency_id,
            'exchange_rate' => $order->exchange_rate,
            'priority' => 'normal',
            'amendment_reason' => 'QA amendment',
            'lines' => [[
                'id' => $line->id,
                'product_id' => $this->spec->product_id,
                'product_spec_id' => $this->spec->id,
                'description' => 'BR-46 amendment line',
                'ordered_qty' => 1000,
                'rate_per_m' => $overrides['rate_per_m'] ?? $line->rate_per_m,
            ]],
        ], collect($overrides)->except(['as', 'rate_per_m'])->all()));
    };
});

it('br46: holds a confirmed order whose amendment takes it past the credit limit', function (): void {
    ($this->setLimit)(500_000.0);
    $order = ($this->confirmedOrder)(100.0);            // USD 100 = BDT 12,250, comfortably inside

    expect($this->states->creditCheck($order)['on_hold'])->toBeFalse();

    // USD 100,000 = BDT 12,250,000 — twenty-four times the limit. The customer may genuinely
    // have increased the order, so the amendment stands; what must not stand is the order
    // continuing to be executable on it.
    ($this->amend)($order, ['rate_per_m' => 100_000])->assertSessionHasNoErrors();

    $fresh = $order->fresh();

    expect((float) $fresh->total)->toBe(100_000.0)
        ->and($fresh->status)->toBe('credit_hold')
        ->and($this->states->creditCheck($fresh)['on_hold'])->toBeTrue();
});

it('br46: allows an amendment that stays within the credit limit', function (): void {
    ($this->setLimit)(500_000.0);
    $order = ($this->confirmedOrder)(100.0);

    // USD 1,000 = BDT 122,500 — still inside. The rule must not refuse what is legitimate.
    ($this->amend)($order, ['rate_per_m' => 1_000])->assertSessionHasNoErrors();

    expect((float) $order->fresh()->total)->toBe(1_000.0)
        ->and($order->fresh()->status)->toBe('confirmed');
});

it('br46: refuses the amendment outright once the order is already being executed', function (): void {
    // `credit_hold` means "do not start this yet". An order already in production or part
    // delivered cannot be un-started, so holding it would be a lie about where the goods are;
    // the amendment itself is what has to give.
    ($this->setLimit)(500_000.0);
    $order = ($this->confirmedOrder)(100.0);
    $order->forceFill(['status' => 'in_production'])->save();

    ($this->amend)($order, ['rate_per_m' => 100_000])->assertSessionHasErrors();

    expect((float) $order->fresh()->total)->toBe(100.0)
        ->and($order->fresh()->status)->toBe('in_production');
});

it('br46: refuses moving an order onto a customer whose credit it would breach', function (): void {
    // A customer of this test's own making, so the case does not depend on the seed's shape.
    $template = DB::table('customers')->where('id', $this->customerId)->first();
    $other = DB::table('customers')->insertGetId([
        'code' => 'CUST-BR46-QA',
        'name' => 'BR-46 amendment target',
        'kind' => $template->kind,
        'currency_id' => $template->currency_id,
        'payment_term_id' => $template->payment_term_id,
        'credit_limit' => 1_000.0,
        'is_active' => true,
    ]);

    ($this->setLimit)(50_000_000.0);

    // Comfortable for its own customer; far past the one it is being moved to.
    $order = ($this->confirmedOrder)(10_000.0);

    ($this->amend)($order, ['customer_id' => $other])->assertSessionHasNoErrors();

    // BR-46 is evaluated against the customer the order now belongs to.
    expect((int) $order->fresh()->customer_id)->toBe((int) $other)
        ->and($order->fresh()->status)->toBe('credit_hold');
});

it('br46: measures an amendment against mixed currencies at each document own rate', function (): void {
    ($this->setLimit)(500_000.0);

    $mk = fn (int $ccy, float $rate, float $total, float $recv, string $no) => SalesInvoice::query()->create([
        'number' => $no, 'customer_id' => $this->customerId,
        'sales_order_id' => SalesOrder::query()->firstOrFail()->id,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $ccy, 'exchange_rate' => $rate, 'subtotal' => $total, 'tax_amount' => 0,
        'total' => $total, 'received_amount' => $recv, 'status' => 'issued',
    ]);

    $mk($this->base->id, 1.0, 100_000, 0, 'INV-AMD-BDT');
    $mk($this->usd->id, $this->usdRate, 1_000, 0, 'INV-AMD-USD');
    $mk($this->usd->id, 110.0, 1_000, 400, 'INV-AMD-HIST');   // historical rate, partly paid

    $order = ($this->confirmedOrder)(100.0);

    // 100,000 + 122,500 + 66,000 = 288,500 before the order itself.
    $expectedInvoices = 100_000 + (1_000 * $this->usdRate) + (600 * 110.0);
    expect($this->states->creditCheck($order)['exposure'])
        ->toBe(round($expectedInvoices + 100 * $this->usdRate, 4));

    // Amending to USD 2,000 (BDT 245,000) takes the total past 500,000, so the order is held.
    ($this->amend)($order, ['rate_per_m' => 2_000])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('credit_hold');
});

it('br46: refuses a hostile exchange rate on an amendment', function (): void {
    ($this->setLimit)(50_000_000.0);
    $order = ($this->confirmedOrder)(100.0);

    foreach ([1, 999999, 0.0001, -122.5] as $rate) {
        ($this->amend)($order, ['exchange_rate' => $rate])->assertSessionHasErrors('exchange_rate');
    }

    expect((float) $order->fresh()->exchange_rate)->toBe($this->usdRate);
});

it('br46: ignores injected status and approval fields on an amendment', function (): void {
    ($this->setLimit)(50_000_000.0);
    $order = ($this->confirmedOrder)(100.0);

    ($this->amend)($order, ['status' => 'delivered', 'approved_by' => 2, 'total' => 999_999_999]);

    expect($order->fresh()->status)->toBe('confirmed')
        ->and((float) $order->fresh()->total)->toBe(100.0);
});

it('br46: does not let an amendment quietly rescue an order already on credit hold', function (): void {
    ($this->setLimit)(500_000.0);
    $order = ($this->confirmedOrder)(100.0);
    $order->forceFill(['status' => 'credit_hold'])->save();

    ($this->amend)($order, ['rate_per_m' => 100_000])->assertSessionHasNoErrors();

    expect($order->fresh()->status)->toBe('credit_hold');
});
