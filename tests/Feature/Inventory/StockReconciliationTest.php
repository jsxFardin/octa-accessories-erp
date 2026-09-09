<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * AD-6 — the derived balances are caches over an append-only ledger, and something has to check.
 *
 * `StockPostingService` documents a nightly job that compares `stock_balances` against
 * `v_stock_balances` and raises a difference "as a bug rather than silently corrected". No such
 * job existed: the only scheduled command in the application was the NCR overdue notice. A
 * posting path that bypassed the service — a raw UPDATE, a migration, a repair script — would
 * have drifted the cache away from the ledger with nothing anywhere to notice, and every screen
 * that shows stock reads the cache. The first symptom would have been a physical count that
 * would not tie out, months later.
 */
it('stock: passes when every derived balance matches the ledger', function (): void {
    $this->artisan('stock:reconcile')
        ->expectsOutputToContain('Every derived balance matches the ledger.')
        ->assertSuccessful();
});

it('stock: fails when a lot balance is moved behind the ledger', function (): void {
    $lotId = DB::table('stock_lots')->insertGetId([
        'lot_no' => 'DRIFT-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 100,
        // No ledger entry behind it: exactly what a bypassed posting path leaves.
        'balance_qty' => 100,
        'unit_cost' => 5,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    $this->artisan('stock:reconcile')
        ->expectsOutputToContain('A derived balance disagrees with the ledger.')
        ->assertFailed();

    // Reported, not repaired — the difference is the evidence of the bug that caused it.
    expect((float) DB::table('stock_lots')->where('id', $lotId)->value('balance_qty'))
        ->toEqualWithDelta(100.0, 0.0001);
});

it('stock: fails when the summary table disagrees with the ledger', function (): void {
    $lotId = DB::table('stock_lots')->insertGetId([
        'lot_no' => 'SUMDRIFT-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 0,
        'balance_qty' => 0,
        'unit_cost' => 5,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    // The lot itself ties to the ledger; only the summary row is wrong. That is the half a
    // check on `stock_lots` alone would miss, and `stock_balances` is what the enquiry reads.
    DB::table('stock_balances')->insert([
        'lot_id' => $lotId,
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'lot_no' => 'SUMDRIFT',
        'balance_qty' => 42,
        'refreshed_at' => now(),
    ]);

    $this->artisan('stock:reconcile')->assertFailed();
});

it('stock: leaves a lot alone when the ledger genuinely backs it', function (): void {
    // The rule must not start reporting drift against ordinary, correctly posted stock.
    $before = DB::table('stock_lots')->count();

    $this->artisan('stock:reconcile')->assertSuccessful();

    expect(DB::table('stock_lots')->count())->toBe($before);
});
