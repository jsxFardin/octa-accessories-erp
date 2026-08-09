<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Product\Models\Routing;
use Illuminate\Support\Facades\DB;

/**
 * The buying chain end to end: requisition → approval → purchase order → approval band.
 *
 * These routes had no screens until now, so nothing had ever posted to them. Each test here
 * submits the payload the form actually sends and asserts on the rows that result.
 */
beforeEach(function (): void {
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $this->purchaseManager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();
    $this->unit = FactoryUnit::query()->firstOrFail();
    $this->item = Item::query()->where('is_active', true)->firstOrFail();
    $this->supplier = Supplier::query()->where('is_approved', true)->firstOrFail();
});

function makeRequisition(object $test, float $qty = 500): PurchaseRequisition
{
    $test->actingAs($test->planner)->post('/purchase-requisitions', [
        'factory_unit_id' => $test->unit->id,
        'requested_on' => now()->toDateString(),
        'required_by' => now()->addDays(14)->toDateString(),
        'lines' => [[
            'item_id' => $test->item->id,
            'uom_id' => $test->item->base_uom_id,
            'qty' => $qty,
        ]],
    ])->assertRedirect();

    return PurchaseRequisition::query()->latest('id')->firstOrFail();
}

function makePurchaseOrder(object $test, float $qty, float $rate, ?int $prLineId = null): PurchaseOrder
{
    $test->actingAs($test->buyer)->post('/purchase-orders', [
        'supplier_id' => $test->supplier->id,
        'factory_unit_id' => $test->unit->id,
        'order_date' => now()->toDateString(),
        'expected_date' => now()->addDays(10)->toDateString(),
        'currency_id' => $test->supplier->currency_id ?? DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'freight_amount' => 0,
        'lines' => [[
            'item_id' => $test->item->id,
            'uom_id' => $test->item->base_uom_id,
            'qty' => $qty,
            'rate' => $rate,
            'pr_line_id' => $prLineId,
        ]],
    ])->assertRedirect();

    return PurchaseOrder::query()->latest('id')->firstOrFail();
}

// --- Requisitions -----------------------------------------------------------------------

it('raises a requisition as a draft with its lines', function (): void {
    $requisition = makeRequisition($this);

    expect($requisition->status)->toBe('draft')
        ->and($requisition->origin)->toBe('manual');

    $line = DB::table('purchase_requisition_lines')->where('pr_id', $requisition->id)->first();

    expect((float) $line->qty)->toBe(500.0)
        ->and((float) $line->ordered_qty)->toBe(0.0)
        ->and($line->line_no)->toBe(1);
});

it('numbers a requisition only when it is submitted (BR-34)', function (): void {
    $requisition = makeRequisition($this);

    expect($requisition->number)->toBeNull();

    $this->actingAs($this->planner)
        ->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'submitted'])
        ->assertRedirect();

    expect($requisition->fresh()->number)->not->toBeNull();
});

it('refuses to edit a requisition once it has left draft', function (): void {
    $requisition = makeRequisition($this);

    $this->actingAs($this->planner)->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'submitted']);

    $this->actingAs($this->planner)->put("/purchase-requisitions/{$requisition->id}", [
        'factory_unit_id' => $this->unit->id,
        'requested_on' => now()->toDateString(),
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 9999,
        ]],
    ])->assertRedirect();

    expect((float) DB::table('purchase_requisition_lines')->where('pr_id', $requisition->id)->value('qty'))
        ->toBe(500.0);
});

// --- Purchase orders --------------------------------------------------------------------

it('totals a purchase order from its lines', function (): void {
    $order = makePurchaseOrder($this, qty: 200, rate: 12.5);

    expect((float) $order->subtotal)->toBe(2500.0)
        ->and((float) $order->total)->toBe(2500.0)
        ->and($order->status)->toBe('draft');
});

it('records how much of a requisition line an order covers', function (): void {
    $requisition = makeRequisition($this, qty: 500);

    $this->actingAs($this->planner)->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'submitted']);
    $this->actingAs($this->purchaseManager)->post("/purchase-requisitions/{$requisition->id}/transition", ['to' => 'approved']);

    $prLineId = (int) DB::table('purchase_requisition_lines')->where('pr_id', $requisition->id)->value('id');

    makePurchaseOrder($this, qty: 300, rate: 10, prLineId: $prLineId);

    // Partly covered demand has to stay visible to the next buyer.
    expect((float) DB::table('purchase_requisition_lines')->where('id', $prLineId)->value('ordered_qty'))
        ->toBe(300.0);
});

it('will not submit an order to a supplier nobody has approved', function (): void {
    $unapproved = Supplier::query()->where('is_approved', false)->first();

    if ($unapproved === null) {
        $unapproved = Supplier::query()->firstOrFail()->replicate();
        $unapproved->code = 'SUP-UNAPP';
        $unapproved->name = 'Unapproved Trading';
        $unapproved->is_approved = false;
        $unapproved->save();
    }

    $this->actingAs($this->buyer)->post('/purchase-orders', [
        'supplier_id' => $unapproved->id,
        'factory_unit_id' => $this->unit->id,
        'order_date' => now()->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'freight_amount' => 0,
        'lines' => [[
            'item_id' => $this->item->id,
            'uom_id' => $this->item->base_uom_id,
            'qty' => 10,
            'rate' => 5,
        ]],
    ]);

    $order = PurchaseOrder::query()->latest('id')->firstOrFail();

    $this->actingAs($this->buyer)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'pending_approval'])
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('draft');
});

it('routes approval by value band — a purchase manager cannot sign above it (06-rbac §5)', function (): void {
    $band = (float) DB::table('settings')->where('key', 'po_approval_band_manager')->value('value');

    $order = makePurchaseOrder($this, qty: 1, rate: $band * 2);

    $this->actingAs($this->buyer)->post("/purchase-orders/{$order->id}/transition", ['to' => 'pending_approval']);

    $this->actingAs($this->purchaseManager)
        ->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved'])
        ->assertSessionHas('error');

    expect($order->fresh()->status)->toBe('pending_approval');

    // The MD's band has no ceiling.
    $this->actingAs($this->md)->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved']);

    expect($order->fresh()->status)->toBe('approved')
        ->and($order->fresh()->approved_by)->toBe($this->md->id);
});

it('lets a purchase manager approve inside the band', function (): void {
    $band = (float) DB::table('settings')->where('key', 'po_approval_band_manager')->value('value');

    $order = makePurchaseOrder($this, qty: 1, rate: $band / 4);

    $this->actingAs($this->buyer)->post("/purchase-orders/{$order->id}/transition", ['to' => 'pending_approval']);
    $this->actingAs($this->purchaseManager)->post("/purchase-orders/{$order->id}/transition", ['to' => 'approved']);

    expect($order->fresh()->status)->toBe('approved');
});

// --- Routings ---------------------------------------------------------------------------

it('creates a routing and numbers its operations by row order (J2)', function (): void {
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail())
        ->post('/routings', [
            'code' => 'RT-TEST-01',
            'name' => 'Test woven routing',
            'product_type' => 'woven',
            'is_default' => false,
            'is_active' => true,
            'operations' => [
                ['code' => 'WEAVE', 'name' => 'Weaving', 'wastage_pct' => 3, 'consumes_web' => true, 'setup_minutes' => 45],
                ['code' => 'CUTFOLD', 'name' => 'Cut & fold', 'wastage_pct' => 2, 'consumes_web' => true, 'setup_minutes' => 20],
                // Packing never touches the web, so its wastage must not join the BR-8 total.
                ['code' => 'PACK', 'name' => 'Packing', 'wastage_pct' => 5, 'consumes_web' => false, 'setup_minutes' => 0],
            ],
        ])->assertRedirect();

    $routing = Routing::query()->where('code', 'RT-TEST-01')->firstOrFail();

    $sequences = DB::table('routing_operations')->where('routing_id', $routing->id)
        ->orderBy('sequence_no')->pluck('code')->all();

    expect($sequences)->toBe(['WEAVE', 'CUTFOLD', 'PACK'])
        ->and($routing->totalWastagePct())->toBe(5.0);
});

it('refuses to retire a routing that products still use', function (): void {
    $routing = Routing::query()
        ->whereIn('id', DB::table('products')->whereNotNull('routing_id')->pluck('routing_id'))
        ->firstOrFail();

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail())
        ->delete("/routings/{$routing->id}")
        ->assertSessionHas('error');

    expect($routing->fresh()->is_active)->toBeTrue();
});

// --- Artwork ----------------------------------------------------------------------------

it('edits an artwork record without touching its versions (A1)', function (): void {
    $designer = User::query()->where('email', 'designer@maheenlabel.test')->firstOrFail();
    $product = DB::table('products')->first();

    $this->actingAs($designer)->post('/artworks', [
        'product_id' => $product->id,
        'code' => 'AW-EDIT-01',
        'title' => 'Before',
    ]);

    $artwork = DB::table('artworks')->where('code', 'AW-EDIT-01')->first();

    $this->actingAs($designer)->put("/artworks/{$artwork->id}", [
        'code' => 'AW-EDIT-02',
        'title' => 'After',
    ])->assertRedirect();

    $updated = DB::table('artworks')->where('id', $artwork->id)->first();

    expect($updated->title)->toBe('After')
        ->and($updated->code)->toBe('AW-EDIT-02');
});

it('locks an artwork code once a version references it', function (): void {
    $artwork = DB::table('artworks')
        ->whereIn('id', DB::table('artwork_versions')->pluck('artwork_id'))
        ->first();

    $this->actingAs(User::query()->where('email', 'designer@maheenlabel.test')->firstOrFail())
        ->put("/artworks/{$artwork->id}", ['code' => 'AW-RENAMED', 'title' => 'Retitled'])
        ->assertRedirect();

    $updated = DB::table('artworks')->where('id', $artwork->id)->first();

    // The title moves; the code other people already hold does not.
    expect($updated->title)->toBe('Retitled')
        ->and($updated->code)->toBe($artwork->code);
});

it('creates artwork against a product from the index dialog', function (): void {
    $product = DB::table('products')->first();

    $this->actingAs(User::query()->where('email', 'designer@maheenlabel.test')->firstOrFail())
        ->post('/artworks', [
            'product_id' => $product->id,
            'code' => 'AW-TEST-01',
            'title' => 'Test care label artwork',
        ])->assertRedirect();

    expect(DB::table('artworks')->where('code', 'AW-TEST-01')->exists())->toBeTrue();
});
