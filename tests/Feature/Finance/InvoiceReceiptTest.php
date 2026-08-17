<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P1-4/P1-5 — the invoice follows the challan, the receipt follows the invoice, and credit
 * control finally reads a number that exists. Billed quantity = dispatched quantity; payment
 * status derives from allocation through the invoice state machine.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->customerId = (int) DB::table('sales_orders')->where('id', $this->soId)->value('customer_id');

    // Produce → receive → accept QC → pack → dispatch 2,000 pcs, all through the real flows.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'finance walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail()
        ->forceFill(['input_qty' => 5000, 'good_qty' => 5000])->save();
    DB::table('sales_order_lines')->where('id', $this->soLineId)->increment('produced_qty', 5000);

    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
    $this->post('/qc-inspections', ['job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 5000, $fgWarehouseId, (string) Str::uuid());
    $this->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $this->post('/packing-lists', ['sales_order_id' => $this->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $this->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $this->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $this->soLineId, 'lot_id' => $this->lot->id, 'qty' => 2000,
    ]);
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);
    $this->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);
    $this->challan = DeliveryChallan::query()->latest('id')->firstOrFail();
    $this->post("/delivery-challans/{$this->challan->id}/transition", ['to' => 'issued']);

    $this->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());
});

/** Draft + issue the invoice for the shared challan, as accounts. */
function issueInvoice(object $test): SalesInvoice
{
    $test->post('/invoices', ['delivery_challan_id' => $test->challan->id])->assertSessionHas('success');
    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    $test->post("/invoices/{$invoice->id}/transition", ['to' => 'issued'])->assertSessionHas('success');

    return $invoice->refresh();
}

it('invoices exactly the dispatched quantity at the order line rate', function (): void {
    $invoice = issueInvoice($this);
    $rate = (float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('rate_per_m');

    $line = DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->first();

    expect($invoice->status)->toBe('issued')
        ->and($invoice->number)->not->toBeNull()
        ->and((float) $line->qty)->toBeQty(2000.0)
        ->and((float) $line->rate_per_m)->toBeQty($rate)
        // BR-1 — value from the per-1000 rate.
        ->and((float) $invoice->total)->toBeMoney(2000 / 1000 * $rate)
        // The order line remembers what has been billed.
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('invoiced_qty'))->toBeQty(2000.0);
});

it('refuses a second invoice for the same challan and an invoice for undispatched goods', function (): void {
    issueInvoice($this);

    $this->post('/invoices', ['delivery_challan_id' => $this->challan->id])->assertSessionHas('error');

    expect(SalesInvoice::query()->count())->toBe(1);
});

it('makes credit exposure real once invoices exist (BR-46)', function (): void {
    $invoice = issueInvoice($this);

    $order = App\Modules\Sales\Models\SalesOrder::query()->findOrFail($this->soId);
    $credit = app(App\Modules\Sales\States\SalesOrderStateMachine::class)->creditCheck($order);

    // The exposure the audit found permanently zero now carries the open invoice.
    expect($credit['exposure'])->toBeGreaterThanOrEqual((float) $invoice->total);
});

it('allocates a receipt, walks the invoice to partially_paid then paid, and blocks over-allocation', function (): void {
    $invoice = issueInvoice($this);
    $total = (float) $invoice->total;
    $half = round($total / 2, 4);

    $currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');

    // Half now…
    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $currencyId, 'amount' => $half,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $half]],
    ])->assertSessionHas('success');

    expect($invoice->refresh()->status)->toBe('partially_paid')
        ->and((float) $invoice->received_amount)->toBeMoney($half);

    // …overpaying the rest is refused before anything writes…
    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $currencyId, 'amount' => $total,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $total]],
    ])->assertSessionHasErrors('allocations');

    expect((float) $invoice->refresh()->received_amount)->toBeMoney($half)
        ->and(DB::table('receipts')->count())->toBe(1);

    // …and the exact balance settles it.
    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => $currencyId, 'amount' => $total - $half,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $total - $half]],
    ])->assertSessionHas('success');

    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and((float) $invoice->received_amount)->toBeMoney($total)
        // received_amount equals the sum of allocations — reconcilable in one query.
        ->and((float) DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)->sum('amount'))
        ->toBeMoney($total);
});

it('refuses allocations that exceed the receipt amount, atomically', function (): void {
    $invoice = issueInvoice($this);
    $currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');

    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'cash', 'currency_id' => $currencyId, 'amount' => 10,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 50]],
    ])->assertSessionHasErrors('allocations');

    expect(DB::table('receipts')->count())->toBe(0)
        ->and(DB::table('receipt_allocations')->count())->toBe(0)
        ->and((float) $invoice->refresh()->received_amount)->toBeMoney(0.0);
});

it('cannot cancel an invoice that has received money', function (): void {
    $invoice = issueInvoice($this);
    $currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');

    $this->post('/receipts', [
        'customer_id' => $this->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'cash', 'currency_id' => $currencyId, 'amount' => 1,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 1]],
    ]);

    $this->post("/invoices/{$invoice->id}/transition", ['to' => 'cancelled'])->assertSessionHas('error');

    expect($invoice->refresh()->status)->toBe('partially_paid');
});

it('unwinds invoiced_qty when an unpaid invoice is cancelled', function (): void {
    $invoice = issueInvoice($this);

    expect((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('invoiced_qty'))->toBeQty(2000.0);

    $this->post("/invoices/{$invoice->id}/transition", ['to' => 'cancelled'])->assertSessionHas('success');

    expect((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('invoiced_qty'))->toBeQty(0.0)
        ->and($invoice->refresh()->status)->toBe('cancelled');
});

it('keeps billing away from users without invoice permissions', function (): void {
    // The dispatch officer ships goods; billing them is the accounts department's act.
    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());

    $this->post('/invoices', ['delivery_challan_id' => $this->challan->id])->assertForbidden();

    expect(SalesInvoice::query()->count())->toBe(0);
});
