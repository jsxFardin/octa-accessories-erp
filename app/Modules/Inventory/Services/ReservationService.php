<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Manufacturing\Models\JobCard;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * P1-2 — soft claims on physical material, so two released jobs cannot both count the same
 * yarn. Reservations are NOT stock movements: the ledger never hears about them, the lot
 * balance never changes, and StockPostingService remains the only stock writer. What changes
 * is arithmetic — availability readers subtract active reservations, and the issue path
 * refuses to hand one job another job's claim.
 *
 * Row discipline: lots are locked FOR UPDATE in ascending id order (the same order the
 * packing and dispatch guards use), and rows are never deleted — consumed and released
 * reservations keep their history.
 */
class ReservationService
{
    /**
     * Reserve the job's BOM requirement at release (J1's race-safe other half).
     *
     * Idempotent: a job that already holds active reservations keeps them (a retried release
     * re-uses the claim, it does not double it). With `$allowShortfall` false, an uncoverable
     * requirement aborts the whole reservation — and with it the enclosing release — atomically.
     *
     * @return int reservation rows written
     */
    public function reserveForJob(JobCard $jobCard, bool $allowShortfall = false): int
    {
        if (DB::table('stock_reservations')->where('job_card_id', $jobCard->getKey())->where('status', 'active')->exists()) {
            return 0;
        }

        if ($jobCard->bom_id === null) {
            return 0;
        }

        $lines = DB::table('bom_lines as bl')
            ->join('boms as b', 'b.id', '=', 'bl.bom_id')
            ->where('bl.bom_id', $jobCard->bom_id)
            ->where('bl.is_optional', false)
            ->get(['bl.item_id', 'bl.qty_per_base', 'b.base_qty']);

        $written = 0;

        foreach ($lines as $line) {
            $required = (float) $line->qty_per_base * ((float) $jobCard->planned_qty / (float) $line->base_qty);

            // Candidate lots FIFO, locked ascending — deterministic against competing releases.
            $lots = DB::table('stock_lots')
                ->where('item_id', $line->item_id)
                ->where('status', 'available')
                ->where('balance_qty', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'item_id', 'warehouse_id', 'balance_qty']);

            $remaining = $required;

            foreach ($lots as $lot) {
                if ($remaining <= 0.000001) {
                    break;
                }

                $claimed = (float) DB::table('stock_reservations')
                    ->where('lot_id', $lot->id)
                    ->where('status', 'active')
                    ->sum('qty');

                $free = (float) $lot->balance_qty - $claimed;

                if ($free <= 0.000001) {
                    continue;
                }

                $take = min($free, $remaining);

                DB::table('stock_reservations')->insert([
                    'lot_id' => $lot->id,
                    'item_id' => $lot->item_id,
                    'warehouse_id' => $lot->warehouse_id,
                    'job_card_id' => $jobCard->getKey(),
                    'qty' => round($take, 6),
                    'reserved_on' => now()->toDateString(),
                    'status' => 'active',
                ]);

                $remaining -= $take;
                $written++;
            }

            if ($remaining > 0.000001 && ! $allowShortfall) {
                // Aborting here rolls back every row above with the enclosing transaction —
                // no partial reservations survive a failed release.
                throw TransitionDenied::guard(
                    'P1-2 · BR-24',
                    sprintf(
                        'Cannot reserve %s of item #%d — %s short once other jobs\' reservations are honoured.',
                        rtrim(rtrim(number_format($required, 6, '.', ''), '0'), '.'),
                        (int) $line->item_id,
                        rtrim(rtrim(number_format($remaining, 6, '.', ''), '0'), '.'),
                    ),
                );
            }
        }

        return $written;
    }

    /**
     * An issue against the job consumes its claim, oldest rows first. Rows shrink and finish
     * as `consumed`; they are never deleted.
     */
    public function consumeForIssue(int $jobCardId, int $lotId, float $qty): void
    {
        $remaining = $qty;

        $rows = DB::table('stock_reservations')
            ->where('job_card_id', $jobCardId)
            ->where('lot_id', $lotId)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($remaining <= 0.000001) {
                break;
            }

            $take = min((float) $row->qty, $remaining);
            $left = (float) $row->qty - $take;

            // `stock_reservations_qty_chk` demands qty > 0, and history is better served by
            // keeping the consumed amount on the row: a fully-issued claim flips to
            // `consumed` at its original quantity; a partial issue leaves the remainder active.
            if ($left <= 0.000001) {
                DB::table('stock_reservations')->where('id', $row->id)
                    ->update(['status' => 'consumed', 'released_on' => now()->toDateString()]);
            } else {
                DB::table('stock_reservations')->where('id', $row->id)
                    ->update(['qty' => round($left, 6)]);
            }

            $remaining -= $take;
        }
    }

    /** How much of a lot other jobs have claimed — what an issue for THIS job must not touch. */
    public function claimedByOthers(int $lotId, ?int $exceptJobCardId = null): float
    {
        return (float) DB::table('stock_reservations')
            ->where('lot_id', $lotId)
            ->where('status', 'active')
            ->when($exceptJobCardId !== null, fn ($q) => $q->where('job_card_id', '!=', $exceptJobCardId))
            ->sum('qty');
    }

    /** A cancelled or closed job gives its claim back; history rows stay. */
    public function releaseForJob(int $jobCardId): int
    {
        return DB::table('stock_reservations')
            ->where('job_card_id', $jobCardId)
            ->where('status', 'active')
            ->update(['status' => 'released', 'released_on' => now()->toDateString()]);
    }
}
