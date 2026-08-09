<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\NegativeStockException;
use App\Modules\Inventory\Services\StockPostingService;
use Illuminate\Support\Facades\DB;

/**
 * The inventory invariants: append-only ledger, derived balances, no negative stock.
 */
beforeEach(function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'store@maheenlabel.test')->firstOrFail());
    $this->posting = app(StockPostingService::class);
});

it('cannot drive a lot balance negative', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 0)->firstOrFail();
    $balance = (float) $lot->balance_qty;

    // BR-38 / I4 — the service refuses under a row lock before the CHECK constraint would.
    expect(fn () => $this->posting->post($lot, 'issue_to_job', -($balance + 1), $lot))
        ->toThrow(NegativeStockException::class, 'short');

    expect((float) $lot->refresh()->balance_qty)->toBe($balance);
});

it('names the lot and the shortfall when it refuses', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 0)->firstOrFail();

    // The store keeper's next action is to find the missing quantity, not read a stack trace.
    expect(fn () => $this->posting->post($lot, 'issue_to_job', -((float) $lot->balance_qty + 10), $lot))
        ->toThrow(function (NegativeStockException $e) use ($lot): void {
            expect($e->getMessage())->toContain($lot->lot_no)->toContain('BR-38');
        });
});

it('issues down to exactly zero without complaint', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 0)->firstOrFail();
    $balance = (float) $lot->balance_qty;

    $this->posting->post($lot, 'issue_to_job', -$balance, $lot);

    expect((float) $lot->refresh()->balance_qty)->toBe(0.0)
        // A lot drawn to zero is consumed, not merely empty.
        ->and($lot->status)->toBe('consumed');
});

it('keeps the cached balance in step with the ledger', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 10)->firstOrFail();

    $this->posting->post($lot, 'issue_to_job', -5, $lot);
    $this->posting->post($lot, 'return_from_job', 2, $lot);

    $ledgerSum = (float) DB::table('stock_ledger')->where('lot_id', $lot->id)->sum('qty');

    // I3 — the ledger is the truth; the column is a rebuildable cache that must agree with it.
    expect((float) $lot->refresh()->balance_qty)->toBe($ledgerSum);
});

it('agrees with the live view that recomputes from the ledger', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 10)->firstOrFail();

    $this->posting->post($lot, 'issue_to_job', -3, $lot);

    $view = (float) DB::table('v_stock_balances')->where('lot_id', $lot->id)->value('balance_qty');
    $summary = (float) DB::table('stock_balances')->where('lot_id', $lot->id)->value('balance_qty');

    expect($summary)->toBe($view)->toBe((float) $lot->refresh()->balance_qty);
});

it('records a correction as a reversing entry rather than an edit', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->where('balance_qty', '>', 10)->firstOrFail();

    $entry = $this->posting->post($lot, 'issue_to_job', -4, $lot);
    $balanceAfterIssue = (float) $lot->refresh()->balance_qty;

    $reversal = $this->posting->reverse($entry, 'Issued against the wrong job card');

    // I1 — the original row is still there. Two rows, not one edited row.
    expect(DB::table('stock_ledger')->where('id', $entry->id)->exists())->toBeTrue()
        ->and((float) $reversal->qty)->toBe(4.0)
        ->and((float) $lot->refresh()->balance_qty)->toBe($balanceAfterIssue + 4)
        ->and($reversal->remarks)->toContain('Reversal');
});

it('refuses a movement of zero', function (): void {
    /** @var StockLot $lot */
    $lot = StockLot::query()->firstOrFail();

    // `stock_ledger_qty_chk` says the same thing; this says it with a useful message.
    expect(fn () => $this->posting->post($lot, 'issue_to_job', 0, $lot))
        ->toThrow(InvalidArgumentException::class);
});

it('moves the item weighted average on receipt', function (): void {
    $item = DB::table('items')->where('code', 'YRN-PLY-150D-WHT')->first();
    $before = (float) $item->avg_rate;

    $lot = StockLot::query()->where('item_id', $item->id)->firstOrFail();

    $this->posting->receive(
        [
            'lot_no' => 'TST-AVG-1',
            'item_id' => $item->id,
            'kind' => 'raw_material',
            'warehouse_id' => $lot->warehouse_id,
            'uom_id' => $lot->uom_id,
            'received_on' => now()->toDateString(),
            'status' => 'available',
        ],
        100.0,
        2000.0,
        $lot,
    );

    $after = (float) DB::table('items')->where('id', $item->id)->value('avg_rate');

    // BR-36 — receiving above the average pulls it up, and by less than the full difference.
    expect($after)->toBeGreaterThan($before)->toBeLessThan(2000.0);
});
