<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemMasterService;
use Illuminate\Support\Facades\DB;

/**
 * Spec §1 — material plan: a made component short for a job is suggested as a job card, not
 * a purchase order, with the quantity to make and the product to raise it for.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->actingAs($this->admin);

    $families = DB::table('production_families')->pluck('id', 'code');
    $uoms = DB::table('uoms')->pluck('id', 'code');
    $service = app(ItemMasterService::class);

    $this->tape = $service->create([
        'name' => 'Zipper tape 12 mm',
        'item_type' => 'semi_finished',
        'production_family_id' => $families['01'],
        'garment_type' => 'both',
        'material_base' => 'textile',
        'base_uom_id' => $uoms['mtr'],
        'status' => Item::ACTIVE,
        'attributes' => ['width_mm' => 12, 'construction' => 'woven', 'material' => 'Polyester'],
    ], ['product_type' => 'other'], $this->admin->id);

    $this->zipper = $service->create([
        'name' => 'Nylon zipper #5',
        'item_type' => 'finished_good',
        'production_family_id' => $families['04'],
        'garment_type' => 'woven',
        'material_base' => 'plastic',
        'base_uom_id' => $uoms['pcs'],
        'order_uom_id' => $uoms['pcs'],
        'status' => Item::ACTIVE,
        'attributes' => ['chain_type' => 'nylon', 'teeth_no' => 5],
    ], ['product_type' => 'other'], $this->admin->id);

    $this->post("/products/{$this->zipper->product->id}/boms", [
        'base_qty' => 1000,
        'lines' => [['item_id' => $this->tape->id, 'uom_id' => $uoms['mtr'], 'qty_per_base' => 420, 'wastage_pct' => 0]],
    ])->assertSessionHasNoErrors();

    $bomId = DB::table('boms')->where('product_id', $this->zipper->product->id)->value('id');
    DB::table('boms')->where('id', $bomId)->update(['status' => 'active']);
});

it('suggests a job card, not a purchase, for a made component the plan is short of', function (): void {
    // A zipper job for stock draws 420 m of tape per 1,000 pcs; none is on hand.
    $this->post('/job-cards', [
        'product_id' => $this->zipper->product->id,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'planned_qty' => 2000,
    ])->assertSessionHasNoErrors();

    $this->post('/mrp/run', ['horizon_days' => 60])->assertRedirect();

    $row = DB::table('material_requirements')->where('item_id', $this->tape->id)->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->suggested_po_qty)->toBe(0.0)
        ->and((float) $row->suggested_make_qty)->toBe(840.0)
        ->and((bool) $row->is_shortage)->toBeTrue();

    $runId = DB::table('mrp_runs')->orderByDesc('id')->value('id');

    $this->get("/mrp?run={$runId}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $tape = collect($page->toArray()['props']['requirements'])->firstWhere('item_code', $this->tape->code);

            expect($tape['make_or_buy'])->toBe('make')
                ->and((float) $tape['suggested_make_qty'])->toBe(840.0)
                ->and((int) $tape['product_id'])->toBe($this->tape->product->id);
        });
});
