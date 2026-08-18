<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * P2-8 / IN-6 — warehouse-wide physical counts. Open and counting write no stock; posting
 * is the approval effect and the only step that calls StockPostingService as count_variance.
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $this->manager = User::query()->where('email', 'storemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

function p28Lot(): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>', 20)
        ->orderBy('id')
        ->firstOrFail();
}

function p28SiblingLot(StockLot $lot): StockLot
{
    return StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', $lot->warehouse_id)
        ->where('id', '!=', $lot->id)
        ->where('balance_qty', '>', 5)
        ->orderBy('id')
        ->firstOrFail();
}

function p28Open(object $test, StockLot $lot, array $overrides = []): PhysicalCount
{
    $test->actingAs($test->keeper);

    $test->post('/physical-counts', [
        'warehouse_id' => $overrides['warehouse_id'] ?? $lot->warehouse_id,
        'counted_on' => $overrides['counted_on'] ?? now()->toDateString(),
        'status' => $overrides['status'] ?? 'posted',
        'number' => $overrides['number'] ?? 'PC-FORGED',
        'created_by' => $overrides['created_by'] ?? $test->md->id,
    ])->assertSessionHasNoErrors();

    return PhysicalCount::query()->orderByDesc('id')->firstOrFail();
}

function p28StartCounting(object $test, PhysicalCount $count, ?User $user = null): PhysicalCount
{
    $test->actingAs($user ?? $test->keeper);
    $test->post("/physical-counts/{$count->id}/transition", ['to' => 'counting'])
        ->assertSessionHas('success');

    return $count->refresh();
}

/** @param array<int, float|int|string> $overrides keyed by line id or lot id */
function p28EnterCounts(object $test, PhysicalCount $count, array $overrides = []): PhysicalCount
{
    $lines = PhysicalCountLine::query()
        ->where('physical_count_id', $count->id)
        ->orderBy('id')
        ->get();

    $payload = $lines->map(function (PhysicalCountLine $line) use ($overrides): array {
        $counted = $overrides[$line->id]
            ?? $overrides[$line->lot_id]
            ?? (float) $line->system_qty;

        return [
            'id' => $line->id,
            'counted_qty' => $counted,
            'remarks' => 'counted',
        ];
    })->all();

    $test->actingAs($test->keeper);
    $test->put("/physical-counts/{$count->id}", ['lines' => $payload])
        ->assertSessionHasNoErrors();

    return $count->refresh();
}

function p28Reconcile(object $test, PhysicalCount $count): PhysicalCount
{
    $test->actingAs($test->keeper);
    $test->post("/physical-counts/{$count->id}/transition", ['to' => 'reconciled'])
        ->assertSessionHas('success');

    return $count->refresh();
}

function p28PostAs(object $test, User $user, PhysicalCount $count): PhysicalCount
{
    $test->actingAs($user);
    $test->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('success');

    return $count->refresh();
}

function p28Ledger(PhysicalCount $count)
{
    return DB::table('stock_ledger')
        ->where('source_type', PhysicalCount::class)
        ->where('source_id', $count->id);
}

function p28ReconcileLot(int $lotId): void
{
    $cached = (float) DB::table('stock_lots')->where('id', $lotId)->value('balance_qty');
    $ledger = (float) DB::table('stock_ledger')->where('lot_id', $lotId)->sum('qty');
    $summary = (float) DB::table('stock_balances')->where('lot_id', $lotId)->value('balance_qty');
    $view = (float) DB::table('v_stock_balances')->where('lot_id', $lotId)->value('balance_qty');

    expect($cached)->toBeQty($ledger)
        ->and($summary)->toBeQty($ledger)
        ->and($view)->toBeQty($ledger);
}

function p28Cost(StockLot $lot, float $unitCost): StockLot
{
    $lot->forceFill(['unit_cost' => $unitCost])->save();

    return $lot->refresh();
}

it('refuses the index to operators and drivers', function (): void {
    $this->actingAs($this->operator)->get('/physical-counts')->assertForbidden();
    $this->actingAs($this->driver)->get('/physical-counts')->assertForbidden();
});

it('lets keepers and managers create, and refuses MD, operators and drivers', function (): void {
    $lot = p28Lot();

    $this->actingAs($this->operator)->post('/physical-counts', [
        'warehouse_id' => $lot->warehouse_id,
    ])->assertForbidden();

    $this->actingAs($this->md)->get('/physical-counts/create')->assertForbidden();
    $this->actingAs($this->md)->post('/physical-counts', [
        'warehouse_id' => $lot->warehouse_id,
    ])->assertForbidden();

    $this->actingAs($this->keeper)->get('/physical-counts/create')->assertOk();
    $this->actingAs($this->manager)->get('/physical-counts/create')->assertOk();

    $count = p28Open($this, $lot);

    expect($count->status)->toBe(PhysicalCount::OPEN)
        ->and($count->number)->toBeNull()
        ->and((int) $count->created_by)->toBe((int) $this->keeper->id);

    $this->actingAs($this->keeper)->get('/physical-counts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory/Counts/Index'));
});

it('starts counting, snapshots system_qty server-side and freezes available lots', function (): void {
    $lot = p28Lot();
    $sibling = p28SiblingLot($lot);
    $blocked = StockLot::query()
        ->where('warehouse_id', $lot->warehouse_id)
        ->where('status', 'blocked')
        ->first();

    if ($blocked) {
        $blocked->forceFill(['status' => 'quarantine'])->save();
    }

    $beforeLot = (float) $lot->balance_qty;
    $beforeSibling = (float) $sibling->balance_qty;
    $ledgerBefore = DB::table('stock_ledger')->count();

    $count = p28StartCounting($this, p28Open($this, $lot));

    $line = PhysicalCountLine::query()->where('physical_count_id', $count->id)->where('lot_id', $lot->id)->firstOrFail();
    $siblingLine = PhysicalCountLine::query()->where('physical_count_id', $count->id)->where('lot_id', $sibling->id)->firstOrFail();

    expect($count->status)->toBe(PhysicalCount::COUNTING)
        ->and($count->number)->toStartWith('PC')
        ->and((float) $line->system_qty)->toBeQty($beforeLot)
        ->and($line->counted_qty)->toBeNull()
        ->and((float) $siblingLine->system_qty)->toBeQty($beforeSibling)
        ->and($lot->refresh()->status)->toBe('blocked')
        ->and($sibling->refresh()->status)->toBe('blocked')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('prints a blind sheet that does not expose system_qty or variance', function (): void {
    $lot = p28Lot();
    $count = p28StartCounting($this, p28Open($this, $lot));

    $html = $this->actingAs($this->keeper)
        ->get("/physical-counts/{$count->id}/print")
        ->assertOk()
        ->getContent();

    expect($html)->toContain($count->number)
        ->and($html)->toContain($lot->lot_no)
        ->and($html)->not->toContain('system_qty')
        ->and($html)->not->toContain('variance')
        ->and($html)->not->toContain('Unit cost')
        ->and($html)->not->toContain(number_format((float) $lot->balance_qty, 3));
});

it('requires counted_qty on every line before reconciliation', function (): void {
    $lot = p28Lot();
    $count = p28StartCounting($this, p28Open($this, $lot));

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'reconciled'])
        ->assertSessionHas('error');

    expect($count->refresh()->status)->toBe(PhysicalCount::COUNTING);
});

it('reconciles when every line is counted and exposes generated variance', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;
    $count = p28StartCounting($this, p28Open($this, $lot));

    $line = PhysicalCountLine::query()->where('physical_count_id', $count->id)->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before + 2]);

    $reconciled = p28Reconcile($this, $count);
    $line->refresh();

    expect($reconciled->status)->toBe(PhysicalCount::RECONCILED)
        ->and((float) $line->variance_qty)->toBeQty(2.0);

    $this->actingAs($this->keeper)->get("/physical-counts/{$count->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Counts/Show')
            ->where('lines.0.variance_qty', 2));
});

it('posts positive count_variance, keeps unit cost and avg_rate unchanged, and unfreezes lots', function (): void {
    $lot = p28Cost(p28Lot(), 12.5);
    $before = (float) $lot->balance_qty;
    $avgBefore = (float) DB::table('items')->where('id', $lot->item_id)->value('avg_rate');
    $lotsBefore = DB::table('stock_lots')->count();

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before + 3]);
    p28Reconcile($this, $count);

    $posted = p28PostAs($this, $this->manager, $count);
    $entry = p28Ledger($posted)->first();

    expect($posted->status)->toBe(PhysicalCount::POSTED)
        ->and(p28Ledger($posted)->count())->toBe(1)
        ->and($entry->movement_type)->toBe('count_variance')
        ->and((float) $entry->qty)->toBeQty(3.0)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before + 3)
        ->and($lot->status)->toBe('available')
        ->and((float) $lot->unit_cost)->toBe(12.5)
        ->and((float) DB::table('items')->where('id', $lot->item_id)->value('avg_rate'))->toBe($avgBefore)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore);

    p28ReconcileLot((int) $lot->id);
});

it('posts negative count_variance on the same lot', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before - 4]);
    p28Reconcile($this, $count);

    p28PostAs($this, $this->manager, $count);

    $entry = p28Ledger($count)->first();

    expect($entry->movement_type)->toBe('count_variance')
        ->and((float) $entry->qty)->toBeQty(-4.0)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before - 4);

    p28ReconcileLot((int) $lot->id);
});

it('creates no ledger row for a zero-variance line', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before]);
    p28Reconcile($this, $count);

    p28PostAs($this, $this->manager, $count);

    expect(p28Ledger($count)->count())->toBe(0)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty($before)
        ->and($lot->status)->toBe('available');
});

it('restores a consumed lot to available when a positive variance brings balance back', function (): void {
    $lot = p28Lot();
    $lot->forceFill(['balance_qty' => 0, 'status' => 'available'])->save();

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();

    $lot->forceFill(['status' => 'consumed', 'balance_qty' => 0])->save();

    p28EnterCounts($this, $count, [$line->id => 5]);
    p28Reconcile($this, $count);
    p28PostAs($this, $this->manager, $count);

    expect((float) $lot->refresh()->balance_qty)->toBeQty(5.0)
        ->and($lot->status)->toBe('available');
});

it('marks a lot consumed when negative variance drives balance to zero', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => 0]);
    p28Reconcile($this, $count);
    p28PostAs($this, $this->manager, $count);

    expect((float) $lot->refresh()->balance_qty)->toBeQty(0.0)
        ->and($lot->status)->toBe('consumed');
});

it('rolls back the entire posting when BR-38 would be violated', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => 0]);
    p28Reconcile($this, $count);

    $lot->forceFill(['balance_qty' => 2])->save();

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->manager)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect($count->refresh()->status)->toBe(PhysicalCount::RECONCILED)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $lot->refresh()->balance_qty)->toBeQty(2.0);
});

it('rolls back atomically when any line in a multi-line post would fail', function (): void {
    $lot = p28Lot();
    $sibling = p28SiblingLot($lot);

    $count = p28StartCounting($this, p28Open($this, $lot));
    $lines = PhysicalCountLine::query()->where('physical_count_id', $count->id)->get()->keyBy('lot_id');

    p28EnterCounts($this, $count, [
        $lines[$lot->id]->id => (float) $lot->balance_qty + 1,
        $lines[$sibling->id]->id => 0,
    ]);
    p28Reconcile($this, $count);

    $sibling->forceFill(['balance_qty' => 1])->save();

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->manager)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect(p28Ledger($count)->count())->toBe(0)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('cancels counting, unfreezes only this count\'s lots and writes no ledger', function (): void {
    $lot = p28Lot();
    $ledgerBefore = DB::table('stock_ledger')->count();

    $count = p28StartCounting($this, p28Open($this, $lot));

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('success');

    expect($count->refresh()->status)->toBe(PhysicalCount::CANCELLED)
        ->and($lot->refresh()->status)->toBe('available')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('refuses further transitions from terminal posted and cancelled states', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before]);
    p28Reconcile($this, $count);
    p28PostAs($this, $this->manager, $count);

    $this->actingAs($this->manager)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('error');

    $open = p28Open($this, $lot);
    $started = p28StartCounting($this, $open);

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$started->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('success');

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$started->id}/transition", ['to' => 'counting'])
        ->assertSessionHas('error');
});

it('lets the store manager post and refuses keeper and MD', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before + 1]);
    p28Reconcile($this, $count);

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    $this->actingAs($this->md)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    expect(p28PostAs($this, $this->manager, $count)->status)->toBe(PhysicalCount::POSTED);
});

it('blocks IDOR on show and client tampering of server-owned fields', function (): void {
    $lot = p28Lot();
    $foreign = StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', '!=', $lot->warehouse_id)
        ->where('balance_qty', '>', 0)
        ->firstOrFail();

    $count = p28Open($this, $lot);

    $this->actingAs($this->operator)->get("/physical-counts/{$count->id}")->assertForbidden();

    $count = p28StartCounting($this, $count);
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();

    $this->actingAs($this->keeper)->put("/physical-counts/{$count->id}", [
        'lines' => [[
            'id' => $line->id,
            'counted_qty' => (float) $lot->balance_qty,
            'system_qty' => 999,
            'variance_qty' => 999,
            'lot_id' => $foreign->id,
        ]],
    ])->assertSessionHasNoErrors();

    expect((float) $line->refresh()->system_qty)->toBeQty((float) $lot->balance_qty)
        ->and((float) $line->variance_qty)->toBeQty(0.0)
        ->and((int) $line->lot_id)->toBe((int) $lot->id);
});

it('rejects overlapping non-terminal counts for the same warehouse', function (): void {
    $lot = p28Lot();
    $first = p28Open($this, $lot);

    $this->actingAs($this->keeper)->post('/physical-counts', [
        'warehouse_id' => $lot->warehouse_id,
    ])->assertSessionHasErrors('warehouse_id');

    p28StartCounting($this, $first);

    $this->actingAs($this->keeper)->post('/physical-counts', [
        'warehouse_id' => $lot->warehouse_id,
    ])->assertSessionHasErrors('warehouse_id');
});

it('records audit events for create and status changes', function (): void {
    $lot = p28Lot();
    $count = p28Open($this, $lot);

    expect(AuditLog::query()
        ->where('auditable_type', PhysicalCount::class)
        ->where('auditable_id', $count->id)
        ->where('event', 'created')
        ->exists())->toBeTrue();

    p28StartCounting($this, $count);

    expect(AuditLog::query()
        ->where('auditable_type', PhysicalCount::class)
        ->where('auditable_id', $count->id)
        ->where('event', 'status_changed')
        ->exists())->toBeTrue();
});

it('uses only count_variance movements and never receive reverse or adjustment types', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;

    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before - 2]);
    p28Reconcile($this, $count);
    p28PostAs($this, $this->manager, $count);

    $types = p28Ledger($count)->pluck('movement_type')->all();

    expect($types)->toBe(['count_variance'])
        ->and($types)->not->toContain('receive', 'reverse', 'adjustment_in', 'adjustment_out');
});

it('refuses to issue a lot frozen by a physical count', function (): void {
    $job = JobCard::query()->whereNotNull('sales_order_line_id')->orderBy('id')->firstOrFail();
    $lot = StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>=', 2)
        ->orderBy('id')
        ->firstOrFail();

    p28StartCounting($this, p28Open($this, $lot));

    expect($lot->refresh()->status)->toBe('blocked');

    $this->actingAs($this->keeper)->post('/material-issues', [
        'job_card_id' => $job->id,
        'warehouse_id' => $lot->warehouse_id,
        'issue_type' => 'issue',
        'lines' => [[
            'item_id' => $lot->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $lot->uom_id,
            'qty' => 1,
        ]],
    ])->assertSessionHasErrors('lines.0.lot_id');
});

it('keeps IN-3 returns, IN-4 transfers and IN-5 adjustments functional', function (): void {
    $lot = p28Lot();
    $sibling = p28SiblingLot($lot);

    $this->actingAs($this->keeper)->post('/stock-adjustments', [
        'warehouse_id' => $lot->warehouse_id,
        'reason' => 'IN-6 regression smoke',
        'lines' => [['lot_id' => $sibling->id, 'qty_delta' => 0]],
    ])->assertSessionHasErrors('lines.0.qty_delta');

    $dest = DB::table('warehouses')
        ->where('is_active', true)
        ->where('id', '!=', $lot->warehouse_id)
        ->orderBy('id')
        ->value('id');

    $this->actingAs($this->keeper)->post('/stock-transfers', [
        'from_warehouse_id' => $lot->warehouse_id,
        'to_warehouse_id' => $dest,
        'transfer_date' => now()->toDateString(),
        'lines' => [['lot_id' => $sibling->id, 'qty' => 1]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->keeper)->get('/material-issues/create')->assertOk();
});

it('enforces one line per lot per count from the server snapshot', function (): void {
    $lot = p28Lot();
    $count = p28StartCounting($this, p28Open($this, $lot));

    expect(PhysicalCountLine::query()->where('physical_count_id', $count->id)->where('lot_id', $lot->id)->count())
        ->toBe(1);
});

it('does not freeze blocked or quarantine lots and never snapshots them', function (): void {
    $lot = p28Lot();
    $held = p28SiblingLot($lot);
    $held->forceFill(['status' => 'quarantine'])->save();

    $count = p28StartCounting($this, p28Open($this, $lot));

    expect(PhysicalCountLine::query()->where('physical_count_id', $count->id)->where('lot_id', $held->id)->exists())
        ->toBeFalse()
        ->and($held->refresh()->status)->toBe('quarantine')
        ->and($lot->refresh()->status)->toBe('blocked');
});

it('hides system qty and variance from the counting screen payload', function (): void {
    $lot = p28Lot();
    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => (float) $line->system_qty + 1]);

    $this->actingAs($this->keeper)->get("/physical-counts/{$count->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory/Counts/Show')
            ->missing('lines.0.system_qty')
            ->missing('lines.0.variance_qty')
            ->missing('lines.0.unit_cost')
            ->missing('lines.0.value_impact'));
});

it('rejects a counted quantity below zero', function (): void {
    $lot = p28Lot();
    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();

    $this->actingAs($this->keeper)->put("/physical-counts/{$count->id}", [
        'lines' => [['id' => $line->id, 'counted_qty' => -1]],
    ])->assertSessionHasErrors('lines.0.counted_qty');
});

it('refuses to save a line that belongs to another count', function (): void {
    $lot = p28Lot();
    $foreignLot = StockLot::query()
        ->where('status', 'available')
        ->where('warehouse_id', '!=', $lot->warehouse_id)
        ->where('balance_qty', '>', 0)
        ->orderBy('id')
        ->firstOrFail();

    $count = p28StartCounting($this, p28Open($this, $lot));
    $other = p28StartCounting($this, p28Open($this, $foreignLot));

    $foreignLine = PhysicalCountLine::query()
        ->where('physical_count_id', $other->id)
        ->where('lot_id', $foreignLot->id)
        ->firstOrFail();

    $this->actingAs($this->keeper)->put("/physical-counts/{$count->id}", [
        'lines' => [['id' => $foreignLine->id, 'counted_qty' => 1]],
    ])->assertSessionHasErrors('lines.0.id');
});

it('cancels an open count without freezing, posting or numbering', function (): void {
    $lot = p28Lot();
    $ledgerBefore = DB::table('stock_ledger')->count();
    $count = p28Open($this, $lot);

    $this->actingAs($this->keeper)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('success');

    expect($count->refresh()->status)->toBe(PhysicalCount::CANCELLED)
        ->and($count->number)->toBeNull()
        ->and($lot->refresh()->status)->toBe('available')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(PhysicalCountLine::query()->where('physical_count_id', $count->id)->count())->toBe(0);
});

it('lets MD read a count but never post it, and refuses posted deletion', function (): void {
    $lot = p28Lot();
    $before = (float) $lot->balance_qty;
    $count = p28StartCounting($this, p28Open($this, $lot));
    $line = PhysicalCountLine::query()->where('lot_id', $lot->id)->firstOrFail();
    p28EnterCounts($this, $count, [$line->id => $before]);
    p28Reconcile($this, $count);

    $this->actingAs($this->md)->get('/physical-counts')->assertOk();
    $this->actingAs($this->md)->get("/physical-counts/{$count->id}")->assertOk();
    $this->actingAs($this->md)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertSessionHas('error');

    $posted = p28PostAs($this, $this->manager, $count);

    $this->actingAs($this->manager)->delete("/physical-counts/{$posted->id}")->assertMethodNotAllowed();
});

it('still posts an IN-3 return onto the original unfrozen lot', function (): void {
    $job = JobCard::query()->whereNotNull('sales_order_line_id')->orderBy('id')->firstOrFail();

    if (! in_array($job->status, [
        JobCard::RELEASED,
        JobCard::IN_PRODUCTION,
        JobCard::QC_PENDING,
        JobCard::COMPLETED,
    ], true)) {
        $this->actingAs($this->admin);
        app(JobCardStateMachine::class)->transition($job, JobCard::RELEASED, [
            'material_waiver_reason' => 'IN-6 return-path smoke',
        ]);
        $job->refresh();
    }

    $lot = StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>=', 2)
        ->orderBy('id')
        ->firstOrFail();

    $this->actingAs($this->keeper)->post('/material-issues', [
        'job_card_id' => $job->id,
        'warehouse_id' => $lot->warehouse_id,
        'issue_type' => 'issue',
        'lines' => [[
            'item_id' => $lot->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $lot->uom_id,
            'qty' => 2,
        ]],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->keeper)->post('/material-issues', [
        'job_card_id' => $job->id,
        'warehouse_id' => $lot->warehouse_id,
        'issue_type' => 'return',
        'lines' => [[
            'item_id' => $lot->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $lot->uom_id,
            'qty' => 2,
        ]],
    ])->assertSessionHasNoErrors();

    $return = MaterialIssue::query()->where('issue_type', MaterialIssue::TYPE_RETURN)->orderByDesc('id')->firstOrFail();

    expect(DB::table('stock_ledger')
        ->where('source_type', MaterialIssue::class)
        ->where('source_id', $return->id)
        ->value('movement_type'))->toBe('return_from_job');
});
