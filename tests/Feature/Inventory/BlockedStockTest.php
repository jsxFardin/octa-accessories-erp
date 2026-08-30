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
 * F-06 / D1 / D3 — non-releasable finished goods do not leave the factory.
 *
 * The audit found lot L260818-00010 marked `blocked` with a −2,000 `dispatch` movement
 * against it. The movement predates the block by two days: the lot was `available` when it
 * shipped and was frozen afterwards by a physical count, so that row is history rather than a
 * hole. What was missing was a test that says so — every exit a piece of stock has is
 * enumerated here, and each one is attempted against a blocked lot.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'blocked-stock walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    issueMaterialFor($this->jobCard->refresh(), 60000);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail());
    $this->post('/qc-inspections', ['job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 5000, $this->fgWarehouseId, (string) Str::uuid());
    $this->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    DB::table('certifications')->where('scheme', 'GRS')->update([
        'issued_on' => now()->subYear()->toDateString(),
        'expires_on' => now()->addYear()->toDateString(),
    ]);

    $this->dispatchOfficer = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
});

function packChallanForLot(object $test, float $qty): DeliveryChallan
{
    $test->actingAs($test->dispatchOfficer);

    $test->post('/packing-lists', ['sales_order_id' => $test->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $test->lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHasNoErrors();
    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);

    return DeliveryChallan::query()->latest('id')->firstOrFail();
}

it('lets a released lot dispatch', function (): void {
    $challan = packChallanForLot($this, 2000);

    $this->actingAs($this->dispatchOfficer)
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('success');

    expect($challan->refresh()->status)->toBe('issued')
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty(3000.0);
});

it('refuses to dispatch a lot blocked between packing and issue, and moves no stock', function (): void {
    $challan = packChallanForLot($this, 2000);

    // Exactly the audit's shape: packed while available, frozen by a physical count before
    // the goods reached the gate.
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $balanceBefore = (float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty');
    $deliveredBefore = (float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty');

    $this->actingAs($this->dispatchOfficer)
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('error');

    expect($challan->refresh()->status)->toBe('draft')
        ->and(session('error'))->toContain('blocked')
        ->and(session('error'))->toContain('cannot leave the factory')
        // No ledger row, no balance change, no delivered quantity: nothing happened at all.
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))->toBeQty($balanceBefore)
        ->and((float) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('delivered_qty'))
        ->toBeQty($deliveredBefore)
        ->and(DB::table('stock_ledger')
            ->where('lot_id', $this->lot->id)->where('movement_type', 'dispatch')->count())->toBe(0);
});

it('refuses quarantined, consumed and scrapped lots on the same door', function (): void {
    foreach (['quarantine', 'consumed', 'expired', 'scrapped', 'reserved'] as $status) {
        DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'available']);

        $challan = packChallanForLot($this, 500);

        DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => $status]);

        $ledgerBefore = DB::table('stock_ledger')->count();

        $this->actingAs($this->dispatchOfficer)
            ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
            ->assertSessionHas('error');

        expect($challan->refresh()->status)->toBe('draft')
            ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
    }
});

it('refuses to pack a blocked lot: the picker hides it and D1 refuses it anyway', function (): void {
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $this->actingAs($this->dispatchOfficer);
    $this->post('/packing-lists', ['sales_order_id' => $this->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();

    // Not on offer…
    $this->get("/packing-lists/{$list->id}")
        ->assertInertia(function (Inertia\Testing\AssertableInertia $page): void {
            expect(collect($page->toArray()['props']['availableLots'])->pluck('id'))
                ->not->toContain($this->lot->id);
        });

    // …refused when asked for by id anyway…
    $this->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();

    $this->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $this->soLineId, 'lot_id' => $this->lot->id, 'qty' => 500,
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('blocked')
        ->and(DB::table('carton_contents')->where('carton_id', $carton->id)->count())->toBe(0);

    // …and refused a second time by the D1 guard, which re-reads the lot under a row lock
    // rather than trusting that the row above ever ran.
    DB::table('carton_contents')->insert([
        'carton_id' => $carton->id,
        'sales_order_line_id' => $this->soLineId,
        'product_id' => $this->lot->product_id,
        'lot_id' => $this->lot->id,
        'qty' => 500,
    ]);

    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])
        ->assertSessionHas('error');

    expect($list->refresh()->status)->toBe('draft')
        ->and(session('error'))->toContain('blocked');
});

it('refuses to transfer a blocked lot to another warehouse', function (): void {
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $destination = (int) DB::table('warehouses')->where('id', '!=', $this->lot->warehouse_id)->value('id');

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post('/stock-transfers', [
            'from_warehouse_id' => $this->lot->warehouse_id,
            'to_warehouse_id' => $destination,
            'lines' => [['lot_id' => $this->lot->id, 'qty' => 100]],
        ])->assertSessionHasErrors();

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('still allows a documented write-off of blocked stock, which is not a dispatch', function (): void {
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    // Blocked stock that turns out to be scrap has to be able to leave the books — through an
    // adjustment, under its own permission, with a reason. That is a different door from the
    // gate, and it writes an `adjustment` movement, never a `dispatch`.
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post('/stock-adjustments', [
            'warehouse_id' => $this->lot->warehouse_id,
            'reason' => 'Damaged during the physical count; written off.',
            'lines' => [['lot_id' => $this->lot->id, 'qty_delta' => -100, 'remarks' => 'Damaged in the count aisle']],
        ])->assertSessionHasNoErrors();

    expect(DB::table('stock_ledger')
        ->where('lot_id', $this->lot->id)->where('movement_type', 'dispatch')->count())->toBe(0);
});

it('keeps the historical dispatch of a lot blocked afterwards intact', function (): void {
    // The audit's lot, reconstructed: shipped while available, blocked two days later. The
    // ledger is append-only (I1), so the old movement stays and the balance still reconciles.
    $challan = packChallanForLot($this, 2000);

    $this->actingAs($this->dispatchOfficer)
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('success');

    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $ledgerSum = (float) DB::table('stock_ledger')->where('lot_id', $this->lot->id)->sum('qty');

    expect((float) DB::table('stock_lots')->where('id', $this->lot->id)->value('balance_qty'))
        ->toBeQty($ledgerSum)
        ->and(DB::table('stock_ledger')
            ->where('lot_id', $this->lot->id)->where('movement_type', 'dispatch')->count())->toBe(1);
});
