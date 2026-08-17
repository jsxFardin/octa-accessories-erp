<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0-4.1/2 — packing is a soft allocation. No ledger row is ever written here; D1 and the
 * ceilings are enforced in the `packed` guard under lot locks, against whatever the client
 * sends — the picker is convenience, not protection.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;

    // Walk the demo job to in_production and put 10,000 good pieces through the final op.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'packing walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();
    DB::table('sales_order_lines')->where('id', $this->soLineId)->increment('produced_qty', 10000);

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
    $this->fgService = app(App\Modules\Manufacturing\Services\FgReceiptService::class);
});

/** Receive FG for the shared job; accepted final QC first when $available. */
function makeFgLot(object $test, float $qty, bool $available = true): object
{
    if ($available) {
        $test->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
        $test->post('/qc-inspections', [
            'job_card_id' => $test->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0,
        ]);
        $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    }

    $receipt = $test->fgService->post($test->jobCard->refresh(), $qty, $test->fgWarehouseId, (string) Str::uuid());

    return DB::table('stock_lots')->where('id', $receipt->lot_id)->first();
}

/** Draft a packing list with one carton holding $qty from $lot, as the dispatch officer. */
function draftPackingList(object $test, object $lot, float $qty): PackingList
{
    $test->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());

    $soId = (int) DB::table('sales_order_lines')->where('id', $test->soLineId)->value('sales_order_id');

    $test->post('/packing-lists', ['sales_order_id' => $soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();

    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();

    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId,
        'lot_id' => $lot->id,
        'qty' => $qty,
    ]);

    return $list;
}

it('packs available FG and writes no stock movement', function (): void {
    $lot = makeFgLot($this, 5000);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $list = draftPackingList($this, $lot, 3000);
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('success');

    $list->refresh();

    expect($list->status)->toBe('packed')
        ->and($list->number)->not->toBeNull()
        ->and((float) $list->total_qty)->toBeQty(3000.0)
        ->and($list->total_cartons)->toBe(1)
        // Packing is allocation, not movement: the ledger did not move, the lot did not shrink.
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) DB::table('stock_lots')->where('id', $lot->id)->value('balance_qty'))->toBeQty(5000.0);
});

it('refuses quarantine FG at the picker and again at the guard', function (): void {
    $lot = makeFgLot($this, 1000, available: false);

    expect($lot->status)->toBe('quarantine');

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->post('/packing-lists', ['sales_order_id' => $soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $this->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();

    // Controller-level refusal.
    $this->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'lot_id' => $lot->id, 'qty' => 100,
    ])->assertSessionHas('error');

    // Bypass the controller entirely — the guard still refuses under locks (D1).
    DB::table('carton_contents')->insert([
        'carton_id' => $carton->id, 'sales_order_line_id' => $this->soLineId,
        'product_id' => $lot->product_id, 'lot_id' => $lot->id, 'qty' => 100,
    ]);

    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('error');

    expect($list->refresh()->status)->toBe('draft');
});

it('refuses a consumed, zero-balance lot', function (): void {
    $lot = makeFgLot($this, 1000);

    // Drain the lot through the legal writer so it reads consumed / zero.
    $model = App\Modules\Inventory\Models\StockLot::query()->findOrFail($lot->id);
    app(App\Modules\Inventory\Services\StockPostingService::class)->post($model, 'dispatch', -1000, $model);

    $list = draftPackingList($this, $lot, 100);
    // Direct insert path — controller already refuses, guard must too.
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('error');

    expect($list->refresh()->status)->toBe('draft');
});

it('refuses packing beyond the lot balance across competing packed lists', function (): void {
    $lot = makeFgLot($this, 1000);

    $first = draftPackingList($this, $lot, 600);
    $this->post("/packing-lists/{$first->id}/transition", ['to' => 'packed'])->assertSessionHas('success');

    // A second list wants 600 of the 400 that is physically unclaimed.
    $second = draftPackingList($this, $lot, 600);
    $this->post("/packing-lists/{$second->id}/transition", ['to' => 'packed'])->assertSessionHas('error');

    expect($second->refresh()->status)->toBe('draft');

    // 400 is fine — partial packing across multiple lists and lots is native.
    $third = draftPackingList($this, $lot, 400);
    $this->post("/packing-lists/{$third->id}/transition", ['to' => 'packed'])->assertSessionHas('success');
});

it('refuses packing beyond the BR-44 band of the order line', function (): void {
    $lot = makeFgLot($this, 10000);

    // The demo line orders 50,000 at 5% over-tolerance; shrink it so the band is testable.
    DB::table('sales_order_lines')->where('id', $this->soLineId)->update(['ordered_qty' => 1000]);

    $list = draftPackingList($this, $lot, 1100); // 1000 × 1.05 = 1050 max
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('error');

    expect($list->refresh()->status)->toBe('draft');
});

it('computes the certified claim of the packed lots (BR-40)', function (): void {
    // Give the job certified consumption so its FG lot carries a GRS claim.
    $certified = DB::table('stock_lots')->where('cert_scheme', 'GRS')->whereNotNull('item_id')->first();
    $issueId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-PACK-1', 'job_card_id' => $this->jobCard->id, 'warehouse_id' => $certified->warehouse_id,
        'issued_on' => now()->toDateString(), 'issue_type' => 'issue', 'status' => 'posted', 'created_at' => now(),
    ]);
    DB::table('material_issue_lines')->insert([
        'material_issue_id' => $issueId, 'line_no' => 1, 'item_id' => $certified->item_id,
        'lot_id' => $certified->id, 'uom_id' => $certified->uom_id, 'qty' => 100, 'unit_cost' => 50,
    ]);

    $lot = makeFgLot($this, 2000);

    expect($lot->cert_scheme)->toBe('GRS');

    $list = draftPackingList($this, $lot, 2000);
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('success');

    $list->refresh();

    expect($list->cert_claim_scheme)->toBe('GRS')
        ->and((float) $list->cert_claim_pct)->toBeGreaterThan(0.0);
});

it('is idempotent on a duplicate pack submission and cancellable while unchallaned', function (): void {
    $lot = makeFgLot($this, 1000);
    $list = draftPackingList($this, $lot, 500);

    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);
    $number = $list->refresh()->number;

    // Double-click: same-status transition is a no-op, not a second confirmation.
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHas('success');
    expect($list->refresh()->number)->toBe($number);

    // Cancelling releases the soft allocation: the lot is packable again in full.
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'cancelled'])->assertSessionHas('success');

    $again = draftPackingList($this, $lot, 1000);
    $this->post("/packing-lists/{$again->id}/transition", ['to' => 'packed'])->assertSessionHas('success');
});

it('blocks users without packing permissions', function (): void {
    $lot = makeFgLot($this, 1000);
    $list = draftPackingList($this, $lot, 100);

    // The driver can see trips, not confirm packing.
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail());

    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertForbidden();
});
