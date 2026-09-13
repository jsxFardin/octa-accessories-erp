<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\States\SalesReturnStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SR — the customer return of delivered, invoiced goods.
 *
 * The invariant this suite exists to defend, asserted after every operation: **a paid invoice
 * that has been returned against is still, in every respect, paid**. Its status, its
 * `received_amount`, its receipt allocations and its outstanding balance are exactly what they
 * were before the goods came back. The return records the goods; the credit that answers them
 * is a separate document, which Phase 3 raises.
 *
 * Phase 1 is the document and its quantity arithmetic. No stock moves yet.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatch = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
});

/** An issued invoice for one line of $qty at $rate per 1000. */
function invoiceFor(object $test, float $qty, float $rate): SalesInvoice
{
    $total = round($qty / 1000 * $rate, 4);

    $test->actingAs($test->accounts);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
        'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1,
        'product_id' => $test->productId,
        'description' => 'return fixture', 'qty' => $qty, 'rate_per_m' => $rate,
        'tax_amount' => 0, 'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

/** Settle an invoice in full, the way money actually arrives. */
function payInFull(object $test, SalesInvoice $invoice): SalesInvoice
{
    $test->actingAs($test->accounts);

    $test->post('/receipts', [
        'customer_id' => $test->customerId,
        'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer',
        'currency_id' => (int) $invoice->currency_id,
        'amount' => (float) $invoice->total,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => (float) $invoice->total]],
    ])->assertSessionHas('success');

    return $invoice->refresh();
}

/**
 * A finished-goods lot for the fixture product, standing in for the one the goods shipped on.
 * These scenarios test the quantity arithmetic, so the lot only has to be real and be the
 * right product; the dispatch-provenance rules get their own test against a real shipment.
 */
function fgLot(object $test, float $balance = 0, float $unitCost = 1.5): int
{
    return (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-SR-'.Str::random(10),
        'product_id' => $test->productId,
        'kind' => 'finished_goods',
        'warehouse_id' => $test->warehouseId,
        'uom_id' => (int) DB::table('uoms')->where('code', 'pcs')->value('id'),
        'received_qty' => $balance,
        'balance_qty' => $balance,
        'unit_cost' => $unitCost,
        'received_on' => now()->toDateString(),
        'status' => 'available',
        'created_at' => now(),
    ]);
}

/** A draft return against $invoice's only line. */
function draftReturn(object $test, SalesInvoice $invoice, float $qty, ?int $invoiceLineId = null, ?int $lotId = null): SalesReturn
{
    $line = $invoiceLineId !== null
        ? SalesInvoiceLine::query()->findOrFail($invoiceLineId)
        : SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->firstOrFail();

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id,
        'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => $test->warehouseId,
        'returned_on' => now()->toDateString(),
        'reason' => 'Customer returned short-shipped cartons',
        'status' => SalesReturn::DRAFT,
        'created_by' => $test->dispatch->id,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id,
        'line_no' => 1,
        'sales_invoice_line_id' => $line->id,
        'product_id' => $line->product_id,
        'lot_id' => $lotId ?? fgLot($test),
        'qty' => $qty,
        'rate_per_m' => $line->rate_per_m,
    ]);

    return $return->refresh();
}

/** Approve then post, with the right hand on each step. */
function postReturn(object $test, SalesReturn $return): SalesReturn
{
    $states = app(SalesReturnStateMachine::class);

    $test->actingAs($test->accounts);
    $states->transition($return, SalesReturn::APPROVED);

    $test->actingAs($test->dispatch);
    $states->transition($return->refresh(), SalesReturn::POSTED);

    return $return->refresh();
}

/**
 * The invariant, in one place. Everything about the invoice's money is exactly as it was.
 *
 * @param  array<int, float>  $allocationsBefore
 */
function invoiceUntouched(SalesInvoice $invoice, string $status, float $received, array $allocationsBefore): void
{
    $machine = app(SalesInvoiceStateMachine::class);
    $invoice->refresh();

    $allocationsNow = DB::table('receipt_allocations')
        ->where('sales_invoice_id', $invoice->id)
        ->orderBy('id')
        ->pluck('amount', 'id')
        ->map(fn ($amount): float => (float) $amount)
        ->all();

    expect($invoice->status)->toBe($status)
        ->and((float) $invoice->received_amount)->toBeMoney($received)
        ->and($allocationsNow)->toEqual($allocationsBefore)
        ->and((float) $invoice->total)
        ->toBeMoney((float) $invoice->received_amount + $machine->appliedCredits($invoice) + $machine->outstanding($invoice));
}

it('accepts a return against a fully paid invoice and leaves the invoice untouched', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));

    expect($invoice->status)->toBe('paid');

    $received = (float) $invoice->received_amount;
    $allocations = DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)
        ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all();

    $return = postReturn($this, draftReturn($this, $invoice, 2500));

    expect($return->status)->toBe(SalesReturn::POSTED)
        ->and($return->number)->toStartWith('SR-');

    // The whole point of the feature: none of this moved.
    invoiceUntouched($invoice, 'paid', $received, $allocations);
    expect(app(SalesInvoiceStateMachine::class)->outstanding($invoice->refresh()))->toBeMoney(0.0);
});

it('consumes the returnable balance line by line', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $lineId = (int) SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->value('id');

    postReturn($this, draftReturn($this, $invoice, 2500));

    expect((float) DB::table('sales_invoice_lines')->where('id', $lineId)->value('returned_qty'))
        ->toBeQty(2500.0);

    // A second partial return is legitimate and nets against the first.
    postReturn($this, draftReturn($this, $invoice, 3000));

    expect((float) DB::table('sales_invoice_lines')->where('id', $lineId)->value('returned_qty'))
        ->toBeQty(5500.0);
});

it('refuses a third return that would exceed what is left to return', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));

    postReturn($this, draftReturn($this, $invoice, 6000));
    postReturn($this, draftReturn($this, $invoice, 3000));

    // 1,000 left; asking for 1,500.
    $third = draftReturn($this, $invoice, 1500);

    $this->actingAs($this->accounts);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($third, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'only 1000 can come back');

    expect($third->refresh()->status)->toBe(SalesReturn::DRAFT);
});

it('refuses a return larger than the invoiced quantity', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $return = draftReturn($this, $invoice, 10001);

    $this->actingAs($this->accounts);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'SR-3');
});

it('refuses a return against a draft invoice, which has billed nothing', function (): void {
    $this->actingAs($this->accounts);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $this->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $this->currencyId, 'exchange_rate' => 1,
        'subtotal' => 100, 'tax_amount' => 0, 'total' => 100, 'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1, 'product_id' => $this->productId,
        'description' => 'draft', 'qty' => 1000, 'rate_per_m' => 100, 'tax_amount' => 0, 'amount' => 100,
    ]);

    $return = draftReturn($this, $invoice, 100);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'billed nothing');
});

it('refuses a return against a cancelled invoice', function (): void {
    $invoice = invoiceFor($this, 10000, 375);
    $return = draftReturn($this, $invoice, 1000);

    $this->actingAs($this->accounts);
    app(SalesInvoiceStateMachine::class)->transition($invoice, 'cancelled');

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'cannot be returned against it');
});

it('refuses a line that belongs to another invoice', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $other = invoiceFor($this, 5000, 200);

    // A return against `$invoice`, but pointing at `$other`'s line.
    $return = draftReturn($this, $invoice, 500,
        (int) SalesInvoiceLine::query()->where('sales_invoice_id', $other->id)->value('id'));

    $this->actingAs($this->accounts);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'SR-4');
});

it('refuses a return whose customer is not the invoice customer', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));

    // The walkthrough seeds one customer, so the second one is made here rather than assumed.
    $otherCustomer = (int) DB::table('customers')->insertGetId([
        'code' => 'CUST-SR-OTHER',
        'name' => 'Another Apparel Ltd',
        'kind' => (string) DB::table('customers')->where('id', $this->customerId)->value('kind'),
        'currency_id' => $this->currencyId,
        'created_at' => now(),
    ]);

    $return = draftReturn($this, $invoice, 500);
    $return->forceFill(['customer_id' => $otherCustomer])->save();

    $this->actingAs($this->accounts);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return->refresh(), SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'different customer');
});

it('refuses to approve a return with no lines', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $return = draftReturn($this, $invoice, 500);
    $return->lines()->delete();

    $this->actingAs($this->accounts);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return->refresh(), SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'no lines');
});

it('makes a posted return immutable — it cannot be cancelled', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $return = postReturn($this, draftReturn($this, $invoice, 1000));

    $this->actingAs($this->dispatch);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::CANCELLED))
        ->toThrow(TransitionDenied::class, 'cannot move from [posted]');
});

it('lets a draft or approved return be cancelled, and frees the quantity again', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $lineId = (int) SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->value('id');

    $return = draftReturn($this, $invoice, 9000);
    $this->actingAs($this->accounts);
    app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED);
    app(SalesReturnStateMachine::class)->transition($return->refresh(), SalesReturn::CANCELLED);

    expect($return->refresh()->status)->toBe(SalesReturn::CANCELLED)
        ->and((float) DB::table('sales_invoice_lines')->where('id', $lineId)->value('returned_qty'))->toBeQty(0.0);

    // Cancelled quantities were never consumed, so the full amount is still returnable.
    postReturn($this, draftReturn($this, $invoice, 10000));

    expect((float) DB::table('sales_invoice_lines')->where('id', $lineId)->value('returned_qty'))->toBeQty(10000.0);
});

it('will not let two approved returns race past the same remaining quantity', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $lineId = (int) SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->value('id');

    // Both approved while the full 10,000 is still available — the over-return only becomes
    // visible when the second one tries to post.
    $first = draftReturn($this, $invoice, 7000);
    $second = draftReturn($this, $invoice, 7000);

    $states = app(SalesReturnStateMachine::class);

    $this->actingAs($this->accounts);
    $states->transition($first, SalesReturn::APPROVED);
    $states->transition($second, SalesReturn::APPROVED);

    $this->actingAs($this->dispatch);
    $states->transition($first->refresh(), SalesReturn::POSTED);

    expect(fn () => $states->transition($second->refresh(), SalesReturn::POSTED))
        ->toThrow(TransitionDenied::class, 'only 3000 can come back');

    expect($second->refresh()->status)->toBe(SalesReturn::APPROVED)
        ->and((float) DB::table('sales_invoice_lines')->where('id', $lineId)->value('returned_qty'))->toBeQty(7000.0);
});

it('refuses the transition to someone without the permission', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $return = draftReturn($this, $invoice, 1000);

    // The driver holds no sales_return rights at all.
    $this->actingAs(User::query()->where('email', 'driver@octapussolution.com')->firstOrFail());

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'sales_return.approve');
});

it('audits every transition with its reason', function (): void {
    $invoice = payInFull($this, invoiceFor($this, 10000, 375));
    $return = postReturn($this, draftReturn($this, $invoice, 1000));

    $trail = DB::table('audit_logs')
        ->where('auditable_type', SalesReturn::class)
        ->where('auditable_id', $return->id)
        ->where('event', 'status_changed')
        ->orderBy('id')
        ->get(['old_values', 'new_values']);

    expect($trail)->toHaveCount(2)
        ->and($trail[0]->new_values)->toContain('approved')
        ->and($trail[1]->new_values)->toContain('posted');
});
