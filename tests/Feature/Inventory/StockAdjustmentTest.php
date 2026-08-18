<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLot;
use App\Support\Audit\AuditLog;
use App\Support\Platform\WorkQueue;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * P2-5 / IN-5 — stock adjustments. Drafts write no stock; posting is the approval effect
 * and the only step that calls StockPostingService.
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $this->manager = User::query()->where('email', 'storemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();
});

function p25Lot(): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>', 20)
        ->orderBy('id')
        ->firstOrFail();
}

function p25OtherWarehouseLot(StockLot $lot): StockLot
{
    $other = StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', '!=', $lot->warehouse_id)
        ->where('balance_qty', '>', 0)
        ->first();

    expect($other)->not->toBeNull();

    return $other;
}

function p25SiblingLot(StockLot $lot): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', $lot->warehouse_id)
        ->where('id', '!=', $lot->id)
        ->where('balance_qty', '>', 5)
        ->orderBy('id')
        ->firstOrFail();
}

/** @param list<array{lot_id: int, qty_delta: float|int|string, remarks?: string|null}>|null $lines */
function p25Draft(object $test, StockLot $lot, float $qty = 1, array $overrides = []): StockAdjustment
{
    $test->actingAs($test->keeper);

    $test->post('/stock-adjustments', [
        'warehouse_id' => $overrides['warehouse_id'] ?? $lot->warehouse_id,
        'reason' => $overrides['reason'] ?? 'Cycle count variance on the roll.',
        'status' => $overrides['status'] ?? 'posted',
        'approved_by' => $overrides['approved_by'] ?? $test->md->id,
        'lines' => $overrides['lines'] ?? [[
            'lot_id' => $lot->id,
            'qty_delta' => $qty,
            'remarks' => $overrides['remarks'] ?? 'counted',
        ]],
    ])->assertSessionHasNoErrors();

    return StockAdjustment::query()->orderByDesc('id')->firstOrFail();
}

function p25Submit(object $test, StockAdjustment $adjustment): StockAdjustment
{
    $test->actingAs($test->keeper);
    $test->post("/stock-adjustments/{$adjustment->id}/transition", ['to' => 'pending_approval'])
        ->assertSessionHas('success');

    return $adjustment->refresh();
}

function p25PostAs(object $test, User $user, StockAdjustment $adjustment): StockAdjustment
{
    $test->actingAs($user);
    $test->post("/stock-adjustments/{$adjustment->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('success');

    return $adjustment->refresh();
}

function p25Ledger(StockAdjustment $adjustment)
{
    return DB::table('stock_ledger')
        ->where('source_type', StockAdjustment::class)
        ->where('source_id', $adjustment->id);
}

function p25ReconcileLot(int $lotId): void
{
    $cached = (float) DB::table('stock_lots')->where('id', $lotId)->value('balance_qty');
    $ledger = (float) DB::table('stock_ledger')->where('lot_id', $lotId)->sum('qty');
    $summary = (float) DB::table('stock_balances')->where('lot_id', $lotId)->value('balance_qty');
    $view = (float) DB::table('v_stock_balances')->where('lot_id', $lotId)->value('balance_qty');

    expect($cached)->toBeQty($ledger)
        ->and($summary)->toBeQty($ledger)
        ->and($view)->toBeQty($ledger);
}

function p25Cost(StockLot $lot, float $unitCost): StockLot
{
    $lot->forceFill(['unit_cost' => $unitCost])->save();

    return $lot->refresh();
}

function p25Reserve(StockLot $lot, float $qty): void
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

it('refuses the index to operators and drivers', function (): void {
    $this->actingAs($this->operator)->get('/stock-adjustments')->assertForbidden();
    $this->actingAs($this->driver)->get('/stock-adjustments')->assertForbidden();
});

it('lets keepers and managers create, and refuses MD, operators and drivers', function (): void {
    $lot = p25Lot();

    $this->actingAs($this->operator)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'no',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->md)->get('/stock-adjustments/create')->assertForbidden();
    $this->actingAs($this->md)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'MD must not enter adjustments.',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
    ])->assertForbidden();

    $this->actingAs($this->keeper)->get('/stock-adjustments/create')->assertOk();
    $this->actingAs($this->manager)->get('/stock-adjustments/create')->assertOk();
    $this->actingAs($this->manager)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'Manager-raised correction.',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
    ])->assertSessionHasNoErrors();

    expect(p25Draft($this, $lot)->status)->toBe(StockAdjustment::DRAFT);
});

it('creates a draft that writes no stock and ignores mass-assigned status', function (): void {
    $lot = p25Lot();
    $ledgerBefore = DB::table('stock_ledger')->count();
    $balanceBefore = (float) $lot->balance_qty;

    $adjustment = p25Draft($this, $lot, 5);

    expect($adjustment->status)->toBe(StockAdjustment::DRAFT)
        ->and($adjustment->number)->toBeNull()
        ->and($adjustment->approved_by)->toBeNull()
        ->and((int) $adjustment->created_by)->toBe((int) $this->keeper->id)
        ->and($adjustment->reason)->toBe('Cycle count variance on the roll.')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceBefore);

    $this->actingAs($this->keeper)->get('/stock-adjustments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Adjustments/Index'));
});

it('updates a draft and refuses posted mutation', function (): void {
    $lot = p25Lot();
    $adjustment = p25Draft($this, $lot, 2);

    $this->actingAs($this->keeper)->put("/stock-adjustments/{$adjustment->id}", [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'Recounted after shade check.',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 3, 'remarks' => 'shade']],
    ])->assertSessionHasNoErrors();

    expect($adjustment->refresh()->reason)->toBe('Recounted after shade check.')
        ->and((float) $adjustment->lines()->first()->qty_delta)->toBeQty(3.0);

    $submitted = p25Submit($this, $adjustment);
    $this->actingAs($this->keeper)->put("/stock-adjustments/{$submitted->id}", [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'should not stick',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 9]],
    ])->assertSessionHas('error');

    expect($submitted->refresh()->reason)->toBe('Recounted after shade check.');
});

it('rejects missing reason, empty lines, zero qty, unknown lots and cross-warehouse lots', function (): void {
    $lot = p25Lot();
    $foreign = p25OtherWarehouseLot($lot);

    $this->actingAs($this->keeper);

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => '',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
    ])->assertSessionHasErrors('reason');

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'no lines',
        'lines' => [],
    ])->assertSessionHasErrors('lines');

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'zero',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 0]],
    ])->assertSessionHasErrors('lines.0.qty_delta');

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'ghost lot',
        'lines' => [['lot_id' => 9_999_999, 'qty_delta' => 1]],
    ])->assertSessionHasErrors('lines.0.lot_id');

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'wrong warehouse',
        'lines' => [['lot_id' => $foreign->id, 'qty_delta' => 1]],
    ])->assertSessionHasErrors('lines.0.lot_id');

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'injection',
        'lines' => [['lot_id' => '1 OR 1=1', 'qty_delta' => 1]],
    ])->assertSessionHasErrors('lines.0.lot_id');

    expect(DB::table('stock_lots')->count())->toBeGreaterThan(0);
});

it('rejects forbidden lot statuses and a positive adjustment into a blocked lot', function (): void {
    $lot = p25Lot();
    $this->actingAs($this->keeper);

    foreach (['quarantine', 'consumed', 'expired', 'scrapped'] as $status) {
        $lot->forceFill(['status' => $status])->save();

        $this->post('/stock-adjustments', [
            'warehouse_id' => $lot->warehouse_id,
            'reason' => "into {$status}",
            'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
        ])->assertSessionHasErrors('lines.0.lot_id');
    }

    $lot->forceFill(['status' => 'blocked'])->save();

    $this->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'positive blocked',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 1]],
    ])->assertSessionHasErrors('lines.0.lot_id');
});

it('numbers a submitted adjustment without writing stock', function (): void {
    $lot = p25Lot();
    $ledgerBefore = DB::table('stock_ledger')->count();
    $adjustment = p25Submit($this, p25Draft($this, $lot, 4));

    expect($adjustment->status)->toBe(StockAdjustment::PENDING_APPROVAL)
        ->and($adjustment->number)->toStartWith('ADJ')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('posts a positive adjustment as adjustment_in and reconciles balances', function (): void {
    $lot = p25Lot();
    $before = (float) $lot->balance_qty;
    $adjustment = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 5)));

    $entry = p25Ledger($adjustment)->first();

    expect($adjustment->status)->toBe(StockAdjustment::POSTED)
        ->and((int) $adjustment->approved_by)->toBe((int) $this->manager->id)
        ->and($entry->movement_type)->toBe('adjustment_in')
        ->and((float) $entry->qty)->toBeQty(5.0)
        ->and((float) $entry->unit_cost)->toBe((float) $lot->refresh()->unit_cost)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before + 5);

    p25ReconcileLot((int) $lot->id);
});

it('posts a negative adjustment as adjustment_out and will not exceed balance', function (): void {
    $lot = p25Lot();
    $before = (float) $lot->balance_qty;

    $this->actingAs($this->keeper)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'too much',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => -($before + 1)]],
    ])->assertSessionHasErrors('lines.0.qty_delta');

    $adjustment = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, -3)));
    $entry = p25Ledger($adjustment)->first();

    expect($entry->movement_type)->toBe('adjustment_out')
        ->and((float) $entry->qty)->toBeQty(-3.0)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before - 3);

    p25ReconcileLot((int) $lot->id);
});

it('protects reserved quantity and allows a write-off only of the free remainder', function (): void {
    $lot = p25Cost(p25Lot(), 1);
    $before = (float) $lot->balance_qty;
    $reserved = round($before / 2, 6);
    $free = $before - $reserved;
    p25Reserve($lot, $reserved);

    $blocked = p25Submit($this, p25Draft($this, $lot, -($free + 0.5)));
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$blocked->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect($blocked->refresh()->status)->toBe(StockAdjustment::PENDING_APPROVAL)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before);

    $ok = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, -$free)));

    expect($ok->status)->toBe(StockAdjustment::POSTED)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($reserved);

    p25ReconcileLot((int) $lot->id);
});

it('allows a negative adjustment on a blocked lot and still posts through the service', function (): void {
    $lot = p25Lot();
    $lot->forceFill(['status' => 'blocked'])->save();
    $before = (float) $lot->balance_qty;

    $adjustment = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, -1)));

    expect($adjustment->status)->toBe(StockAdjustment::POSTED)
        ->and(p25Ledger($adjustment)->value('movement_type'))->toBe('adjustment_out')
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before - 1);

    p25ReconcileLot((int) $lot->id);
});

it('posts multiple lines in one warehouse and rejects a mixed-warehouse document', function (): void {
    $lot = p25Lot();
    $sibling = p25SiblingLot($lot);
    $foreign = p25OtherWarehouseLot($lot);

    $this->actingAs($this->keeper)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'two warehouses',
        'lines' => [
            ['lot_id' => $lot->id, 'qty_delta' => 1],
            ['lot_id' => $foreign->id, 'qty_delta' => 1],
        ],
    ])->assertSessionHasErrors('lines.1.lot_id');

    $beforeA = (float) $lot->balance_qty;
    $beforeB = (float) $sibling->balance_qty;

    $adjustment = p25Draft($this, $lot, overrides: [
        'lines' => [
            ['lot_id' => $lot->id, 'qty_delta' => 2],
            ['lot_id' => $sibling->id, 'qty_delta' => -1],
            ['lot_id' => $lot->id, 'qty_delta' => 1],
        ],
    ]);
    $posted = p25PostAs($this, $this->manager, p25Submit($this, $adjustment));
    $types = p25Ledger($posted)->pluck('movement_type')->all();

    expect($types)->toContain('adjustment_in')
        ->and($types)->toContain('adjustment_out')
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($beforeA + 3)
        ->and((float) $sibling->refresh()->balance_qty)->toBeQty($beforeB - 1);

    p25ReconcileLot((int) $lot->id);
    p25ReconcileLot((int) $sibling->id);
});

it('enforces the store-manager band and records the approver', function (): void {
    $lot = p25Cost(p25Lot(), 100);
    $band = app(Settings::class)->decimal('adjustment_approval_band_manager', 25000);

    expect($band)->toBe(25000.0);

    $below = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 100)));
    expect($below->status)->toBe(StockAdjustment::POSTED)
        ->and((int) $below->approved_by)->toBe((int) $this->manager->id);

    $exact = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 250)));
    expect($exact->status)->toBe(StockAdjustment::POSTED);

    $above = p25Submit($this, p25Draft($this, $lot, 300));

    $this->actingAs($this->keeper)
        ->post("/stock-adjustments/{$above->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');
    expect($above->refresh()->status)->toBe(StockAdjustment::PENDING_APPROVAL)
        ->and(p25Ledger($above)->count())->toBe(0);

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$above->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');
    expect($above->refresh()->status)->toBe(StockAdjustment::PENDING_APPROVAL);

    $posted = p25PostAs($this, $this->md, $above);
    expect($posted->status)->toBe(StockAdjustment::POSTED)
        ->and((int) $posted->approved_by)->toBe((int) $this->md->id);
});

it('walks the state machine, treats posted→posted as a no-op, and refuses illegal moves', function (): void {
    $lot = p25Lot();
    $draft = p25Draft($this, $lot, 1);

    $this->actingAs($this->keeper)
        ->post("/stock-adjustments/{$draft->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe(StockAdjustment::CANCELLED);

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$draft->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');
    expect($draft->refresh()->status)->toBe(StockAdjustment::CANCELLED);

    $pending = p25Submit($this, p25Draft($this, $lot, 1));
    $this->actingAs($this->keeper)
        ->post("/stock-adjustments/{$pending->id}/transition", ['to' => 'draft'])
        ->assertSessionHasNoErrors();
    expect($pending->refresh()->status)->toBe(StockAdjustment::DRAFT);

    $pendingAgain = p25Submit($this, $pending);
    $this->actingAs($this->keeper)
        ->post("/stock-adjustments/{$pendingAgain->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHasNoErrors();
    expect($pendingAgain->refresh()->status)->toBe(StockAdjustment::CANCELLED);

    $posted = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 1)));
    $ledger = p25Ledger($posted)->count();

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$posted->id}/transition", ['to' => 'posted'])
        ->assertSessionHasNoErrors();
    expect($posted->refresh()->status)->toBe(StockAdjustment::POSTED)
        ->and(p25Ledger($posted)->count())->toBe($ledger);

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$posted->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('error');
    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$posted->id}/transition", ['to' => 'draft'])
        ->assertSessionHas('error');
    expect($posted->refresh()->status)->toBe(StockAdjustment::POSTED);
});

it('does not write stock from a draft-to-posted shortcut and refuses posted edits and deletes', function (): void {
    $lot = p25Lot();
    $draft = p25Draft($this, $lot, 2);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$draft->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect($draft->refresh()->status)->toBe(StockAdjustment::DRAFT)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);

    $posted = p25PostAs($this, $this->manager, p25Submit($this, $draft));

    $this->actingAs($this->keeper)->put("/stock-adjustments/{$posted->id}", [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'rewrite history',
        'lines' => [['lot_id' => $lot->id, 'qty_delta' => 99]],
    ])->assertSessionHas('error');

    $this->actingAs($this->keeper)->delete("/stock-adjustments/{$posted->id}")->assertMethodNotAllowed();

    expect($posted->refresh()->status)->toBe(StockAdjustment::POSTED)
        ->and(p25Ledger($posted)->count())->toBe(1);
});

it('restores net quantity with a compensating adjustment', function (): void {
    $lot = p25Lot();
    $original = (float) $lot->balance_qty;

    p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 6)));
    expect((float) $lot->refresh()->balance_qty)->toBeQty($original + 6);

    p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, -6)));
    expect((float) $lot->refresh()->balance_qty)->toBeQty($original);

    p25ReconcileLot((int) $lot->id);
});

it('audits creation, submission and posting', function (): void {
    $lot = p25Lot();
    $posted = p25PostAs($this, $this->manager, p25Submit($this, p25Draft($this, $lot, 1)));

    $events = AuditLog::query()
        ->where('auditable_type', StockAdjustment::class)
        ->where('auditable_id', $posted->id)
        ->pluck('event');

    expect($events)->toContain('created')
        ->and($events->filter(fn ($event): bool => $event === 'status_changed')->count())->toBeGreaterThanOrEqual(2);

    $statuses = AuditLog::query()
        ->where('auditable_type', StockAdjustment::class)
        ->where('auditable_id', $posted->id)
        ->where('event', 'status_changed')
        ->get()
        ->map(fn (AuditLog $row) => $row->new_values['status'] ?? null)
        ->all();

    expect($statuses)->toContain(StockAdjustment::PENDING_APPROVAL)
        ->and($statuses)->toContain(StockAdjustment::POSTED);
});

it('rolls back the whole posting when a later line is impossible', function (): void {
    $lot = p25Lot();
    $sibling = p25SiblingLot($lot);
    $adjustment = p25Submit($this, p25Draft($this, $lot, overrides: [
        'lines' => [
            ['lot_id' => $lot->id, 'qty_delta' => 2],
            ['lot_id' => $sibling->id, 'qty_delta' => 1],
        ],
    ]));

    DB::table('stock_adjustment_lines')
        ->where('stock_adjustment_id', $adjustment->id)
        ->where('lot_id', $sibling->id)
        ->update(['qty_delta' => -(((float) $sibling->balance_qty) + 50)]);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $balanceA = (float) $lot->balance_qty;
    $balanceB = (float) $sibling->balance_qty;

    $this->actingAs($this->manager)
        ->post("/stock-adjustments/{$adjustment->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect($adjustment->refresh()->status)->toBe(StockAdjustment::PENDING_APPROVAL)
        ->and($adjustment->approved_by)->toBeNull()
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($balanceA)
        ->and((float) $sibling->refresh()->balance_qty)->toBeQty($balanceB);
});

it('refuses a transition from a user who cannot see the document', function (): void {
    $adjustment = p25Submit($this, p25Draft($this, p25Lot(), 1));

    $this->actingAs($this->operator)
        ->post("/stock-adjustments/{$adjustment->id}/transition", ['to' => 'posted'])
        ->assertForbidden();
    $this->actingAs($this->driver)
        ->post("/stock-adjustments/{$adjustment->id}/transition", ['to' => 'posted'])
        ->assertForbidden();

    expect($adjustment->refresh()->status)->toBe(StockAdjustment::PENDING_APPROVAL);
});

it('puts in-band pending adjustments on the store manager queue and all of them on the MD queue', function (): void {
    $lot = p25Cost(p25Lot(), 100);
    p25Submit($this, p25Draft($this, $lot, 100));
    p25Submit($this, p25Draft($this, $lot, 300));

    $queue = app(WorkQueue::class);
    $manager = collect($queue->for($this->manager))->firstWhere('key', 'stock_adjustment_approval');
    $md = collect($queue->for($this->md))->firstWhere('key', 'stock_adjustment_approval');
    $keeperKeys = array_column($queue->for($this->keeper), 'key');
    $operator = $queue->for($this->operator);

    expect($manager['count'])->toBe(1)
        ->and($md['count'])->toBe(2)
        ->and($keeperKeys)->not->toContain('stock_adjustment_approval')
        ->and($operator)->toBe([]);
});
