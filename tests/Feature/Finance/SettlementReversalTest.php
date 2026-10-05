<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Receipt;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * UX audit H-27. A posted receipt or payment could not be corrected: `bounced` and `cancelled`
 * were statuses nothing could reach. Reversing one now takes back exactly what it settled and
 * derives each invoice's or bill's status again from what is left.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();

    $order = SalesOrder::query()->firstOrFail();
    $this->customerId = (int) $order->customer_id;

    $this->invoice = fn (string $number, float $total): SalesInvoice => SalesInvoice::query()->create([
        'number' => $number, 'customer_id' => $this->customerId, 'sales_order_id' => $order->id,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $this->base->id, 'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'received_amount' => 0, 'status' => 'issued',
    ]);

    $this->receive = function (float $amount, array $allocations): Receipt {
        $this->actingAs($this->accounts)->post('/receipts', [
            'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(), 'method' => 'cheque',
            'reference_no' => 'CHQ-0001', 'currency_id' => $this->base->id, 'amount' => $amount,
            'allocations' => $allocations,
        ])->assertSessionHasNoErrors();

        return Receipt::query()->latest('id')->firstOrFail();
    };
});

it('un-pays every invoice a bounced cheque settled, each by its own amount', function (): void {
    $first = ($this->invoice)('INV-REV-1', 1000);
    $second = ($this->invoice)('INV-REV-2', 600);

    // One cheque across two invoices: the first in full, the second in part.
    $receipt = ($this->receive)(1400, [
        ['sales_invoice_id' => $first->id, 'amount' => 1000],
        ['sales_invoice_id' => $second->id, 'amount' => 400],
    ]);

    expect($first->refresh()->status)->toBe('paid')
        ->and($second->refresh()->status)->toBe('partially_paid');

    $this->actingAs($this->accounts)
        ->post("/receipts/{$receipt->id}/reverse", ['outcome' => 'bounced', 'reason' => 'Returned unpaid by the bank'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($receipt->refresh()->status)->toBe('bounced')
        ->and($receipt->remarks)->toContain('Returned unpaid by the bank')
        ->and($first->refresh()->status)->toBe('issued')
        ->and((float) $first->received_amount)->toBe(0.0)
        ->and($second->refresh()->status)->toBe('issued')
        ->and((float) $second->received_amount)->toBe(0.0)
        // The allocations are kept as the record of what the receipt had settled.
        ->and(DB::table('receipt_allocations')->where('receipt_id', $receipt->id)->count())->toBe(2);
});

it('leaves an invoice partly paid when another receipt still stands against it', function (): void {
    $invoice = ($this->invoice)('INV-REV-3', 1000);

    $kept = ($this->receive)(300, [['sales_invoice_id' => $invoice->id, 'amount' => 300]]);
    $voided = ($this->receive)(700, [['sales_invoice_id' => $invoice->id, 'amount' => 700]]);

    expect($invoice->refresh()->status)->toBe('paid');

    $this->actingAs($this->accounts)
        ->post("/receipts/{$voided->id}/reverse", ['outcome' => 'cancelled', 'reason' => 'Keyed against the wrong customer']);

    expect($invoice->refresh()->status)->toBe('partially_paid')
        ->and((float) $invoice->received_amount)->toBe(300.0)
        ->and($kept->refresh()->status)->toBe('posted')
        ->and($voided->refresh()->status)->toBe('cancelled');
});

it('does not count the invoice onto its sales order a second time when it returns to issued', function (): void {
    $invoice = ($this->invoice)('INV-REV-4', 500);
    $lineId = (int) DB::table('sales_order_lines')->where('sales_order_id', $invoice->sales_order_id)->value('id');

    DB::table('sales_invoice_lines')->insert([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1, 'sales_order_line_id' => $lineId,
        'product_id' => DB::table('products')->value('id'), 'description' => 'reversal fixture',
        'qty' => 1000, 'rate_per_m' => 500, 'tax_amount' => 0, 'amount' => 500,
    ]);

    $before = (float) DB::table('sales_order_lines')->where('id', $lineId)->value('invoiced_qty');

    $receipt = ($this->receive)(500, [['sales_invoice_id' => $invoice->id, 'amount' => 500]]);
    $this->actingAs($this->accounts)
        ->post("/receipts/{$receipt->id}/reverse", ['outcome' => 'bounced', 'reason' => 'Returned unpaid']);

    expect($invoice->refresh()->status)->toBe('issued')
        ->and((float) DB::table('sales_order_lines')->where('id', $lineId)->value('invoiced_qty'))->toBe($before);
});

it('reverses a receipt once and asks for a reason', function (): void {
    $invoice = ($this->invoice)('INV-REV-5', 200);
    $receipt = ($this->receive)(200, [['sales_invoice_id' => $invoice->id, 'amount' => 200]]);

    $this->actingAs($this->accounts)->post("/receipts/{$receipt->id}/reverse", ['outcome' => 'bounced', 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($receipt->refresh()->status)->toBe('posted');

    $this->actingAs($this->accounts)->post("/receipts/{$receipt->id}/reverse", ['outcome' => 'bounced', 'reason' => 'Returned unpaid']);
    $this->actingAs($this->accounts)->post("/receipts/{$receipt->id}/reverse", ['outcome' => 'cancelled', 'reason' => 'Second attempt'])
        ->assertSessionHasErrors('reason');

    expect($receipt->refresh()->status)->toBe('bounced')
        ->and((float) $invoice->refresh()->received_amount)->toBe(0.0);
});

it('voids a supplier payment and makes its bill payable again', function (): void {
    $supplier = DB::table('suppliers')->where('is_active', true)->firstOrFail();

    $billId = DB::table('supplier_bills')->insertGetId([
        'number' => 'SB-REV-1', 'supplier_id' => $supplier->id, 'bill_no' => 'REV-1',
        'bill_date' => now()->toDateString(), 'currency_id' => $this->base->id, 'exchange_rate' => 1,
        'subtotal' => 900, 'tax_amount' => 0, 'total' => 900, 'paid_amount' => 0, 'status' => 'approved',
    ]);

    $this->actingAs($this->accounts)->post('/payments', [
        'supplier_id' => $supplier->id, 'payment_date' => now()->toDateString(), 'method' => 'bank_transfer',
        'currency_id' => $this->base->id, 'amount' => 900,
        'allocations' => [['supplier_bill_id' => $billId, 'amount' => 900]],
    ])->assertSessionHasNoErrors();

    $payment = Payment::query()->latest('id')->firstOrFail();

    expect(DB::table('supplier_bills')->where('id', $billId)->value('status'))->toBe('paid');

    $this->actingAs($this->accounts)
        ->post("/payments/{$payment->id}/reverse", ['reason' => 'Entered twice by mistake'])
        ->assertSessionHas('success');

    $bill = DB::table('supplier_bills')->where('id', $billId)->first();

    expect($payment->refresh()->status)->toBe('cancelled')
        ->and($bill->status)->toBe('approved')
        ->and((float) $bill->paid_amount)->toBe(0.0);
});

it('shows a receipt and a payment with what they settled', function (): void {
    $invoice = ($this->invoice)('INV-REV-6', 150);
    $receipt = ($this->receive)(150, [['sales_invoice_id' => $invoice->id, 'amount' => 150]]);

    $this->actingAs($this->accounts)->get("/receipts/{$receipt->id}")
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->component('Finance/Receipts/Show')
            ->where('receipt.number', $receipt->number)
            ->has('allocations', 1)
            ->where('allocations.0.invoice_number', 'INV-REV-6'));
});
