<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemActivationChecklist;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\MasterData\States\ItemStateMachine;
use App\Modules\Product\Models\Product;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * IM-1 — an item is born draft and becomes active only through the gate.
 *
 * The gate reads the same checklist the page shows, so the refusal names the first thing the
 * reader would have seen there. The transition's audit row is the approver's signature.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->actingAs($this->admin);

    $this->states = app(ItemStateMachine::class);
    $this->uoms = DB::table('uoms')->pluck('id', 'code');
    $this->families = DB::table('production_families')->pluck('id', 'code');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'raw_material')->value('id');
});

/** A bought material with nothing set beyond what the form insists on. */
function draftYarn(object $test, array $overrides = []): Item
{
    return app(ItemMasterService::class)->create([
        'name' => 'Polyester yarn 150D — ecru',
        'item_category_id' => DB::table('item_categories')->where('code', 'YARN')->value('id'),
        'base_uom_id' => $test->uoms['kg'],
        ...$overrides,
    ]);
}

it('refuses to activate a draft that is not ready, naming what is missing', function (): void {
    $yarn = draftYarn($this);

    try {
        $this->states->transition($yarn, Item::ACTIVE);
        $this->fail('A bare draft was activated.');
    } catch (TransitionDenied $e) {
        expect($e->rule)->toBe('IM-1')
            ->and($e->getMessage())->toContain('Default warehouse')
            ->and($e->getMessage())->toContain('Stock levels')
            ->and($e->getMessage())->toContain('Standard cost');
    }

    expect($yarn->fresh()->status)->toBe(Item::DRAFT);
});

it('activates a bought item once its warehouse, stock level and rate are set, and signs the audit row', function (): void {
    $yarn = draftYarn($this, ['default_warehouse_id' => $this->warehouseId, 'reorder_level' => 25, 'std_rate' => 1450]);

    $this->states->transition($yarn, Item::ACTIVE);

    $yarn->refresh();

    expect($yarn->status)->toBe(Item::ACTIVE)
        ->and($yarn->activated_by)->toBe($this->admin->id)
        ->and($yarn->activated_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('auditable_type', Item::class)->where('auditable_id', $yarn->id)->where('event', 'status_changed')->exists())->toBeTrue();
});

it('needs the item.activate permission', function (): void {
    // A planner may read items and holds nothing on them.
    $planner = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'planner'))->firstOrFail();
    $yarn = draftYarn($this, ['default_warehouse_id' => $this->warehouseId, 'reorder_level' => 25, 'std_rate' => 1450]);

    $this->actingAs($planner);

    try {
        $this->states->transition($yarn, Item::ACTIVE);
        $this->fail('Activated without the permission.');
    } catch (TransitionDenied $e) {
        expect($e->permission)->toBe('item.activate');
    }
});

it('activates the seeded finished good from its product page once the item side is complete', function (): void {
    $product = Product::query()->whereCode('PRD-NFJ-CARE-01')->firstOrFail();
    $item = $product->item;

    // The demo seeds it active; back to draft to walk the gate.
    DB::table('items')->where('id', $item->id)->update(['status' => Item::DRAFT, 'default_warehouse_id' => null]);

    $this->post("/products/{$product->id}/transition", ['to' => Item::ACTIVE])
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Default warehouse'));

    expect($item->fresh()->status)->toBe(Item::DRAFT);

    DB::table('items')->where('id', $item->id)->update(['default_warehouse_id' => DB::table('warehouses')->where('kind', 'finished_goods')->value('id')]);

    $this->post("/products/{$product->id}/transition", ['to' => Item::ACTIVE])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($item->fresh()->status)->toBe(Item::ACTIVE);
});

it('lists the steps the gate reads, with the product steps passed through for a made item', function (): void {
    $product = Product::query()->whereCode('PRD-NFJ-CARE-01')->firstOrFail();

    $steps = collect(app(ItemActivationChecklist::class)->steps($product->item))->keyBy('key');

    expect($steps->keys()->all())->toContain('classification', 'units', 'specification', 'bom', 'routing', 'wastage', 'warehouse', 'standard_cost', 'approver')
        ->and($steps['bom']['state'])->toBe('done')
        ->and($steps['stock_levels']['state'])->toBe('skipped')
        ->and($steps['qc_plan']['required'])->toBeFalse();
});

it('puts an active item on hold and back, and discontinues only with a reason', function (): void {
    $yarn = draftYarn($this, ['default_warehouse_id' => $this->warehouseId, 'reorder_level' => 25, 'std_rate' => 1450]);
    $this->states->transition($yarn, Item::ACTIVE);

    $this->post("/items/{$yarn->id}/transition", ['to' => Item::ON_HOLD])->assertSessionHas('success');
    expect($yarn->fresh()->status)->toBe(Item::ON_HOLD);

    $this->post("/items/{$yarn->id}/transition", ['to' => Item::ACTIVE])->assertSessionHas('success');
    expect($yarn->fresh()->status)->toBe(Item::ACTIVE);

    $this->post("/items/{$yarn->id}/transition", ['to' => Item::DISCONTINUED])->assertSessionHas('error');
    $this->post("/items/{$yarn->id}/transition", ['to' => Item::DISCONTINUED, 'reason' => 'Supplier withdrew the count'])->assertSessionHas('success');
    expect($yarn->fresh()->status)->toBe(Item::DISCONTINUED)
        ->and($this->states->available($yarn->fresh()))->toBe([]);
});

it('refuses to confirm a sales order whose line names a draft item', function (): void {
    $order = App\Modules\Sales\Models\SalesOrder::query()->where('status', 'draft')->with('lines.product.item')->first()
        ?? App\Modules\Sales\Models\SalesOrder::query()->with('lines.product.item')->firstOrFail();
    $item = $order->lines->first()->product->item;

    DB::table('items')->where('id', $item->id)->update(['status' => Item::DRAFT]);
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'draft']);

    try {
        app(App\Modules\Sales\States\SalesOrderStateMachine::class)->transition($order->fresh(), 'confirmed');
        $this->fail('Confirmed an order for a draft item.');
    } catch (TransitionDenied $e) {
        expect($e->getMessage())->toContain('not active');
    }
});
