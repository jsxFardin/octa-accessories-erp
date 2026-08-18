<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockAvailability;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * P2-6 / IN-4 — warehouse transfers. Drafts write no stock; dispatch and receive post
 * through StockPostingService into child lots. Conversation label only — the SRS story is IN-4.
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $this->manager = User::query()->where('email', 'storemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();
});

function p26Lot(): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>', 20)
        ->orderBy('id')
        ->firstOrFail();
}

function p26SiblingLot(StockLot $lot): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', $lot->warehouse_id)
        ->where('id', '!=', $lot->id)
        ->where('balance_qty', '>', 5)
        ->orderBy('id')
        ->firstOrFail();
}

function p26DestWarehouse(StockLot $lot): object
{
    $dest = DB::table('warehouses')
        ->where('is_active', true)
        ->where('kind', '!=', 'transit')
        ->where('is_nettable', true)
        ->where('id', '!=', $lot->warehouse_id)
        ->orderBy('id')
        ->first();

    expect($dest)->not->toBeNull();

    return $dest;
}

function p26TransitWarehouse(): object
{
    return DB::table('warehouses')->where('code', 'TRANSIT')->firstOrFail();
}

/** @param list<array{lot_id: int, qty: float|int|string}>|null $lines */
function p26Draft(object $test, StockLot $lot, float $qty = 1, array $overrides = []): StockTransfer
{
    $test->actingAs($test->keeper);
    $dest = $overrides['to_warehouse_id'] ?? p26DestWarehouse($lot)->id;

    $test->post('/stock-transfers', [
        'from_warehouse_id' => $overrides['from_warehouse_id'] ?? $lot->warehouse_id,
        'to_warehouse_id' => $dest,
        'transfer_date' => $overrides['transfer_date'] ?? now()->toDateString(),
        'remarks' => $overrides['remarks'] ?? 'Move yarn to the other store.',
        'status' => $overrides['status'] ?? 'received',
        'number' => $overrides['number'] ?? 'STR-FORGED',
        'created_by' => $overrides['created_by'] ?? $test->md->id,
        'transit_lot_id' => $overrides['transit_lot_id'] ?? 999,
        'destination_lot_id' => $overrides['destination_lot_id'] ?? 999,
        'lines' => $overrides['lines'] ?? [[
            'lot_id' => $lot->id,
            'qty' => $qty,
            'transit_lot_id' => 111,
            'destination_lot_id' => 222,
            'received_qty' => 0,
        ]],
    ])->assertSessionHasNoErrors();

    return StockTransfer::query()->orderByDesc('id')->firstOrFail();
}

function p26Dispatch(object $test, StockTransfer $transfer, ?User $user = null): StockTransfer
{
    $test->actingAs($user ?? $test->keeper);
    $test->post("/stock-transfers/{$transfer->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('success');

    return $transfer->refresh();
}

function p26Receive(object $test, StockTransfer $transfer, ?User $user = null): StockTransfer
{
    $test->actingAs($user ?? $test->keeper);
    $test->post("/stock-transfers/{$transfer->id}/transition", ['to' => 'received'])
        ->assertSessionHas('success');

    return $transfer->refresh();
}

function p26Ledger(StockTransfer $transfer)
{
    return DB::table('stock_ledger')
        ->where('source_type', StockTransfer::class)
        ->where('source_id', $transfer->id);
}

function p26ReconcileLot(int $lotId): void
{
    $cached = (float) DB::table('stock_lots')->where('id', $lotId)->value('balance_qty');
    $ledger = (float) DB::table('stock_ledger')->where('lot_id', $lotId)->sum('qty');
    $summary = (float) DB::table('stock_balances')->where('lot_id', $lotId)->value('balance_qty');
    $view = (float) DB::table('v_stock_balances')->where('lot_id', $lotId)->value('balance_qty');

    expect($cached)->toBeQty($ledger)
        ->and($summary)->toBeQty($ledger)
        ->and($view)->toBeQty($ledger);
}

function p26Reserve(StockLot $lot, float $qty): void
{
    DB::table('stock_reservations')->insert([
        'lot_id' => $lot->id,
        'item_id' => $lot->item_id,
        'warehouse_id' => $lot->warehouse_id,
        'job_card_id' => DB::table('job_cards')->value('id'),
        'qty' => $qty,
        'status' => 'active',
    ]);
}

function p26Children(StockTransfer $transfer, int $sourceLotId, int $warehouseId)
{
    $lotIds = DB::table('stock_ledger')
        ->where('source_type', StockTransfer::class)
        ->where('source_id', $transfer->id)
        ->where('warehouse_id', $warehouseId)
        ->where('movement_type', 'transfer_in')
        ->pluck('lot_id');

    return StockLot::query()
        ->whereIn('id', $lotIds)
        ->where('parent_lot_id', $sourceLotId)
        ->where('warehouse_id', $warehouseId)
        ->get();
}

it('refuses the index to operators and drivers', function (): void {
    $this->actingAs($this->operator)->get('/stock-transfers')->assertForbidden();
    $this->actingAs($this->driver)->get('/stock-transfers')->assertForbidden();
});

it('lets keepers and managers create, and refuses MD, operators and drivers', function (): void {
    $lot = p26Lot();
    $dest = p26DestWarehouse($lot);

    $this->actingAs($this->operator)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->driver)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->md)->get('/stock-transfers/create')->assertForbidden();
    $this->actingAs($this->md)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->keeper)->get('/stock-transfers/create')->assertOk();
    $this->actingAs($this->manager)->get('/stock-transfers/create')->assertOk();
    $this->actingAs($this->manager)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertSessionHasNoErrors();

    expect(p26Draft($this, $lot)->status)->toBe(StockTransfer::DRAFT);
});

it('creates a draft that writes no stock and ignores mass-assigned status, number and child lot ids', function (): void {
    $lot = p26Lot();
    $ledgerBefore = DB::table('stock_ledger')->count();
    $lotsBefore = DB::table('stock_lots')->count();
    $balanceBefore = (float) $lot->balance_qty;

    $transfer = p26Draft($this, $lot, 5);

    expect($transfer->status)->toBe(StockTransfer::DRAFT)
        ->and($transfer->number)->toBeNull()
        ->and((int) $transfer->created_by)->toBe((int) $this->keeper->id)
        ->and($transfer->remarks)->toBe('Move yarn to the other store.')
        ->and((float) $transfer->lines()->first()->received_qty)->toBeQty(0.0)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceBefore);

    $this->actingAs($this->keeper)->get('/stock-transfers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Transfers/Index'));
});

it('updates a draft and refuses dispatched mutation', function (): void {
    $lot = p26Lot();
    $transfer = p26Draft($this, $lot, 2);

    $this->actingAs($this->keeper)->put("/stock-transfers/{$transfer->id}", [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $transfer->to_warehouse_id,
        'transfer_date' => now()->toDateString(),
        'remarks' => 'Revised quantity.',
        'lines' => [['lot_id' => $lot->id, 'qty' => 3]],
    ])->assertSessionHasNoErrors();

    expect($transfer->refresh()->remarks)->toBe('Revised quantity.')
        ->and((float) $transfer->lines()->first()->qty)->toBeQty(3.0);

    $dispatched = p26Dispatch($this, $transfer);
    $this->actingAs($this->keeper)->put("/stock-transfers/{$dispatched->id}", [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dispatched->to_warehouse_id,
        'transfer_date' => now()->toDateString(),
        'remarks' => 'should not stick',
        'lines' => [['lot_id' => $lot->id, 'qty' => 9]],
    ])->assertSessionHas('error');

    expect($dispatched->refresh()->remarks)->toBe('Revised quantity.');
});

it('rejects same warehouse, transit endpoints, duplicate lots, zero qty and cross-warehouse lots', function (): void {
    $lot = p26Lot();
    $dest = p26DestWarehouse($lot);
    $transit = p26TransitWarehouse();
    $foreign = StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', '!=', $lot->warehouse_id)
        ->where('balance_qty', '>', 0)
        ->firstOrFail();

    $this->actingAs($this->keeper);

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $lot->warehouse_id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertSessionHasErrors('from_warehouse_id');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $transit->id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertSessionHasErrors('from_warehouse_id');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $transit->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertSessionHasErrors('to_warehouse_id');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [
            ['lot_id' => $lot->id, 'qty' => 1],
            ['lot_id' => $lot->id, 'qty' => 2],
        ],
    ])->assertSessionHasErrors('lines.1.lot_id');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 0]],
    ])->assertSessionHasErrors('lines.0.qty');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => -1]],
    ])->assertSessionHasErrors('lines.0.qty');

    $this->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $foreign->id, 'qty' => 1]],
    ])->assertSessionHasErrors('lines.0.lot_id');
});

it('refuses dispatch when more than one active transit warehouse exists', function (): void {
    $lot = p26Lot();
    $unitId = DB::table('warehouses')->value('factory_unit_id');

    DB::table('warehouses')->insert([
        'factory_unit_id' => $unitId,
        'code' => 'TRANSIT2',
        'name' => 'Second transit',
        'kind' => 'transit',
        'is_nettable' => false,
        'is_active' => true,
    ]);

    $this->actingAs($this->keeper)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => p26DestWarehouse($lot)->id,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $lot->id, 'qty' => 1]],
    ])->assertSessionHasErrors('from_warehouse_id');
});

it('protects reserved quantity and will not transfer more than free', function (): void {
    $lot = p26Lot();
    $before = (float) $lot->balance_qty;
    $reserved = round($before / 2, 6);
    $free = $before - $reserved;
    p26Reserve($lot, $reserved);

    $blocked = p26Draft($this, $lot, $free + 0.5);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$blocked->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('error');

    expect($blocked->refresh()->status)->toBe(StockTransfer::DRAFT)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before);

    $ok = p26Dispatch($this, p26Draft($this, $lot, $free));

    expect($ok->status)->toBe(StockTransfer::IN_TRANSIT)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($reserved);

    p26ReconcileLot((int) $lot->id);
});

it('dispatches into a transit child lot and receives into a destination child lot', function (): void {
    $lot = p26Lot();
    $itemId = (int) $lot->item_id;
    $avgBefore = (float) DB::table('items')->where('id', $itemId)->value('avg_rate');
    $sourceWarehouse = (int) $lot->warehouse_id;
    $before = (float) $lot->balance_qty;
    $onHandBefore = app(StockAvailability::class)->onHand($itemId);
    $qty = 4.0;

    $draft = p26Draft($this, $lot, $qty);
    expect($draft->number)->toBeNull();

    $dispatched = p26Dispatch($this, $draft);
    $transit = p26TransitWarehouse();
    $children = p26Children($dispatched, (int) $lot->id, (int) $transit->id);

    expect($dispatched->status)->toBe(StockTransfer::IN_TRANSIT)
        ->and($dispatched->number)->toStartWith('STR')
        ->and($children)->toHaveCount(1)
        ->and((int) $children->first()->parent_lot_id)->toBe((int) $lot->id)
        ->and($children->first()->bin_id)->toBeNull()
        ->and($children->first()->barcode)->toBeNull()
        ->and((string) $children->first()->status)->toBe('available')
        ->and((float) $children->first()->unit_cost)->toBe((float) $lot->unit_cost)
        ->and((float) $children->first()->balance_qty)->toBeQty($qty)
        ->and((int) $lot->refresh()->warehouse_id)->toBe($sourceWarehouse)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before - $qty);

    $movements = p26Ledger($dispatched)->orderBy('id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements[0]->movement_type)->toBe('transfer_out')
        ->and((float) $movements[0]->qty)->toBeQty(-$qty)
        ->and((int) $movements[0]->lot_id)->toBe((int) $lot->id)
        ->and($movements[1]->movement_type)->toBe('transfer_in')
        ->and((float) $movements[1]->qty)->toBeQty($qty)
        ->and((int) $movements[1]->lot_id)->toBe((int) $children->first()->id)
        ->and((int) $movements[1]->warehouse_id)->toBe((int) $transit->id)
        ->and($movements[0]->source_type)->toBe(StockTransfer::class)
        ->and((int) $movements[0]->source_id)->toBe((int) $dispatched->id);

    $onHandInTransit = app(StockAvailability::class)->onHand($itemId);
    expect($onHandInTransit)->toBeQty($onHandBefore - $qty);

    p26ReconcileLot((int) $lot->id);
    p26ReconcileLot((int) $children->first()->id);

    $this->actingAs($this->keeper)->get("/stock-transfers/{$dispatched->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Transfers/Show')
            ->where('lines.0.transit_lot_no', $children->first()->lot_no));

    $received = p26Receive($this, $dispatched, $this->manager);
    $destChildren = p26Children($received, (int) $lot->id, (int) $received->to_warehouse_id);
    $transitAfter = $children->first()->refresh();

    expect($received->status)->toBe(StockTransfer::RECEIVED)
        ->and((float) $received->lines()->first()->received_qty)->toBeQty($qty)
        ->and($destChildren)->toHaveCount(1)
        ->and((int) $destChildren->first()->parent_lot_id)->toBe((int) $lot->id)
        ->and((int) $destChildren->first()->warehouse_id)->toBe((int) $received->to_warehouse_id)
        ->and($destChildren->first()->bin_id)->toBeNull()
        ->and((float) $destChildren->first()->balance_qty)->toBeQty($qty)
        ->and((float) $transitAfter->balance_qty)->toBeQty(0.0)
        ->and((int) $lot->refresh()->warehouse_id)->toBe($sourceWarehouse);

    $all = p26Ledger($received)->orderBy('id')->pluck('movement_type')->all();
    expect($all)->toBe(['transfer_out', 'transfer_in', 'transfer_out', 'transfer_in']);

    expect((float) DB::table('items')->where('id', $itemId)->value('avg_rate'))->toBe($avgBefore);
    expect(app(StockAvailability::class)->onHand($itemId))->toBeQty($onHandBefore);

    p26ReconcileLot((int) $lot->id);
    p26ReconcileLot((int) $transitAfter->id);
    p26ReconcileLot((int) $destChildren->first()->id);
});

it('rejects a partial receive and leaves the transfer in transit', function (): void {
    $lot = p26Lot();
    $dispatched = p26Dispatch($this, p26Draft($this, $lot, 5));
    $ledgerBefore = p26Ledger($dispatched)->count();
    $lotsBefore = DB::table('stock_lots')->count();

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$dispatched->id}/transition", [
            'to' => 'received',
            'received_qty' => 4,
        ])
        ->assertSessionHas('error');

    expect($dispatched->refresh()->status)->toBe(StockTransfer::IN_TRANSIT)
        ->and((float) $dispatched->lines()->first()->received_qty)->toBeQty(0.0)
        ->and(p26Ledger($dispatched)->count())->toBe($ledgerBefore)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore);
});

it('treats same-state transitions as no-ops and refuses illegal moves', function (): void {
    $lot = p26Lot();
    $draft = p26Draft($this, $lot, 1);

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$draft->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe(StockTransfer::CANCELLED)
        ->and(p26Ledger($draft)->count())->toBe(0);

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$draft->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('error');
    expect($draft->refresh()->status)->toBe(StockTransfer::CANCELLED);

    $dispatched = p26Dispatch($this, p26Draft($this, $lot, 1));
    $ledger = p26Ledger($dispatched)->count();

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$dispatched->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHasNoErrors();
    expect($dispatched->refresh()->status)->toBe(StockTransfer::IN_TRANSIT)
        ->and(p26Ledger($dispatched)->count())->toBe($ledger);

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$dispatched->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('error');
    expect($dispatched->refresh()->status)->toBe(StockTransfer::IN_TRANSIT);

    $received = p26Receive($this, $dispatched);
    $ledgerAfter = p26Ledger($received)->count();

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$received->id}/transition", ['to' => 'received'])
        ->assertSessionHasNoErrors();
    expect($received->refresh()->status)->toBe(StockTransfer::RECEIVED)
        ->and(p26Ledger($received)->count())->toBe($ledgerAfter);

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$received->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('error');
    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$received->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('error');
});

it('lets the manager post and refuses MD posting', function (): void {
    $lot = p26Lot();
    $draft = p26Draft($this, $lot, 1);

    $this->actingAs($this->md)
        ->post("/stock-transfers/{$draft->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('error');
    expect($draft->refresh()->status)->toBe(StockTransfer::DRAFT)
        ->and(p26Ledger($draft)->count())->toBe(0);

    $dispatched = p26Dispatch($this, $draft, $this->manager);
    expect($dispatched->status)->toBe(StockTransfer::IN_TRANSIT);

    $this->actingAs($this->md)
        ->post("/stock-transfers/{$dispatched->id}/transition", ['to' => 'received'])
        ->assertSessionHas('error');
    expect($dispatched->refresh()->status)->toBe(StockTransfer::IN_TRANSIT);

    expect(p26Receive($this, $dispatched, $this->manager)->status)->toBe(StockTransfer::RECEIVED);
});

it('rolls back the whole dispatch when a later line is impossible', function (): void {
    $lot = p26Lot();
    $sibling = p26SiblingLot($lot);
    $transfer = p26Draft($this, $lot, overrides: [
        'lines' => [
            ['lot_id' => $lot->id, 'qty' => 2],
            ['lot_id' => $sibling->id, 'qty' => 1],
        ],
    ]);

    DB::table('stock_transfer_lines')
        ->where('stock_transfer_id', $transfer->id)
        ->where('lot_id', $sibling->id)
        ->update(['qty' => ((float) $sibling->balance_qty) + 50]);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $lotsBefore = DB::table('stock_lots')->count();
    $balanceA = (float) $lot->balance_qty;
    $balanceB = (float) $sibling->balance_qty;

    $this->actingAs($this->keeper)
        ->post("/stock-transfers/{$transfer->id}/transition", ['to' => 'in_transit'])
        ->assertSessionHas('error');

    expect($transfer->refresh()->status)->toBe(StockTransfer::DRAFT)
        ->and($transfer->number)->toBeNull()
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceA)
        ->and((float) $sibling->refresh()->balance_qty)->toBeQty($balanceB);
});

it('audits creation, dispatch and receive', function (): void {
    $lot = p26Lot();
    $received = p26Receive($this, p26Dispatch($this, p26Draft($this, $lot, 1)));

    $events = AuditLog::query()
        ->where('auditable_type', StockTransfer::class)
        ->where('auditable_id', $received->id)
        ->pluck('event');

    expect($events)->toContain('created')
        ->and($events->filter(fn ($event): bool => $event === 'status_changed')->count())->toBeGreaterThanOrEqual(2);

    $statuses = AuditLog::query()
        ->where('auditable_type', StockTransfer::class)
        ->where('auditable_id', $received->id)
        ->where('event', 'status_changed')
        ->get()
        ->map(fn (AuditLog $row) => $row->new_values['status'] ?? null)
        ->all();

    expect($statuses)->toContain(StockTransfer::IN_TRANSIT)
        ->and($statuses)->toContain(StockTransfer::RECEIVED);
});

it('refuses a transition from a user who cannot see the document', function (): void {
    $transfer = p26Draft($this, p26Lot(), 1);

    $this->actingAs($this->operator)
        ->get("/stock-transfers/{$transfer->id}")
        ->assertForbidden();
    $this->actingAs($this->operator)
        ->post("/stock-transfers/{$transfer->id}/transition", ['to' => 'in_transit'])
        ->assertForbidden();
    $this->actingAs($this->driver)
        ->post("/stock-transfers/{$transfer->id}/transition", ['to' => 'in_transit'])
        ->assertForbidden();

    $this->actingAs($this->keeper)->get('/stock-transfers/999999999')->assertNotFound();

    expect($transfer->refresh()->status)->toBe(StockTransfer::DRAFT);
});
