<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;
use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Support\Audit\AuditLogger;
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
        private readonly AuditLogger $audit,
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
        ?string $materialWaiverReason = null,
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

        $receipt = DB::transaction(function () use ($jobCard, $qty, $warehouseId, $grade, $qcInspectionId, $userId, $materialWaiverReason): FgReceipt {
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

            // BR-48 — labels are not made out of nothing. Five job cards produced 5,000 pieces
            // apiece with no material issued against them, and their FG lots were valued at
            // 0.00 because there was no consumption to value them from. Both halves of that
            // are the same hole: output was received without the input it came from.
            $this->guardMaterial($locked, $qty, $alreadyReceived, $materialWaiverReason, $userId);

            // BR-52 — and the other half of the same hole. `guardMaterial` asks whether the
            // issued material accounts for the *pieces*; it says nothing about their *value*,
            // and it returns early for a job whose BOM has nothing mandatory on it. Such a job
            // then values at zero and posts finished goods worth nothing into stock, silently:
            // no shortage, no waiver, no trace. Twenty-five thousand pieces in this database
            // are carried at 0.00 that way, two thousand of which were dispatched.
            $unitCost = $this->materialUnitCost($locked, $finalGood);

            $this->guardValuation($locked, $qty, $unitCost, $materialWaiverReason, $userId);

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
                $unitCost,
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
     * @return array{produced: float, received: float, available: float, quarantined: float, remaining_receivable: float, material_supports: float|null, material_required: bool, material_issued_any: bool, unit_cost: float}
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

        // BR-48 — how far the issued material stretches, stated before someone tries to
        // receive past it rather than as a refusal afterwards.
        $material = $this->materialPosition($jobCard);

        return [
            'produced' => round($produced, 6),
            'received' => round($received, 6),
            'available' => round((float) ($byStatus['available'] ?? 0), 6),
            'quarantined' => round((float) ($byStatus['quarantine'] ?? 0), 6),
            'remaining_receivable' => round(max(0, $produced - $received), 6),
            'material_required' => $material['required'],
            'material_issued_any' => $material['issued_any'],
            'material_supports' => $material['supported_qty'],
            // What a piece of this job's output is worth: issued material value over good
            // output. Zero here is a fact about the job, not a rendering accident (BR-48).
            'unit_cost' => $this->materialUnitCost($jobCard, $produced),
        ];
    }

    /**
     * BR-48 — how much finished output the material actually issued to this job can account
     * for, item by item.
     *
     * The requirement is the job's own BOM, scaled the way every other screen scales it
     * (`Bom::scaleTo`). Optional lines are not required — that is what the flag on
     * `bom_lines` means. A job with no BOM, or a BOM with nothing mandatory on it, requires
     * no issue at all: some processes genuinely consume nothing from the store, and refusing
     * those would be a rule inventing work rather than preventing an error.
     *
     * @return array{
     *     required: bool,
     *     issued_any: bool,
     *     supported_qty: float|null,
     *     limiting: array{item_code: string, item_name: string, required: float, issued: float, uom: string}|null,
     *     lines: list<array{item_code: string, item_name: string, per_base: float, per_piece: float, issued: float, supports: float, uom: string}>
     * }
     */
    public function materialPosition(JobCard $jobCard): array
    {
        $empty = ['required' => false, 'issued_any' => false, 'supported_qty' => null, 'limiting' => null, 'lines' => []];

        $bom = $jobCard->bom;

        if ($bom === null) {
            return $empty;
        }

        $bomLines = DB::table('bom_lines as bl')
            ->join('items as i', 'i.id', '=', 'bl.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'bl.uom_id')
            ->where('bl.bom_id', $bom->getKey())
            ->where('bl.is_optional', false)
            ->get(['bl.item_id', 'bl.qty_per_base', 'i.code', 'i.name', 'u.code as uom']);

        if ($bomLines->isEmpty()) {
            return $empty;
        }

        // IN-3 — a return is unused material coming back, not additional consumption.
        $issued = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $jobCard->getKey())
            ->where('mi.status', 'posted')
            ->groupBy('mil.item_id')
            ->selectRaw("mil.item_id, SUM(CASE WHEN mi.issue_type = 'return' THEN -mil.qty ELSE mil.qty END) as issued_qty")
            ->pluck('issued_qty', 'item_id');

        $lines = [];
        $supported = null;
        $limiting = null;

        foreach ($bomLines as $line) {
            $perBase = (float) $line->qty_per_base;
            $issuedQty = (float) ($issued[$line->item_id] ?? 0);

            // Pieces the issue covers, computed from the unrounded ratio. `scaleTo` rounds to
            // six places, and dividing a rounded requirement back out amplifies that rounding
            // into whole pieces — which is why the *check* below compares material to
            // material and only the display goes through this number.
            $supports = $perBase > 0
                ? $issuedQty * (float) $bom->base_qty / $perBase
                : INF;

            $lines[] = [
                'item_code' => (string) $line->code,
                'item_name' => (string) $line->name,
                'per_base' => $perBase,
                'per_piece' => $bom->scaleTo($perBase, 1.0),
                'issued' => round($issuedQty, 6),
                'supports' => is_infinite($supports) ? INF : round($supports, 6),
                'uom' => (string) ($line->uom ?? ''),
            ];

            if ($supported === null || $supports < $supported) {
                $supported = $supports;
                $limiting = [
                    'item_code' => (string) $line->code,
                    'item_name' => (string) $line->name,
                    'required' => $bom->scaleTo($perBase, 1.0),
                    'issued' => round($issuedQty, 6),
                    'uom' => (string) ($line->uom ?? ''),
                ];
            }
        }

        return [
            'required' => true,
            'issued_any' => $issued->filter(fn ($qty): bool => (float) $qty > 0)->isNotEmpty(),
            'supported_qty' => $supported === null || is_infinite($supported) ? null : round($supported, 6),
            'limiting' => $limiting,
            'lines' => $lines,
        ];
    }

    /**
     * BR-48 at the moment of receipt.
     *
     * A waiver is a real thing — rework fed from a previous run, material moved by a stock
     * transfer someone will reconcile later — so it exists, but it is a named permission and
     * a typed sentence, and it lands in the audit trail beside the receipt. What it is not is
     * silence.
     *
     * @throws ValidationException
     */
    private function guardMaterial(
        JobCard $jobCard,
        float $qty,
        float $alreadyReceived,
        ?string $waiverReason,
        int $userId,
    ): void {
        $position = $this->materialPosition($jobCard);

        if (! $position['required']) {
            return;
        }

        $supported = $position['supported_qty'];
        $wantedPieces = $alreadyReceived + $qty;

        // Compared in the material's own unit, using the same `Bom::scaleTo` the issue screen
        // and the release gate use. A comparison in pieces would go through a division and
        // fail by a fraction of a label on a perfectly issued job.
        $short = collect($position['lines'])->first(
            fn (array $line): bool => $jobCard->bom->scaleTo($line['per_base'], $wantedPieces)
                > $line['issued'] + 0.000001,
        );

        if ($short === null) {
            return;
        }

        if (filled($waiverReason)) {
            $user = $userId !== 0 ? User::query()->find($userId) : auth()->user();

            if (! ($user?->hasPermission('job_card.waive_material') ?? false)) {
                throw ValidationException::withMessages([
                    'material_waiver_reason' => 'Receiving finished goods beyond what the issued material covers needs the [job_card.waive_material] permission. Ask a planner to record the waiver.',
                ]);
            }

            // Against the job card, because that is the document someone opens when they ask
            // why this job's finished goods cost what they cost.
            $this->audit->record($jobCard, 'updated', null, [
                'material_waiver_reason' => $waiverReason,
                'waived_for' => 'fg_receipt',
                'qty' => $qty,
                'material_supports_qty' => $supported,
            ]);

            return;
        }

        // The item that actually ran out, not merely the tightest one on paper.
        $limiting = $short;
        $wanted = $wantedPieces;

        throw ValidationException::withMessages([
            'qty' => $position['issued_any']
                ? sprintf(
                    'Material cannot account for %s pieces on %s. %s issued to this job covers %s pieces, and %s of %s is needed per piece. Issue the rest, or record a waiver with a reason.',
                    $this->number($wanted),
                    $jobCard->reference(),
                    trim($this->number($limiting['issued']).' '.$limiting['uom']),
                    $this->number((float) $supported),
                    trim($this->number($limiting['per_piece'], 8).' '.$limiting['uom']),
                    $limiting['item_code'],
                )
                : sprintf(
                    'No material has been issued to %s, so there is nothing these %s pieces could have been made from. Its BOM needs %s; issue it from the store, or record a waiver with a reason.',
                    $jobCard->reference(),
                    $this->number($qty),
                    collect($position['lines'])->pluck('item_code')->join(', '),
                ),
        ]);
    }

    /**
     * BR-52 — finished goods are not received at zero value without an authorised waiver.
     *
     * Stock worth nothing is an accounting event, not an absence of one: it understates
     * inventory, it makes the job's cost variance meaningless (BR-23), and when the lot is
     * dispatched it books a delivery at no cost of sale at all. It happens for two reasons,
     * and BR-48 catches neither:
     *
     *  - the job's BOM has nothing mandatory on it, so the material guard returns early and
     *    never asks where the value came from; or
     *  - material was issued but every consumed lot was itself valued at zero.
     *
     * A job that genuinely consumes nothing from the store is a real thing, so this is a
     * waiver rather than a refusal — the *same* waiver BR-48 uses, with the same permission,
     * the same typed sentence and the same audit row. A second waiver mechanism beside it
     * would be a second thing to forget to check.
     *
     * @throws ValidationException
     */
    private function guardValuation(
        JobCard $jobCard,
        float $qty,
        float $unitCost,
        ?string $waiverReason,
        int $userId,
    ): void {
        if ($unitCost > 0.0) {
            return;
        }

        if (filled($waiverReason)) {
            $user = $userId !== 0 ? User::query()->find($userId) : auth()->user();

            if (! ($user?->hasPermission('job_card.waive_material') ?? false)) {
                throw ValidationException::withMessages([
                    'material_waiver_reason' => 'Receiving finished goods with no material value needs the [job_card.waive_material] permission. Ask a planner to record the waiver.',
                ]);
            }

            $this->audit->record($jobCard, 'updated', null, [
                'material_waiver_reason' => $waiverReason,
                'waived_for' => 'fg_receipt_zero_value',
                'qty' => $qty,
                'unit_cost' => 0,
            ]);

            return;
        }

        throw ValidationException::withMessages([
            'qty' => sprintf(
                'These %s pieces would be taken into stock at no value, because no material issued to %s carries a cost. Finished goods valued at zero understate inventory and give the job no cost of sale. Issue the material it was made from, or record a waiver with a reason.',
                $this->number($qty),
                $jobCard->reference(),
            ),
        ]);
    }

    /** A DECIMAL figure the way a supervisor writes it. */
    private function number(float $value, int $decimals = 3): string
    {
        return rtrim(rtrim(number_format($value, $decimals, '.', ','), '0'), '.');
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
