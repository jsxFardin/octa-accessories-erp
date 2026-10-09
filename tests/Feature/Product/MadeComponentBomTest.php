<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\Product\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The item master is one table so that what one family makes, another consumes: a family-01
 * tape is a material on a family-04 zipper's bill, received from the tape job as stock the
 * zipper job can draw. Nothing in planning or inventory needs to know which it is handling.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $families = DB::table('production_families')->pluck('id', 'code');
    $uoms = DB::table('uoms')->pluck('id', 'code');
    $customerId = (int) DB::table('customers')->value('id');

    $service = app(ItemMasterService::class);

    $this->tape = $service->create([
        'name' => 'Zipper tape, polyester, 12 mm',
        'item_type' => 'semi_finished',
        'production_family_id' => $families['01'],
        'garment_type' => 'both',
        'material_base' => 'textile',
        'base_uom_id' => $uoms['mtr'],
        'status' => Item::ACTIVE,
        'attributes' => ['width_mm' => 12, 'construction' => 'woven', 'material' => 'Polyester'],
    ], ['product_type' => 'tape']);

    $this->zipper = $service->create([
        'name' => 'Nylon zipper #5, 20 cm',
        'item_type' => 'finished_good',
        'production_family_id' => $families['04'],
        'garment_type' => 'woven',
        'material_base' => 'plastic',
        'base_uom_id' => $uoms['pcs'],
        'order_uom_id' => $uoms['pcs'],
        'status' => Item::ACTIVE,
        'attributes' => ['chain_type' => 'nylon', 'teeth_no' => 5],
    ], ['product_type' => 'other', 'customer_id' => $customerId, 'spec_scope' => 'buyer']);
});

it('offers a made item on another product\'s bill of materials, never the product itself', function (): void {
    $zipper = $this->zipper->product;
    $tapeUom = $this->tape->base_uom_id;

    $this->post("/products/{$zipper->id}/boms", [
        'base_qty' => 1000,
        'lines' => [['item_id' => $this->tape->id, 'uom_id' => $tapeUom, 'qty_per_base' => 420, 'wastage_pct' => 3]],
    ])->assertSessionHasNoErrors();

    expect(DB::table('bom_lines')->where('item_id', $this->tape->id)->exists())->toBeTrue();

    $this->post("/products/{$zipper->id}/boms", [
        'base_qty' => 1000,
        'lines' => [['item_id' => $this->zipper->id, 'uom_id' => $this->zipper->base_uom_id, 'qty_per_base' => 1]],
    ])->assertSessionHasErrors('lines.0.item_id');

    // The picker lists the tape (made) beside bought materials, and not the zipper's own item.
    $this->get("/products/{$zipper->id}/boms/create")
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => collect($items)->contains(fn ($row) => (int) $row['id'] === $this->tape->id && $row['make_or_buy'] === 'make')
                && ! collect($items)->contains(fn ($row) => (int) $row['id'] === $this->zipper->id)));
});

it('receives a job\'s output as stock of its item, in the item\'s unit, that another job can draw', function (): void {
    // The seeded woven label job stands in for the tape job: what matters is that its output
    // lands on the product's item, in that item's stock unit, and counts as on hand for it.
    $jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $product = Product::query()->findOrFail($jobCard->product_id);
    $before = app(StockAvailability::class)->onHand($product->item_id);

    $lot = app(App\Modules\Inventory\Services\StockPostingService::class)->receive(
        [
            'lot_no' => 'L-XF-'.Illuminate\Support\Str::random(6),
            'product_id' => $product->id,
            'item_id' => $product->item_id,
            'kind' => 'finished_goods',
            'warehouse_id' => (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id'),
            'uom_id' => $product->item->base_uom_id,
            'job_card_id' => $jobCard->id,
            'received_on' => now()->toDateString(),
            'status' => 'available',
        ],
        250,
        1.2,
        $jobCard,
        movementType: 'production_output',
    );

    expect((int) $lot->item_id)->toBe((int) $product->item_id)
        ->and((int) $lot->uom_id)->toBe((int) $product->item->base_uom_id)
        ->and(app(StockAvailability::class)->onHand($product->item_id))->toBe($before + 250.0)
        ->and(collect(app(StockAvailability::class)->candidateLots($product->item_id))->pluck('id'))->toContain($lot->id);
});
