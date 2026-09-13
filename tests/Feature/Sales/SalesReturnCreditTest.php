<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\States\SalesReturnStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SR / Phase 3 — the credit note a posted return drafts.
 *
 * The distinction this suite exists to hold: `credit_notes.sales_invoice_id` records where a
 * credit **came from**, not where it has been **applied**. On a paid invoice those must never
 * be confused, because confusing them drives `outstanding()` negative and breaks the identity
 *
 *   invoice.total = received_amount + Σ(applied credits) + outstanding
 *
 * which every receipt, credit application and credit-control decision in the system reads.
 *
 * The existing guard is left exactly as it is, and these tests prove it still bites.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
});

function crInvoice(object $test, float $qty, float $rate): SalesInvoice
{
    $total = round($qty / 1000 * $rate, 4);

    $test->actingAs($test->accounts);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId, 'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1, 'product_id' => $test->productId,
        'description' => 'credit fixture', 'qty' => $qty, 'rate_per_m' => $rate,
        'tax_amount' => 0, 'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

function crPay(object $test, SalesInvoice $invoice, ?float $amount = null): SalesInvoice
{
    $amount ??= (float) $invoice->total;

    $test->actingAs($test->accounts);
    $test->post('/receipts', [
        'customer_id' => $test->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => (int) $invoice->currency_id,
        'amount' => $amount,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $amount]],
    ])->assertSessionHas('success');

    return $invoice->refresh();
}

function crLot(object $test): int
{
    return (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-CR-'.Str::random(10), 'product_id' => $test->productId,
        'kind' => 'finished_goods', 'warehouse_id' => $test->warehouseId,
        'uom_id' => (int) DB::table('uoms')->where('code', 'pcs')->value('id'),
        'received_qty' => 0, 'balance_qty' => 0, 'unit_cost' => 1.5,
        'received_on' => now()->toDateString(), 'status' => 'available', 'created_at' => now(),
    ]);
}

function crReturn(object $test, SalesInvoice $invoice, float $qty): SalesReturn
{
    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->firstOrFail();

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id, 'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => $test->warehouseId, 'returned_on' => now()->toDateString(),
        'reason' => 'Customer returned goods', 'status' => SalesReturn::DRAFT,
        'created_by' => $test->dispatchUser->id,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 1,
        'sales_invoice_line_id' => $line->id, 'product_id' => $line->product_id,
        'lot_id' => crLot($test), 'qty' => $qty, 'rate_per_m' => $line->rate_per_m,
    ]);

    $states = app(SalesReturnStateMachine::class);

    $test->actingAs($test->accounts);
    $states->transition($return, SalesReturn::APPROVED);

    $test->actingAs($test->dispatchUser);
    $states->transition($return->refresh(), SalesReturn::POSTED);

    return $return->refresh();
}

it('drafts a credit note for what came back, at the rate it was billed', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));

    $return = crReturn($this, $invoice, 2500);

    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();

    expect($note->reason)->toBe('return')
        ->and($note->status)->toBe('draft')
        ->and((int) $note->customer_id)->toBe((int) $invoice->customer_id)
        ->and((int) $note->currency_id)->toBe((int) $invoice->currency_id)
        // 2,500 pcs at 375 per 1,000.
        ->and((float) $note->amount)->toBeMoney(937.50);
});

it('records the invoice as provenance, not as an application', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));
    $return = crReturn($this, $invoice, 2500);

    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();
    $machine = app(SalesInvoiceStateMachine::class);

    expect((int) $note->sales_invoice_id)->toBe((int) $invoice->id)
        // Provenance recorded…
        ->and((int) $note->sales_return_id)->toBe((int) $return->id)
        // …and nothing credited. This is the whole distinction.
        ->and($machine->appliedCredits($invoice->refresh()))->toBeMoney(0.0)
        ->and($machine->outstanding($invoice))->toBeMoney(0.0);
});

it('leaves the paid invoice paid, with its money and allocations untouched', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));

    $received = (float) $invoice->received_amount;
    $allocations = DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)
        ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all();

    crReturn($this, $invoice, 4000);

    $machine = app(SalesInvoiceStateMachine::class);
    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and((float) $invoice->received_amount)->toBeMoney($received)
        ->and($machine->outstanding($invoice))->toBeMoney(0.0)
        ->and(
            DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)
                ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all(),
        )->toEqual($allocations)
        // The identity still holds exactly.
        ->and((float) $invoice->total)->toBeMoney(
            (float) $invoice->received_amount + $machine->appliedCredits($invoice) + $machine->outstanding($invoice),
        );
});

it('still refuses to apply that credit to the paid invoice it came from', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));
    $return = crReturn($this, $invoice, 2500);

    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();
    $states = app(CreditNoteStateMachine::class);

    $this->actingAs($this->accounts);
    $states->transition($note, 'approved');

    // The existing guard is untouched and still bites: a paid invoice has nothing outstanding
    // to credit against. Where this credit *can* go is Phase 4's question.
    expect(fn () => $states->transition($note->refresh(), 'applied'))
        ->toThrow(TransitionDenied::class, 'nothing is outstanding to credit against');

    expect($note->refresh()->status)->toBe('approved')
        ->and(app(SalesInvoiceStateMachine::class)->outstanding($invoice->refresh()))->toBeMoney(0.0);
});

it('never drives a paid invoice negative, however much comes back', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));

    // Everything comes back — a credit worth the entire invoice.
    $return = crReturn($this, $invoice, 10000);
    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();

    expect((float) $note->amount)->toBeMoney((float) $invoice->total);

    $machine = app(SalesInvoiceStateMachine::class);
    $invoice->refresh();

    expect($machine->outstanding($invoice))->toBeMoney(0.0)
        ->and($machine->outstanding($invoice))->toBeGreaterThanOrEqual(0.0)
        ->and($invoice->status)->toBe('paid');
});

it('leaves an unpaid invoice credit note working exactly as it did', function (): void {
    // The pre-existing behaviour: an open invoice, a credit note, applied normally.
    $invoice = crInvoice($this, 10000, 375);
    $return = crReturn($this, $invoice, 2000);

    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();
    $states = app(CreditNoteStateMachine::class);
    $machine = app(SalesInvoiceStateMachine::class);

    $outstandingBefore = $machine->outstanding($invoice->refresh());

    $this->actingAs($this->accounts);
    $states->transition($note, 'approved');
    $states->transition($note->refresh(), 'applied');

    $invoice->refresh();

    expect($note->refresh()->status)->toBe('applied')
        ->and($machine->appliedCredits($invoice))->toBeMoney((float) $note->amount)
        ->and($machine->outstanding($invoice))->toBeMoney($outstandingBefore - (float) $note->amount)
        ->and($invoice->status)->toBe('partially_paid');
});

it('rolls the credit note back with the posting when the posting fails', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));

    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->firstOrFail();

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id, 'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => $this->warehouseId, 'returned_on' => now()->toDateString(),
        'reason' => 'Return that cannot post', 'status' => SalesReturn::DRAFT,
        'created_by' => $this->dispatchUser->id,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 1,
        'sales_invoice_line_id' => $line->id, 'product_id' => $line->product_id,
        'lot_id' => crLot($this), 'qty' => 1000, 'rate_per_m' => $line->rate_per_m,
    ]);

    $states = app(SalesReturnStateMachine::class);
    $this->actingAs($this->accounts);
    $states->transition($return, SalesReturn::APPROVED);

    // Strip the lot so posting refuses after the guard has otherwise passed.
    $return->lines()->update(['lot_id' => null]);

    $notesBefore = CreditNote::query()->count();

    $this->actingAs($this->dispatchUser);
    expect(fn () => $states->transition($return->refresh(), SalesReturn::POSTED))
        ->toThrow(TransitionDenied::class);

    expect(CreditNote::query()->count())->toBe($notesBefore)
        ->and(CreditNote::query()->where('sales_return_id', $return->id)->exists())->toBeFalse()
        ->and($return->refresh()->status)->toBe(SalesReturn::APPROVED);
});

it('drafts one credit note per return, not one per invoice', function (): void {
    $invoice = crPay($this, crInvoice($this, 10000, 375));

    $first = crReturn($this, $invoice, 2000);
    $second = crReturn($this, $invoice, 3000);

    $notes = CreditNote::query()->where('sales_invoice_id', $invoice->id)->where('reason', 'return')->get();

    expect($notes)->toHaveCount(2)
        ->and($notes->pluck('sales_return_id')->sort()->values()->all())
        ->toEqual(collect([$first->id, $second->id])->sort()->values()->all())
        ->and((float) $notes->sum('amount'))->toBeMoney(1875.0);

    // Still nothing applied, still not negative.
    expect(app(SalesInvoiceStateMachine::class)->outstanding($invoice->refresh()))->toBeMoney(0.0);
});
