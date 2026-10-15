<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * IN-8 AC2 / BR-39 — a lot past its expiry date is not issuable.
 *
 * The thirty-day warning and the ageing buckets were built; the status that makes the rule
 * bite was never set, so an expired ink tin stayed `available` and the issue screen offered
 * it. The ledger is untouched: the pieces are still on the shelf, they are just not to be
 * used, which is a status, not a movement. Only `available` lots move — a reserved, blocked
 * or quarantined lot already has a reason of its own.
 */
class ExpireStockLotsCommand extends Command
{
    protected $signature = 'stock:expire-lots {--dry-run : List what would expire without changing anything}';

    protected $description = 'Mark available lots past their expiry date as expired';

    public function handle(AuditLogger $audit): int
    {
        $due = DB::table('stock_lots')
            ->where('status', 'available')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->orderBy('id')
            ->get(['id', 'lot_no', 'expiry_date']);

        if ($this->option('dry-run')) {
            foreach ($due as $lot) {
                $this->line(sprintf('%s expired %s', $lot->lot_no, $lot->expiry_date));
            }

            $this->info($due->count().' lot(s) would expire.');

            return self::SUCCESS;
        }

        foreach ($due as $lot) {
            DB::transaction(function () use ($lot, $audit): void {
                DB::table('stock_lots')->where('id', $lot->id)->where('status', 'available')->update(['status' => 'expired']);

                $audit->recordTable('stock_lots', (int) $lot->id, 'status_changed', ['status' => 'available'], [
                    'status' => 'expired',
                    'because' => 'expiry_date passed',
                ]);
            });
        }

        $this->info($due->count().' lot(s) expired.');

        return self::SUCCESS;
    }
}
