<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Support\Calculators\ClaimDilutionCalculator;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P0-3 — the single application-level writer for production-driven FG receipts.
 *
 * Production output becomes stock here and nowhere else: one `fg_receipts` row, one new
 * finished-goods lot, exactly one `production_output` ledger movement — posted through
 * StockPostingService, never around it. The lot starts in `quarantine` unless an accepted
 * final inspection for *this job* already exists; the quarantine→available release is a
 * status flip, never a second movement.
 *
 * Idempotency is two layers deep: a client_ref replay returns the original receipt (the
 * device-API cache pattern), and the physical ceiling — final-operation good output minus
 * what is already received — is checked under the job card's row lock, so even an evicted
 * cache cannot mint stock production never reported.
 */
class FgReceiptService
{
    private const IDEMPOTENCY_TTL_HOURS = 24;

    /** Job states from which finished output can plausibly be received. */
    private const RECEIVABLE_STATUSES = [JobCard::IN_PRODUCTION, JobCard::QC_PENDING, JobCard::COMPLETED];

    public function __construct(
        private readonly StockPostingService $posting,
        private readonly NumberAllocator $numbers,
        private readonly ClaimDilutionCalculator $coc,
    ) {}

    /**
     * Post a (possibly partial) FG receipt for a job card.
     *
     * @throws ValidationException when the job is not receivable or the quantity exceeds
     *                             the remaining receivable production (the whole transaction
     *                             rolls back — no receipt, no lot, no ledger row)
     */
    public function post(
        JobCard $jobCard,
        float $qty,
        int $warehouseId,
        string $clientRef,
        string $grade = 'A',
        ?int $qcInspectionId = null,
        int $userId = 0,
    ): FgReceipt {
        $cacheKey = 'fg_receipt:'.hash('sha256', $clientRef);

        // Layer 1 — replay: the same client_ref returns the original receipt and writes nothing.
        $existingId = Cache::get($cacheKey);

        if ($existingId !== null) {
            $existing = FgReceipt::query()->find($existingId);

            if ($existing !== null) {
                return $existing;
            }
        }

        $receipt = DB::transaction(function () use ($jobCard, $qty, $warehouseId, $grade, $qcInspectionId, $userId): FgReceipt {
            // Lock #1 — the job card. Two supervisors posting the last 4,000 pieces at the
            // same moment serialise here, so the ceiling below is checked against the truth.
            /** @var JobCard $locked */
            $locked = JobCard::query()->lockForUpdate()->findOrFail($jobCard->getKey());

            if (! in_array($locked->status, self::RECEIVABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'qty' => "FG cannot be received from a job in status [{$locked->status}].",
                ]);
            }

            if ($qty <= 0) {
                throw ValidationException::withMessages(['qty' => 'A receipt needs a positive quantity.']);
            }

            // Layer 2 — the physical ceiling. Production reported the final operation's good
            // output; the sum of posted receipts may never exceed it. This is what holds when
            // the replay cache has been evicted.
            $finalGood = $this->finalOperationGoodQty($locked);
            $alreadyReceived = (float) FgReceipt::query()
                ->where('job_card_id', $locked->getKey())
                ->where('status', 'posted')
                ->sum('qty');
            $remaining = $finalGood - $alreadyReceived;

            if ($qty > $remaining + 0.000001) {
                throw ValidationException::withMessages([
                    'qty' => sprintf(
                        'Only %s of the %s the final operation reported is still unreceived. Record more output first.',
                        rtrim(rtrim(number_format($remaining, 6, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($finalGood, 6, '.', ''), '0'), '.'),
                    ),
                ]);
            }

            $inspection = $this->resolveInspection($locked, $qcInspectionId);

            // Quarantine unless an accepted final inspection for THIS job exists; reject-grade
            // output stays in quarantine no matter what the paperwork says.
            $status = ($inspection !== null && $grade !== 'reject') ? 'available' : 'quarantine';

            $claim = $this->dilutedClaim($locked);

            /** @var FgReceipt $receipt */
            $receipt = FgReceipt::query()->create([
                'number' => $this->numbers->next('fg_receipt'),
                'job_card_id' => $locked->getKey(),
                'warehouse_id' => $warehouseId,
                'received_on' => now()->toDateString(),
                'qty' => $qty,
                'qc_inspection_id' => $inspection?->id,
                'grade' => $grade,
                'status' => 'posted',
                'created_by' => $userId ?: auth()->id(),
            ]);

            // Lock #2 happens inside: StockPostingService creates the lot and row-locks it for
            // the posting. One movement, `production_output`, and the balance caches move in
            // the same transaction — the invariants are the service's, unchanged.
            $lot = $this->posting->receive(
                [
                    'lot_no' => $this->numbers->nextLotNumber(),
                    'product_id' => $locked->product_id,
                    'kind' => 'finished_goods',
                    'warehouse_id' => $warehouseId,
                    'uom_id' => $this->pieceUomId(),
                    'job_card_id' => $locked->getKey(),
                    'received_on' => now()->toDateString(),
                    'cert_scheme' => $claim['scheme'],
                    'cert_claim_pct' => $claim['claim_pct'],
                    'status' => $status,
                ],
                $qty,
                $this->materialUnitCost($locked, $finalGood),
                $receipt,
                movementType: 'production_output',
            );

            $receipt->forceFill(['lot_id' => $lot->getKey()])->save();

            return $receipt;
        });

        // Cached only after commit — a rolled-back attempt must stay retryable.
        Cache::put($cacheKey, $receipt->getKey(), now()->addHours(self::IDEMPOTENCY_TTL_HOURS));

        return $receipt;
    }

    /**
     * An accepted final inspection has been recorded for this job: release its quarantined FG
     * lots. A status flip only — the pieces never moved, so the ledger stays silent. Lots that
     * came in as grade `reject` keep their quarantine; paperwork does not un-reject them.
     *
     * @return int number of lots released
     */
    public function releaseForJob(int $jobCardId): int
    {
        return DB::transaction(function () use ($jobCardId): int {
            $rejectLotIds = FgReceipt::query()
                ->where('job_card_id', $jobCardId)
                ->where('grade', 'reject')
                ->whereNotNull('lot_id')
                ->pluck('lot_id');

            return DB::table('stock_lots')
                ->where('job_card_id', $jobCardId)
                ->where('kind', 'finished_goods')
                ->where('status', 'quarantine')
                ->whereNotIn('id', $rejectLotIds)
                ->update(['status' => 'available']);
        });
    }

    /**
     * The FG position of a job, for the reconciliation panel: produced vs received vs
     * available, with the gap stated rather than smoothed over.
     *
     * @return array{produced: float, received: float, available: float, quarantined: float, remaining_receivable: float}
     */
    public function positionFor(JobCard $jobCard): array
    {
        $produced = $this->finalOperationGoodQty($jobCard);

        $received = (float) FgReceipt::query()
            ->where('job_card_id', $jobCard->getKey())
            ->where('status', 'posted')
            ->sum('qty');

        $byStatus = DB::table('stock_lots')
            ->where('job_card_id', $jobCard->getKey())
            ->where('kind', 'finished_goods')
            ->whereIn('status', ['available', 'quarantine'])
            ->groupBy('status')
            ->pluck(DB::raw('SUM(balance_qty)'), 'status');

        return [
            'produced' => round($produced, 6),
            'received' => round($received, 6),
            'available' => round((float) ($byStatus['available'] ?? 0), 6),
            'quarantined' => round((float) ($byStatus['quarantine'] ?? 0), 6),
            'remaining_receivable' => round(max(0, $produced - $received), 6),
        ];
    }

    /** The quantity production actually reported: the final operation's good output (P0-2). */
    private function finalOperationGoodQty(JobCard $jobCard): float
    {
        return (float) JobCardOperation::query()
            ->where('job_card_id', $jobCard->getKey())
            ->reorder('sequence_no', 'desc')
            ->value('good_qty');
    }

    /**
     * The inspection that vouches for this receipt — accepted, final-stage, and belonging to
     * this job. A passed-in id that fails any of those checks is treated as absent, not
     * trusted; when none is passed, the job's own accepted final inspection is looked up.
     */
    private function resolveInspection(JobCard $jobCard, ?int $qcInspectionId): ?object
    {
        return DB::table('qc_inspections')
            ->where('job_card_id', $jobCard->getKey())
            ->where('stage', 'final')
            ->whereIn('result', ['accepted', 'accepted_with_concession'])
            ->when($qcInspectionId !== null, fn ($q) => $q->where('id', $qcInspectionId))
            ->orderByDesc('id')
            ->first(['id']);
    }

    /**
     * BR-40 — the FG lot's certification claim is the consumption-weighted average of the
     * material lots this job actually consumed, rounded down. Mixed schemes cannot carry a
     * single claim, so they carry none. Derived from real issue lines — never invented.
     *
     * @return array{scheme: ?string, claim_pct: float}
     */
    private function dilutedClaim(JobCard $jobCard): array
    {
        $consumed = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->join('stock_lots as sl', 'sl.id', '=', 'mil.lot_id')
            ->where('mi.job_card_id', $jobCard->getKey())
            ->where('mi.status', 'posted')
            ->get(['mil.qty', 'mi.issue_type', 'sl.cert_scheme', 'sl.cert_claim_pct']);

        if ($consumed->isEmpty()) {
            return ['scheme' => null, 'claim_pct' => 0.0];
        }

        $schemes = $consumed->pluck('cert_scheme')->filter()->unique();

        if ($schemes->count() !== 1) {
            return ['scheme' => null, 'claim_pct' => 0.0];
        }

        $claim = $this->coc->dilutedClaimPct(
            $consumed->map(fn ($row): array => [
                'qty_consumed' => $this->signedIssueQty($row->issue_type, (float) $row->qty),
                'claim_pct' => (float) $row->cert_claim_pct,
            ])->all(),
        );

        return $claim > 0
            ? ['scheme' => (string) $schemes->first(), 'claim_pct' => $claim]
            : ['scheme' => null, 'claim_pct' => 0.0];
    }

    /**
     * FG lot valuation: the job's issued-material value spread over its good output. Actual,
     * traceable cost — `material_issue_lines.unit_cost` was captured from the consumed lot at
     * issue time and already carries landed cost. A job with no issues values at zero rather
     * than at a number nobody can defend.
     */
    private function materialUnitCost(JobCard $jobCard, float $finalGood): float
    {
        if ($finalGood <= 0) {
            return 0.0;
        }

        $materialValue = (float) DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $jobCard->getKey())
            ->where('mi.status', 'posted')
            ->sum(DB::raw("CASE WHEN mi.issue_type = 'return' THEN -mil.qty ELSE mil.qty END * mil.unit_cost"));

        return round($materialValue / $finalGood, 4);
    }

    /** IN-3 — a return is unused material, not additional consumption. */
    private function signedIssueQty(?string $issueType, float $qty): float
    {
        return $issueType === 'return' ? -abs($qty) : abs($qty);
    }

    /** FG is counted in pieces; the base UoM for labels. */
    private function pieceUomId(): int
    {
        return (int) DB::table('uoms')->where('code', 'pcs')->value('id');
    }
}
