<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan stock:reconcile` — does the derived balance still match the ledger?
 *
 * AD-6 makes `stock_ledger` append-only and every balance derived from it. `stock_lots.balance_qty`
 * and the `stock_balances` table are caches, maintained by `StockPostingService` inside the same
 * transaction as the movement that changes them. That holds only for as long as every write goes
 * through that service, and `StockPostingService` says so in as many words:
 *
 *     "A nightly job compares it against `v_stock_balances`; a difference means a posting path
 *      bypassed this service, and is raised as a bug rather than silently corrected."
 *
 * There was no such job. The only scheduled command in the application was the NCR overdue
 * notice, so a bypassed posting path — a raw `UPDATE`, a migration, a repair script — would have
 * drifted the cache away from the ledger with nothing anywhere to notice. Stock enquiry, the
 * dashboard, MRP and the FIFO suggestion all read the cache, so the first symptom would have
 * been a physical count that would not tie out, months later.
 *
 * Deliberately a report and not a repair. Rewriting the cache to match the ledger would hide the
 * bug that caused the difference, and the difference is the only evidence that the bug exists.
 * Exits non-zero so a cron can raise it without parsing the output.
 */
class StockReconciliationCommand extends Command
{
    protected $signature = 'stock:reconcile {--limit=20 : How many differing lots to print}';

    protected $description = 'Compare the derived stock caches against the append-only ledger (AD-6)';

    /** Quantities are DECIMAL(18,6); anything smaller than this is representation, not drift. */
    private const EPSILON = 0.000001;

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // The lot's own cached balance against the ledger that defines it.
        $lotDrift = DB::table('stock_lots as l')
            ->leftJoin('v_stock_balances as v', 'v.lot_id', '=', 'l.id')
            ->whereRaw('ABS(l.balance_qty - COALESCE(v.balance_qty, 0)) > ?', [self::EPSILON])
            ->orderBy('l.id')
            ->limit($limit)
            ->get(['l.id', 'l.lot_no', 'l.balance_qty as cached', DB::raw('COALESCE(v.balance_qty, 0) as ledger')]);

        $lotDriftCount = DB::table('stock_lots as l')
            ->leftJoin('v_stock_balances as v', 'v.lot_id', '=', 'l.id')
            ->whereRaw('ABS(l.balance_qty - COALESCE(v.balance_qty, 0)) > ?', [self::EPSILON])
            ->count();

        // The summary table against the same ledger. A row that is missing entirely is drift
        // too: `refreshBalanceSummary()` writes one for every lot it posts against.
        $summaryDrift = DB::table('stock_lots as l')
            ->leftJoin('stock_balances as b', 'b.lot_id', '=', 'l.id')
            ->leftJoin('v_stock_balances as v', 'v.lot_id', '=', 'l.id')
            ->whereRaw('ABS(COALESCE(b.balance_qty, 0) - COALESCE(v.balance_qty, 0)) > ?', [self::EPSILON])
            ->count();

        $this->components->twoColumnDetail('Lots checked', (string) DB::table('stock_lots')->count());
        $this->components->twoColumnDetail('Lot balances adrift', (string) $lotDriftCount);
        $this->components->twoColumnDetail('Summary rows adrift', (string) $summaryDrift);

        if ($lotDriftCount === 0 && $summaryDrift === 0) {
            $this->components->info('Every derived balance matches the ledger.');

            return self::SUCCESS;
        }

        foreach ($lotDrift as $row) {
            $this->components->twoColumnDetail(
                "Lot {$row->lot_no}",
                sprintf('cached %s, ledger %s', $this->trim((float) $row->cached), $this->trim((float) $row->ledger)),
            );
        }

        if ($lotDriftCount > $lotDrift->count()) {
            $this->components->warn(sprintf('… and %d more.', $lotDriftCount - $lotDrift->count()));
        }

        // Not repaired on purpose: the difference is the evidence that a posting path bypassed
        // `StockPostingService`, and correcting it quietly would destroy that evidence.
        $this->components->error(
            'A derived balance disagrees with the ledger. Something wrote stock without going through StockPostingService (AD-6). Find that path before correcting the figures.',
        );

        return self::FAILURE;
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
