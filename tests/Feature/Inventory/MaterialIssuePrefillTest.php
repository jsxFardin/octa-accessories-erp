<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Opening the issue form from a job card: the card is chosen, what it still needs is listed
 * from its bill of materials less what is already issued, and the store is chosen when one
 * store holds everything.
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();
    $this->card = JobCard::query()->whereNotNull('bom_id')->with('bom.lines')->orderBy('id')->firstOrFail();
    DB::table('job_cards')->where('id', $this->card->id)->update(['status' => 'released']);
    $this->card->refresh();
});

it('lists what the job needs, scaled from its bill of materials, with the card preselected', function (): void {
    $this->actingAs($this->keeper)
        ->get('/material-issues/create?job_card='.$this->card->id)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect($props['preselectJobCardId'])->toBe($this->card->id)
                ->and($props['requirement'])->toHaveCount($this->card->bom->lines->count());

            $line = $this->card->bom->lines->first();
            $row = collect($props['requirement'])->firstWhere('item.id', $line->item_id);

            expect((float) $row['required'])->toEqual($this->card->bom->scaleTo((float) $line->qty_per_base, (float) $this->card->planned_qty))
                ->and($row)->toHaveKeys(['issued', 'remaining', 'warehouses', 'uom']);
        });
});

it('subtracts what posted issues have already moved, returns included', function (): void {
    $line = $this->card->bom->lines->first();
    $warehouse = (int) DB::table('warehouses')->where('is_active', true)->value('id');
    $lot = (int) DB::table('stock_lots')->where('item_id', $line->item_id)->value('id');

    $issue = (int) DB::table('material_issues')->insertGetId([
        'number' => 'MI-T-00001', 'job_card_id' => $this->card->id, 'warehouse_id' => $warehouse,
        'issued_on' => now()->toDateString(), 'issue_type' => 'issue', 'status' => 'posted',
        'issued_by' => $this->keeper->id, 'created_at' => now(),
    ]);
    DB::table('material_issue_lines')->insert(['material_issue_id' => $issue, 'line_no' => 1, 'item_id' => $line->item_id, 'lot_id' => $lot ?: null, 'uom_id' => $line->uom_id, 'qty' => 10, 'unit_cost' => 1]);

    $return = (int) DB::table('material_issues')->insertGetId([
        'number' => 'MI-T-00002', 'job_card_id' => $this->card->id, 'warehouse_id' => $warehouse,
        'issued_on' => now()->toDateString(), 'issue_type' => 'return', 'status' => 'posted',
        'issued_by' => $this->keeper->id, 'created_at' => now(),
    ]);
    DB::table('material_issue_lines')->insert(['material_issue_id' => $return, 'line_no' => 1, 'item_id' => $line->item_id, 'lot_id' => $lot ?: null, 'uom_id' => $line->uom_id, 'qty' => 4, 'unit_cost' => 1]);

    $this->actingAs($this->keeper)
        ->get('/material-issues/create?job_card='.$this->card->id)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($line): void {
            $row = collect($page->toArray()['props']['requirement'])->firstWhere('item.id', $line->item_id);

            expect((float) $row['issued'])->toEqual(6.0)
                ->and((float) $row['remaining'])->toEqual(max(0.0, round((float) $row['required'] - 6.0, 6)));
        });
});

it('chooses the store when one store holds everything still needed, and not otherwise', function (): void {
    $itemIds = $this->card->bom->lines->pluck('item_id')->all();
    $stores = DB::table('warehouses')->where('is_active', true)->where('is_nettable', true)->orderBy('id')->limit(2)->pluck('id')->all();

    // Everything in the first store.
    DB::table('stock_lots')->whereIn('item_id', $itemIds)->update(['warehouse_id' => $stores[0], 'status' => 'available', 'balance_qty' => 1000]);

    $this->actingAs($this->keeper)
        ->get('/material-issues/create?job_card='.$this->card->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('suggestedWarehouseId', (int) $stores[0]));

    // Split across two stores: no guess.
    if (count($stores) > 1 && count($itemIds) > 1) {
        DB::table('stock_lots')->where('item_id', $itemIds[0])->update(['warehouse_id' => $stores[1]]);

        $this->actingAs($this->keeper)
            ->get('/material-issues/create?job_card='.$this->card->id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('suggestedWarehouseId', null));
    }
});

it('lists nothing to pick when the form is opened without a job card', function (): void {
    $this->actingAs($this->keeper)
        ->get('/material-issues/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preselectJobCardId', null)
            ->where('requirement', [])
            ->where('suggestedWarehouseId', null));
});
