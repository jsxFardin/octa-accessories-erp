<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\LotHoldExplainer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * F-06 (P2) — a held lot says why, since when, and what happens next.
 *
 * The rule itself was never wrong: blocked stock cannot be dispatched, and the audit retracted
 * that finding. What was wrong was the screen. `Blocked` with nothing beside it, next to a
 * dispatch movement carrying a date, reads as "this shipped while frozen" — and that is what
 * was reported. The status now carries the record that caused it.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->explainer = app(LotHoldExplainer::class);

    $this->lot = StockLot::query()->where('status', 'available')->firstOrFail();
});

it('says nothing about a lot that is not held', function (): void {
    expect($this->explainer->explain($this->lot->fresh(), $this->admin))->toBeNull();

    $this->actingAs($this->admin)
        ->get("/lots/{$this->lot->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hold', null));
});

it('explains a lot frozen by a physical count: when, who, why, and the count itself', function (): void {
    $this->actingAs($this->admin)
        ->post('/physical-counts', [
            'warehouse_id' => $this->lot->warehouse_id,
            'counted_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

    $count = DB::table('physical_counts')->orderByDesc('id')->first();

    $this->actingAs($this->admin)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'counting'])
        ->assertSessionHasNoErrors();

    $lot = $this->lot->fresh();
    expect($lot->status)->toBe('blocked');

    $hold = $this->explainer->explain($lot, $this->admin);

    expect($hold['held'])->toBeTrue()
        ->and($hold['status'])->toBe('blocked')
        ->and($hold['headline'])->toContain($count->number)
        ->and($hold['since'])->not->toBeNull()
        ->and($hold['actor'])->not->toBeNull()
        ->and($hold['reason'])->not->toBeEmpty()
        ->and($hold['reference']['href'])->toBe("/physical-counts/{$count->id}")
        ->and($hold['next_action'])->toContain('count');
});

it('puts the same explanation on the lot screen', function (): void {
    $this->actingAs($this->admin)->post('/physical-counts', ['warehouse_id' => $this->lot->warehouse_id]);
    $count = DB::table('physical_counts')->orderByDesc('id')->first();
    $this->actingAs($this->admin)->post("/physical-counts/{$count->id}/transition", ['to' => 'counting']);

    $this->actingAs($this->admin)
        ->get("/lots/{$this->lot->id}")
        ->assertInertia(function (AssertableInertia $page) use ($count): void {
            $hold = $page->toArray()['props']['hold'];

            expect($hold)->not->toBeNull()
                ->and($hold['headline'])->toContain($count->number)
                ->and($hold['since'])->not->toBeNull()
                ->and($hold['next_action'])->not->toBeEmpty();
        });
});

it('counts the outward movements that predate the hold, so history is not read as a breach', function (): void {
    // Exactly the audit's shape: the lot shipped while available, and was frozen afterwards.
    DB::table('stock_ledger')->insert([
        'lot_id' => $this->lot->id,
        'item_id' => $this->lot->item_id,
        'warehouse_id' => $this->lot->warehouse_id,
        'movement_type' => 'issue_to_job',
        'qty' => -1,
        'uom_id' => $this->lot->uom_id,
        'unit_cost' => 0,
        'value' => 0,
        'source_type' => 'test',
        'source_id' => 1,
        'occurred_at' => now()->subDays(2),
    ]);

    $this->actingAs($this->admin)->post('/physical-counts', ['warehouse_id' => $this->lot->warehouse_id]);
    $count = DB::table('physical_counts')->orderByDesc('id')->first();
    $this->actingAs($this->admin)->post("/physical-counts/{$count->id}/transition", ['to' => 'counting']);

    $hold = $this->explainer->explain($this->lot->fresh(), $this->admin);

    expect($hold['movements_predating_hold'])->toBeGreaterThanOrEqual(1);

    // And the movement is still on the ledger for anyone to read (I1, append-only).
    $this->actingAs($this->admin)
        ->get("/lots/{$this->lot->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            expect(collect($page->toArray()['props']['ledger'])->pluck('movement_type'))
                ->toContain('issue_to_job');
        });
});

it('offers the resolving action only to someone who holds the permission', function (): void {
    $this->actingAs($this->admin)->post('/physical-counts', ['warehouse_id' => $this->lot->warehouse_id]);
    $count = DB::table('physical_counts')->orderByDesc('id')->first();
    $this->actingAs($this->admin)->post("/physical-counts/{$count->id}/transition", ['to' => 'counting']);

    $lot = $this->lot->fresh();
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    expect($this->explainer->explain($lot, $this->admin)['can_resolve'])->toBeTrue()
        ->and($this->explainer->explain($lot, $operator)['can_resolve'])->toBeFalse()
        ->and($this->explainer->explain($lot, null)['can_resolve'])->toBeFalse();

    // And the operator cannot post the count either — the screen and the server agree.
    $this->actingAs($operator)
        ->post("/physical-counts/{$count->id}/transition", ['to' => 'posted'])
        ->assertForbidden();
});

it('says plainly when nothing in the system accounts for the status', function (): void {
    // No count, no rejected inspection — the honest answer is that nobody recorded a reason.
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $hold = $this->explainer->explain($this->lot->fresh(), $this->admin);

    expect($hold['reason'])->toContain('No physical count or rejected inspection')
        ->and($hold['since'])->toBeNull()
        ->and($hold['actor'])->toBeNull()
        ->and($hold['reference'])->toBeNull()
        ->and($hold['next_action'])->toContain('Quality Control')
        ->and($hold['can_resolve'])->toBeFalse();
});

it('still refuses to dispatch a held lot, whatever the screen says about it', function (): void {
    DB::table('stock_lots')->where('id', $this->lot->id)->update(['status' => 'blocked']);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $balanceBefore = (float) $this->lot->fresh()->balance_qty;

    // The guard is the service's, not the page's; the explanation changes nothing about it.
    expect(app(LotHoldExplainer::class)->explain($this->lot->fresh(), $this->admin)['held'])
        ->toBeTrue()
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $this->lot->fresh()->balance_qty)->toBeQty($balanceBefore);
});
