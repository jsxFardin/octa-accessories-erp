<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\States\SalesReturnStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SR / Phase 2 — the goods actually coming back.
 *
 * These run the real chain: produce, QC, receive to FG, pack, dispatch, invoice, pay. That
 * matters because the rules being tested are about *provenance* — which lot left, on which
 * challan, carrying which certified claim — and a hand-built lot cannot exercise any of it.
 *
 * The invariant from Phase 1 still holds throughout and is re-asserted here: the paid invoice
 * is not touched by any of this.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->customerId = (int) DB::table('sales_orders')->where('id', $this->soId)->value('customer_id');
    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
});

/** Produce, QC-accept and receive $qty of finished goods; returns the FG lot. */
function produceLot(object $test, float $qty): StockLot
{
    $test->actingAs($test->admin);

    $states = app(JobCardStateMachine::class);
    $states->transition($test->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'return walkthrough']);
    $states->transition($test->jobCard->refresh(), JobCard::IN_PRODUCTION);
    issueMaterialFor($test->jobCard->refresh(), 60000);

    $test->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail()
        ->forceFill(['input_qty' => $qty, 'good_qty' => $qty])->save();
    DB::table('sales_order_lines')->where('id', $test->soLineId)->increment('produced_qty', $qty);

    $test->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail());
    $test->post('/qc-inspections', [
        'job_card_id' => $test->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0,
    ]);

    $test->actingAs($test->admin);
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($test->jobCard->refresh(), $qty, $test->fgWarehouseId, (string) Str::uuid());

    return StockLot::query()->findOrFail($receipt->lot_id);
}

/** Pack and dispatch $qty of $lot, then invoice and pay it in full. */
function shipAndBill(object $test, StockLot $lot, float $qty): SalesInvoice
{
    $test->actingAs($test->dispatchUser);

    $test->post('/packing-lists', ['sales_order_id' => $test->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);
    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);

    $challan = DeliveryChallan::query()->latest('id')->firstOrFail();
    $test->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);

    $test->actingAs($test->accounts);
    $test->post('/invoices', ['delivery_challan_id' => $challan->id]);
    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    $test->post("/invoices/{$invoice->id}/transition", ['to' => 'issued']);
    $invoice->refresh();

    $test->post('/receipts', [
        'customer_id' => $test->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => (int) $invoice->currency_id,
        'amount' => (float) $invoice->total,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => (float) $invoice->total]],
    ])->assertSessionHas('success');

    return $invoice->refresh();
}

/** A return of $qty from $lot against $invoice's first line. */
function returnFrom(object $test, SalesInvoice $invoice, StockLot $lot, float $qty): SalesReturn
{
    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->firstOrFail();

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id,
        'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => $test->fgWarehouseId,
        'returned_on' => now()->toDateString(),
        'reason' => 'Customer returned part of the shipment',
        'status' => SalesReturn::DRAFT,
        'created_by' => $test->dispatchUser->id,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 1,
        'sales_invoice_line_id' => $line->id,
        'product_id' => $line->product_id,
        'lot_id' => $lot->id,
        'qty' => $qty,
        'rate_per_m' => $line->rate_per_m,
    ]);

    return $return->refresh();
}

function postIt(object $test, SalesReturn $return): SalesReturn
{
    $states = app(SalesReturnStateMachine::class);

    $test->actingAs($test->accounts);
    $states->transition($return, SalesReturn::APPROVED);

    $test->actingAs($test->dispatchUser);
    $states->transition($return->refresh(), SalesReturn::POSTED);

    return $return->refresh();
}

it('puts the goods back into the lot they shipped on, at that lot cost', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    $costBefore = (float) $lot->refresh()->unit_cost;
    $balanceAfterShipping = (float) $lot->balance_qty;

    postIt($this, returnFrom($this, $invoice, $lot, 2500));

    $lot->refresh();

    expect((float) $lot->balance_qty)->toBeQty($balanceAfterShipping + 2500)
        // A return is not a purchase. The average must not move.
        ->and((float) $lot->unit_cost)->toBeMoney($costBefore);

    $entry = DB::table('stock_ledger')
        ->where('source_type', SalesReturn::class)
        ->where('lot_id', $lot->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->movement_type)->toBe('sales_return')
        ->and((float) $entry->qty)->toBeQty(2500.0)
        ->and((float) $entry->unit_cost)->toBeMoney($costBefore)
        ->and((float) $entry->value)->toBeMoney(round(2500 * $costBefore, 4));
});

it('quarantines returned goods rather than putting them straight back on sale', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    // Shipping everything drew the lot to zero, so it is `consumed`.
    expect($lot->refresh()->status)->toBe('consumed');

    postIt($this, returnFrom($this, $invoice, $lot, 4000));

    expect($lot->refresh()->status)->toBe('quarantine');

    // BR-37's suggestion only offers `available` lots, so nothing will pick this up until
    // somebody has looked at it.
    $suggestable = app(App\Modules\Inventory\Services\StockAvailability::class)
        ->candidateLots((int) ($lot->item_id ?? 0));

    expect(collect($suggestable)->pluck('id'))->not->toContain($lot->id);
});

it('keeps the paid invoice exactly as it was', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    expect($invoice->status)->toBe('paid');

    $received = (float) $invoice->received_amount;
    $allocations = DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)
        ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all();

    postIt($this, returnFrom($this, $invoice, $lot, 3000));

    $machine = app(App\Modules\Finance\States\SalesInvoiceStateMachine::class);
    $invoice->refresh();

    expect($invoice->status)->toBe('paid')
        ->and((float) $invoice->received_amount)->toBeMoney($received)
        ->and($machine->outstanding($invoice))->toBeMoney(0.0)
        ->and(
            DB::table('receipt_allocations')->where('sales_invoice_id', $invoice->id)
                ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all(),
        )->toEqual($allocations);
});

it('reduces certified output in proportion rather than deleting it', function (): void {
    $lot = produceLot($this, 10000);

    // Give the lot a claim so a CoC output row is written at dispatch (BR-42).
    DB::table('stock_lots')->where('id', $lot->id)
        ->update(['cert_scheme' => 'GRS', 'cert_claim_pct' => 100]);

    $invoice = shipAndBill($this, $lot->refresh(), 10000);

    $before = DB::table('coc_transactions')->where('direction', 'output')->where('lot_id', $lot->id)->first();

    expect($before)->not->toBeNull();

    // A quarter of the shipment comes back; three quarters legitimately stayed shipped.
    postIt($this, returnFrom($this, $invoice, $lot, 2500));

    $after = DB::table('coc_transactions')->where('direction', 'output')->where('lot_id', $lot->id)->first();

    expect($after)->not->toBeNull()
        ->and((float) $after->qty)->toBeQty(round((float) $before->qty * 0.75, 6));
});

it('removes the certified output row when the whole shipment comes back', function (): void {
    $lot = produceLot($this, 10000);
    DB::table('stock_lots')->where('id', $lot->id)
        ->update(['cert_scheme' => 'GRS', 'cert_claim_pct' => 100]);

    $invoice = shipAndBill($this, $lot->refresh(), 10000);

    postIt($this, returnFrom($this, $invoice, $lot, 10000));

    // `coc_qty_chk` forbids a zero row, so nothing shipped means no output row.
    expect(DB::table('coc_transactions')->where('direction', 'output')->where('lot_id', $lot->id)->exists())
        ->toBeFalse();
});

it('refuses to post a line that does not say which lot came back', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    $return = returnFrom($this, $invoice, $lot, 1000);
    $return->lines()->update(['lot_id' => null]);

    $states = app(SalesReturnStateMachine::class);

    $this->actingAs($this->accounts);
    $states->transition($return->refresh(), SalesReturn::APPROVED);

    $this->actingAs($this->dispatchUser);

    expect(fn () => $states->transition($return->refresh(), SalesReturn::POSTED))
        ->toThrow(TransitionDenied::class, 'does not say which lot');
});

it('refuses a lot that never left on this invoice\'s challan', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    // A real lot of the right product, but one this customer was never sent.
    $stranger = (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-SR-STRANGER', 'product_id' => $lot->product_id, 'kind' => 'finished_goods',
        'warehouse_id' => $this->fgWarehouseId, 'uom_id' => $lot->uom_id,
        'received_qty' => 500, 'balance_qty' => 500, 'unit_cost' => 1,
        'received_on' => now()->toDateString(), 'status' => 'available', 'created_at' => now(),
    ]);

    $return = returnFrom($this, $invoice, $lot, 1000);
    $return->lines()->update(['lot_id' => $stranger]);

    $states = app(SalesReturnStateMachine::class);

    $this->actingAs($this->accounts);
    $states->transition($return->refresh(), SalesReturn::APPROVED);

    $this->actingAs($this->dispatchUser);

    expect(fn () => $states->transition($return->refresh(), SalesReturn::POSTED))
        ->toThrow(TransitionDenied::class, 'never dispatched on the challan');
});

it('rolls the whole posting back when any part of it fails', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);

    $return = returnFrom($this, $invoice, $lot, 2000);

    // A second line naming a lot that was never shipped: the first line is perfectly good, so
    // this only stays consistent if the refusal happens before anything posts.
    $stranger = (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-SR-ROLLBACK', 'product_id' => $lot->product_id, 'kind' => 'finished_goods',
        'warehouse_id' => $this->fgWarehouseId, 'uom_id' => $lot->uom_id,
        'received_qty' => 0, 'balance_qty' => 0, 'unit_cost' => 1,
        'received_on' => now()->toDateString(), 'status' => 'available', 'created_at' => now(),
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 2,
        'sales_invoice_line_id' => (int) SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->value('id'),
        'product_id' => $lot->product_id, 'lot_id' => $stranger,
        'qty' => 1000, 'rate_per_m' => 375,
    ]);

    $balanceBefore = (float) $lot->refresh()->balance_qty;
    $ledgerBefore = DB::table('stock_ledger')->where('movement_type', 'sales_return')->count();

    $states = app(SalesReturnStateMachine::class);
    $this->actingAs($this->accounts);
    $states->transition($return->refresh(), SalesReturn::APPROVED);

    $this->actingAs($this->dispatchUser);

    expect(fn () => $states->transition($return->refresh(), SalesReturn::POSTED))
        ->toThrow(TransitionDenied::class);

    expect($return->refresh()->status)->toBe(SalesReturn::APPROVED)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceBefore)
        ->and(DB::table('stock_ledger')->where('movement_type', 'sales_return')->count())->toBe($ledgerBefore)
        // The quantity cache must not have moved either.
        ->and((float) DB::table('sales_invoice_lines')
            ->where('sales_invoice_id', $invoice->id)->value('returned_qty'))->toBeQty(0.0);
});

it('leaves the pre-delivery challan return working exactly as before', function (): void {
    $lot = produceLot($this, 10000);
    $invoice = shipAndBill($this, $lot, 10000);
    $challan = DeliveryChallan::query()->findOrFail($invoice->delivery_challan_id);

    $balanceBefore = (float) $lot->refresh()->balance_qty;

    // The old path: the whole consignment refused, reversed whole, credit note auto-drafted.
    $this->actingAs($this->dispatchUser);
    $this->post("/delivery-challans/{$challan->id}/transition", [
        'to' => 'returned', 'return_reason' => 'refused at the gate',
    ]);

    expect($challan->refresh()->status)->toBe('returned')
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceBefore + 10000)
        ->and(DB::table('credit_notes')->where('sales_invoice_id', $invoice->id)->where('reason', 'return')->exists())
        ->toBeTrue();

    // And it did it its own way — by reversing the dispatch, not by posting a sales_return.
    expect(DB::table('stock_ledger')->where('movement_type', 'sales_return')->count())->toBe(0);
});
