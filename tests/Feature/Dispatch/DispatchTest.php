<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0-4.7/8 — issuing a challan is the one physical exit for finished goods: one `dispatch`
 * ledger movement per line through StockPostingService, delivered_qty in the same
 * transaction, CoC output at shipment and nowhere earlier, all reversible only by a
 * documented return that reverses the ledger.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'dispatch walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();
    DB::table('sales_order_lines')->where('id', $this->soLineId)->increment('produced_qty', 10000);

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    // Certified consumption so the FG lot carries a GRS claim — the CoC output side needs it.
    $certified = DB::table('stock_lots')->where('cert_scheme', 'GRS')->whereNotNull('item_id')->first();
    $issueId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-DISP-1', 'job_card_id' => $this->jobCard->id, 'warehouse_id' => $certified->warehouse_id,
        'issued_on' => now()->toDateString(), 'issue_type' => 'issue', 'status' => 'posted', 'created_at' => now(),
    ]);
    DB::table('material_issue_lines')->insert([
        'material_issue_id' => $issueId, 'line_no' => 1, 'item_id' => $certified->item_id,
        'lot_id' => $certified->id, 'uom_id' => $certified->uom_id, 'qty' => 100, 'unit_cost' => 50,
    ]);

    // Accepted final QC, then a 5,000-piece available FG lot.
    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
    $this->post('/qc-inspections', ['job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 5000, $this->fgWarehouseId, (string) Str::uuid());
    $this->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    // A GRS certificate valid today — BR-43 is evaluated on the challan date.
    DB::table('certifications')->where('scheme', 'GRS')->update([
        'issued_on' => now()->subYear()->toDateString(),
        'expires_on' => now()->addYear()->toDateString(),
    ]);
});

/** Pack $qty of the shared lot and draft its challan, as the dispatch officer. */
function packAndDraftChallan(object $test, float $qty): DeliveryChallan
{
    $test->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());

    $test->post('/packing-lists', ['sales_order_id' => $test->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $test->lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);

    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);

    return DeliveryChallan::query()->latest('id')->firstOrFail();
}

it('issues a challan: one dispatch movement per line, delivered_qty and CoC in the same commit', function (): void {
    $challan = packAndDraftChallan($this, 3000);
    $deliveredBefore = (float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty');

    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('success');

    $challan->refresh();
    $movements = DB::table('stock_ledger')
        ->where('source_type', DeliveryChallan::class)->where('source_id', $challan->id)->get();

    expect($challan->status)->toBe('issued')
        ->and($challan->number)->not->toBeNull()
        ->and($movements)->toHaveCount(1)
        ->and($movements[0]->movement_type)->toBe('dispatch')
        ->and((float) $movements[0]->qty)->toBeQty(-3000.0)
        // The lot physically shrank; delivered_qty moved by exactly the ledger quantity (I9).
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(2000.0)
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty'))
        ->toBeQty($deliveredBefore + 3000.0);

    // CoC output written at shipment — the reconciliation view finally shows an output side.
    $output = DB::table('coc_transactions')->where('direction', 'output')->where('packing_list_id', $challan->packing_list_id)->first();
    expect($output)->not->toBeNull()
        ->and((float) $output->qty)->toBeQty(round(3000 * (float) $this->lot->cert_claim_pct / 100, 6));
    expect((float) DB::table('v_coc_reconciliation')->where('scheme', 'GRS')->sum('certified_output_qty'))
        ->toBeGreaterThan(0.0);

    // The packing list followed its challan; the order reflects fulfilment.
    expect(DB::table('packing_lists')->where('id', $challan->packing_list_id)->value('status'))->toBe('dispatched')
        ->and(DB::table('sales_orders')->where('id', $this->soId)->value('status'))->toBe('partially_delivered');
});

it('treats a duplicate issue as a replay, not a second dispatch', function (): void {
    $challan = packAndDraftChallan($this, 1000);

    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('success');

    expect(DB::table('stock_ledger')->where('source_type', DeliveryChallan::class)->where('source_id', $challan->id)->count())
        ->toBe(1)
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(4000.0);
});

it('refuses to dispatch more than the lot balance even after packing', function (): void {
    $challan = packAndDraftChallan($this, 4000);

    // The lot shrinks between packing and issue — an adjustment, another shipment, shrinkage.
    $model = App\Modules\Inventory\Models\StockLot::query()->findOrFail($this->lot->id);
    app(App\Modules\Inventory\Services\StockPostingService::class)->post($model, 'dispatch', -2000, $model);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('error');

    // Clean refusal: no rows, no quantity movement, challan still draft (I2, I9).
    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and($challan->refresh()->status)->toBe('draft')
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty'))->toBeQty(0.0);
});

it('refuses a lot that lost its availability between packing and issue', function (): void {
    $challan = packAndDraftChallan($this, 1000);

    // QC pulls the lot after packing — issue-time re-validation is the safety net.
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('error');
    expect($challan->refresh()->status)->toBe('draft');
});

it('enforces BR-44: over-band needs the named override with a reason', function (): void {
    DB::table('sales_order_lines')->where('id', $this->soLineId)->update(['ordered_qty' => 2000]);
    // Pack inside the band ceiling (2100), dispatch would land at 2050 — over 2000 but within
    // band, fine. Beyond band: shrink ordered afterwards to force `over`.
    $challan = packAndDraftChallan($this, 2100);
    DB::table('sales_order_lines')->where('id', $this->soLineId)->update(['ordered_qty' => 1000]);

    // Officer, no override reason → refused.
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('error');

    // Officer with a reason but without the permission → refused.
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued', 'override_reason' => 'Customer accepted overrun'])
        ->assertSessionHas('error');
    expect($challan->refresh()->status)->toBe('draft');

    // The MD holds sales_order.override_tolerance — with a typed reason it ships.
    $this->actingAs(User::query()->where('email', 'md@maheenlabel.test')->firstOrFail());
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued', 'override_reason' => 'Customer accepted overrun'])
        ->assertSessionHas('success');
    expect($challan->refresh()->status)->toBe('issued');
});

it('blocks a certified shipment when no certificate is valid on the challan date (BR-43)', function (): void {
    $challan = packAndDraftChallan($this, 1000);

    DB::table('certifications')->where('scheme', 'GRS')->update([
        'issued_on' => '2024-01-01', 'expires_on' => '2024-12-31',
    ]);

    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('error');
    expect($challan->refresh()->status)->toBe('draft')
        ->and(DB::table('stock_ledger')->where('source_type', DeliveryChallan::class)->count())->toBe(0);
});

it('returns a delivery by reversing the ledger and walking delivered_qty back', function (): void {
    $challan = packAndDraftChallan($this, 2000);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);

    expect((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(3000.0);

    // No reason, no return.
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'returned'])->assertSessionHas('error');

    $this->post("/delivery-challans/{$challan->id}/transition", [
        'to' => 'returned', 'return_reason' => 'Address unreachable; goods back at gate',
    ])->assertSessionHas('success');

    // Reversing entry, not an edit (I1): stock restored, delivered walked back, history intact.
    expect((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(5000.0)
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty'))->toBeQty(0.0)
        ->and(DB::table('stock_ledger')->where('source_type', DeliveryChallan::class)->where('source_id', $challan->id)->count())->toBe(1)
        ->and($challan->refresh()->status)->toBe('returned');
});

it('closes the line and the order on delivery inside the BR-45 band', function (): void {
    DB::table('sales_order_lines')->where('id', $this->soLineId)->update(['ordered_qty' => 3000]);

    $challan = packAndDraftChallan($this, 2900); // ≥ 3000 × 0.95 = 2850 → closable
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'delivered', 'pod_ref' => 'POD-778'])
        ->assertSessionHas('success');

    expect(DB::table('sales_order_lines')->where('id', $this->soLineId)->value('status'))->toBe('completed')
        ->and(DB::table('sales_orders')->where('id', $this->soId)->value('status'))->toBe('delivered')
        ->and(DB::table('packing_lists')->where('id', $challan->packing_list_id)->value('status'))->toBe('delivered');
});

it('keeps unauthorized users out of the challan entirely', function (): void {
    $challan = packAndDraftChallan($this, 500);

    // QC has packing rights but no challan rights at all.
    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertForbidden();

    expect($challan->refresh()->status)->toBe('draft');
});

it('reconciles: sum of dispatch ledger equals sum of challan lines equals delivered_qty', function (): void {
    $first = packAndDraftChallan($this, 1500);
    $this->post("/delivery-challans/{$first->id}/transition", ['to' => 'issued']);

    $second = packAndDraftChallan($this, 1000);
    $this->post("/delivery-challans/{$second->id}/transition", ['to' => 'issued']);

    $ledger = (float) DB::table('stock_ledger')
        ->where('source_type', DeliveryChallan::class)->where('movement_type', 'dispatch')->sum('qty');
    $lines = (float) DB::table('delivery_challan_lines')
        ->whereIn('delivery_challan_id', [$first->id, $second->id])->sum('qty');
    $delivered = (float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty');

    expect(-$ledger)->toBeQty($lines)->toBeQty($delivered)->toBeQty(2500.0)
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(2500.0);
});
