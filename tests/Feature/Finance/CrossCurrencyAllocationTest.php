<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * BR-57 — money is allocated only within one currency.
 *
 * A receipt allocation lands in `sales_invoices.received_amount` and is compared against the
 * P2-1 outstanding balance; both are figures in the **invoice's** currency. Nothing checked
 * that the receipt was in that currency, so a BDT 11.63 receipt would settle a USD 11.63
 * invoice in full and report it paid — a debt of BDT 1,425 cleared with BDT 11.63. The same
 * hole existed between a payment and a supplier bill.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->rate = (float) DB::table('exchange_rates')
        ->where('currency_id', $this->usd->id)->orderByDesc('effective_on')->value('rate_to_base');

    $order = SalesOrder::query()->firstOrFail();

    $this->invoice = SalesInvoice::query()->create([
        'number' => 'INV-QA-BR57',
        'customer_id' => $order->customer_id,
        'sales_order_id' => $order->id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $this->usd->id,
        'exchange_rate' => $this->rate,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'total' => 1000,
        'received_amount' => 0,
        'status' => 'issued',
    ]);
});

it('refuses a receipt raised in a currency the invoice is not in', function (): void {
    $this->actingAs($this->accounts)->post('/receipts', [
        'customer_id' => $this->invoice->customer_id,
        'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer',
        'currency_id' => $this->base->id,
        'amount' => 1000,
        'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => 1000]],
    ])->assertSessionHasErrors('allocations');

    // Nothing settled, nothing part-settled: the invoice is untouched.
    expect((float) $this->invoice->fresh()->received_amount)->toBe(0.0)
        ->and($this->invoice->fresh()->status)->toBe('issued');
});

it('accepts a receipt in the invoice currency', function (): void {
    $this->actingAs($this->accounts)->post('/receipts', [
        'customer_id' => $this->invoice->customer_id,
        'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer',
        'currency_id' => $this->usd->id,
        'exchange_rate' => $this->rate,
        'amount' => 1000,
        'allocations' => [['sales_invoice_id' => $this->invoice->id, 'amount' => 1000]],
    ])->assertSessionHasNoErrors();

    expect((float) $this->invoice->fresh()->received_amount)->toBe(1000.0)
        ->and($this->invoice->fresh()->status)->toBe('paid');
});

it('refuses a payment raised in a currency the supplier bill is not in', function (): void {
    $supplier = DB::table('suppliers')->where('is_active', true)->firstOrFail();

    $billId = DB::table('supplier_bills')->insertGetId([
        'number' => 'SB-QA-BR57',
        'supplier_id' => $supplier->id,
        'bill_no' => 'QA-BR57',
        'bill_date' => now()->toDateString(),
        'currency_id' => $this->usd->id,
        'exchange_rate' => $this->rate,
        'subtotal' => 500,
        'tax_amount' => 0,
        'total' => 500,
        'paid_amount' => 0,
        'status' => 'approved',
    ]);

    $this->actingAs($this->accounts)->post('/payments', [
        'supplier_id' => $supplier->id,
        'payment_date' => now()->toDateString(),
        'method' => 'bank_transfer',
        'currency_id' => $this->base->id,
        'amount' => 500,
        'allocations' => [['supplier_bill_id' => $billId, 'amount' => 500]],
    ])->assertSessionHasErrors('allocations');

    expect((float) DB::table('supplier_bills')->where('id', $billId)->value('paid_amount'))->toBe(0.0);
});
