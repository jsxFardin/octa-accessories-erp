<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use Illuminate\Support\Facades\DB;

/**
 * Why a lot is not available, in the words of the record that made it so.
 *
 * `Blocked` on its own is what turned a correct piece of history into a reported defect: an
 * auditor saw lot L260818-00010 marked blocked with a −2,000 dispatch against it and read a
 * shipment of frozen stock. The dispatch was on 18 August, the freeze on 20 August, and the
 * ledger said so all along — but the screen showed a status with no date beside a movement
 * with one, and invited exactly that reading.
 *
 * Two things in this system take a lot out of circulation, and both leave a record:
 *
 *  - a **physical count**, which snapshots the lot into `physical_count_lines` and freezes it
 *    (`PhysicalCountStateMachine`), and
 *  - a **rejected final inspection**, which freezes the job's quarantined finished goods so a
 *    later accepted inspection of reworked output cannot release the rejected batch.
 *
 * Nothing here is inferred. When neither record explains the status, this says so rather than
 * offering a plausible reason nobody wrote down.
 */
class LotHoldExplainer
{
    /** Statuses that mean "this lot is not going anywhere for now". */
    private const HELD = ['blocked', 'quarantine', 'reserved'];

    /**
     * @return array{
     *     held: bool,
     *     status: string,
     *     headline: string,
     *     since: ?string,
     *     actor: ?string,
     *     reason: ?string,
     *     reference: ?array{label: string, href: ?string},
     *     next_action: string,
     *     can_resolve: bool,
     *     movements_predating_hold: int
     * }|null
     */
    public function explain(StockLot $lot, ?User $viewer = null): ?array
    {
        $status = (string) $lot->status;

        if (! in_array($status, self::HELD, true)) {
            return null;
        }

        $hold = $this->fromPhysicalCount($lot) ?? $this->fromRejectedInspection($lot) ?? $this->unexplained($status);

        $since = $hold['since'];

        return [
            ...$hold,
            'held' => true,
            'status' => $status,
            'can_resolve' => $hold['permission'] !== null
                && ($viewer?->hasPermission($hold['permission']) ?? false),
            // The number that answers "was this shipped while frozen?" without arithmetic.
            'movements_predating_hold' => $since === null ? 0 : (int) DB::table('stock_ledger')
                ->where('lot_id', $lot->getKey())
                ->where('qty', '<', 0)
                ->where('occurred_at', '<', $since)
                ->count(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function fromPhysicalCount(StockLot $lot): ?array
    {
        $count = DB::table('physical_count_lines as pcl')
            ->join('physical_counts as pc', 'pc.id', '=', 'pcl.physical_count_id')
            ->leftJoin('users as u', 'u.id', '=', 'pc.created_by')
            ->where('pcl.lot_id', $lot->getKey())
            ->whereIn('pc.status', ['counting', 'open'])
            ->orderByDesc('pc.id')
            ->first(['pc.id', 'pc.number', 'pc.counted_on', 'pc.created_at', 'pc.status', 'u.name as opened_by', 'pcl.remarks']);

        if ($count === null) {
            return null;
        }

        return [
            'headline' => sprintf('Frozen for stock count %s.', $count->number ?? "#{$count->id}"),
            'since' => (string) $count->created_at,
            'actor' => $count->opened_by,
            'reason' => $count->remarks ?? 'Counted stock is frozen so the count and the ledger cannot move apart while counting.',
            'reference' => ['label' => $count->number ?? "Physical count #{$count->id}", 'href' => "/physical-counts/{$count->id}"],
            'next_action' => 'The lot is released when the count is posted. Post or cancel the count to free it.',
            'permission' => 'physical_count.post',
        ];
    }

    /** @return array<string, mixed>|null */
    private function fromRejectedInspection(StockLot $lot): ?array
    {
        if ($lot->job_card_id === null) {
            return null;
        }

        $inspection = DB::table('qc_inspections as q')
            ->leftJoin('employees as e', 'e.id', '=', 'q.inspector_id')
            ->where('q.job_card_id', $lot->job_card_id)
            ->where('q.stage', 'final')
            ->where('q.result', 'rejected')
            ->orderByDesc('q.id')
            ->first(['q.id', 'q.number', 'q.inspected_on', 'q.created_at', 'q.remarks', 'q.disposition', 'e.name as inspector']);

        if ($inspection === null) {
            return null;
        }

        $ncr = DB::table('ncrs')->where('job_card_id', $lot->job_card_id)->orderByDesc('id')->first(['id', 'number']);

        return [
            'headline' => sprintf('Held by rejected final inspection %s.', $inspection->number ?? "#{$inspection->id}"),
            'since' => (string) $inspection->created_at,
            'actor' => $inspection->inspector,
            'reason' => $inspection->remarks
                ?? 'Final QC rejected this job, so its finished goods are frozen until a disposition is recorded (QC2).',
            'reference' => $ncr === null
                ? ['label' => $inspection->number ?? "Inspection #{$inspection->id}", 'href' => "/qc-inspections/{$inspection->id}"]
                : ['label' => $ncr->number ?? "NCR #{$ncr->id}", 'href' => "/ncrs/{$ncr->id}"],
            'next_action' => $inspection->disposition === null
                ? 'Quality must record a disposition — rework, concession or scrap — before this stock moves again.'
                : sprintf('Disposition recorded (%s). Quality releases the lot once it is carried out.', str_replace('_', ' ', (string) $inspection->disposition)),
            'permission' => 'qc_inspection.update',
        ];
    }

    /**
     * The honest answer when nothing in the system says why.
     *
     * @return array<string, mixed>
     */
    private function unexplained(string $status): array
    {
        return [
            'headline' => match ($status) {
                'quarantine' => 'Awaiting a final QC verdict.',
                'reserved' => 'Reserved against a job card.',
                default => 'Blocked, with no record of why.',
            },
            'since' => null,
            'actor' => null,
            'reason' => match ($status) {
                'quarantine' => 'Finished goods stay in quarantine until an accepted final inspection releases them.',
                'reserved' => 'The lot is committed to production and cannot be packed or dispatched.',
                // Said plainly rather than filled in with a plausible guess.
                default => 'No physical count or rejected inspection in this system accounts for this status.',
            },
            'reference' => null,
            'next_action' => match ($status) {
                'quarantine' => 'Record the final inspection for this job to release it.',
                'reserved' => 'The reservation clears when the job card consumes or releases it.',
                default => 'Contact Quality Control to establish why this lot is blocked and to release it.',
            },
            'permission' => null,
        ];
    }
}
