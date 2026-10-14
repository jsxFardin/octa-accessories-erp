<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Quality\Models\QcInspection;
use App\Support\Calculators\AqlResolver;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Notifications\Notifier;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-30 / QC1 / QC2.
 *
 * The verdict is computed from the AQL plan, never typed. The inspector chooses only the
 * disposition — and on a rejection the database itself refuses a row without one
 * (`qc_inspections_rejected_chk`), so no lot is rejected and then quietly forgotten.
 */
class QcInspectionController extends Controller
{
    use ContextualId;
    use ListsResources;

    public function __construct(
        private readonly AqlResolver $aql,
        private readonly NumberAllocator $numbers,
        private readonly FgReceiptService $fgReceipts,
        private readonly JobCardStateMachine $jobCards,
        private readonly Notifier $notifier,
        private readonly StockPostingService $posting,
    ) {}

    public function index(Request $request): Response
    {
        $query = QcInspection::query()->with(['jobCard:id,number']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['stage' => 'stage', 'result' => 'result'],
            sortable: ['number', 'inspected_on', 'result'],
            defaultSort: '-id',
        );

        return Inertia::render('Quality/Inspections/Index', [
            'inspections' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (QcInspection $inspection): array => [
                    ...$inspection->only([
                        'id', 'number', 'stage', 'lot_size', 'sample_size', 'accept_number',
                        'reject_number', 'major_found', 'minor_found', 'critical_found',
                        'dhu', 'result', 'disposition', 'inspected_on',
                    ]),
                    'job_card' => $inspection->jobCard?->number,
                ],
            ),
            'filters' => $this->listingFilters($request, ['stage', 'result']),
        ]);
    }

    /**
     * `?job_card=` / `?operation=` carry the card the inspector was sent from. The full list of
     * inspectable cards stays on the form — QC works a queue, not one card at a time.
     */
    public function create(Request $request): Response
    {
        // J6 — the lot in front of the inspector is the final operation's good
        // output. `job_cards.good_qty_running` sums every operation, so it offered a lot size of
        // 60,457 for a job that had made 30,000 labels, and the sample size follows the lot.
        $jobCards = DB::table('job_cards as jc')
            ->whereIn('jc.status', ['in_production', 'qc_pending'])
            ->orderBy('jc.number')
            ->selectRaw('jc.id, jc.number, jc.planned_qty,
                COALESCE((SELECT o.good_qty FROM job_card_operations o
                          WHERE o.job_card_id = jc.id
                          ORDER BY o.sequence_no DESC LIMIT 1), 0) as good_qty')
            ->get();

        $requestedCard = $this->contextualId($request, 'job_card', ['job_card.view_any', 'job_card.view']);
        $preselectCard = $jobCards->contains(fn ($card): bool => (int) $card->id === $requestedCard)
            ? $requestedCard
            : null;

        return Inertia::render('Quality/Inspections/Form', [
            'jobCards' => $jobCards,
            'preselect' => $preselectCard === null ? null : [
                'job_card_id' => $preselectCard,
                'job_card_operation_id' => $this->preselectOperation($request, $preselectCard),
                // The lot in front of the inspector is what the card has made so far; typing
                // it again from the screen they just left is where transposed digits enter.
                'lot_size' => (int) $jobCards->firstWhere('id', $preselectCard)->good_qty,
                'stage' => $request->string('stage')->toString() ?: null,
            ],
            // QC1 — an in-process inspection has to name the operation it clears, or the
            // successor operation has nothing to wait for.
            'operations' => DB::table('job_card_operations')
                ->whereIn('job_card_id', DB::table('job_cards')->whereIn('status', ['in_production', 'qc_pending'])->select('id'))
                ->where('requires_qc', true)
                ->orderBy('job_card_id')->orderBy('sequence_no')
                ->get(['id', 'job_card_id', 'sequence_no', 'name', 'good_qty']),
            'defects' => DB::table('defects')->where('is_active', true)
                ->orderBy('severity')->orderBy('name')
                ->get(['id', 'code', 'name', 'process', 'severity']),
            'plans' => DB::table('aql_plans')
                ->where('standard', 'ISO 2859-1')->where('inspection_level', 'II')->where('aql_value', 2.5)
                ->orderBy('lot_size_from')
                ->get(['id', 'lot_size_from', 'lot_size_to', 'sample_size', 'accept_number', 'reject_number']),
        ]);
    }

    /** The QC-flagged operation named by `?operation=`, if it belongs to the chosen card. */
    private function preselectOperation(Request $request, int $jobCardId): ?int
    {
        $id = $this->contextualId($request, 'operation', ['job_card.view_any', 'job_card.view']);

        if ($id === null) {
            return null;
        }

        $exists = DB::table('job_card_operations')
            ->where('id', $id)
            ->where('job_card_id', $jobCardId)
            ->where('requires_qc', true)
            ->exists();

        return $exists ? $id : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_card_id' => ['nullable', 'integer', 'exists:job_cards,id'],
            'lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'grn_line_id' => ['nullable', 'integer', 'exists:grn_lines,id'],
            // Stage vocabulary must match `qc_inspections_stage_chk` — the CHECK constraint is
            // the canonical list. `pre_dispatch` was a drift that the database rejected mid-save.
            'stage' => ['required', Rule::in(['incoming', 'in_process', 'final', 'pre_shipment', 'customer'])],
            'lot_size' => ['required', 'integer', 'min:1'],
            'major_found' => ['integer', 'min:0'],
            'minor_found' => ['integer', 'min:0'],
            'critical_found' => ['integer', 'min:0'],
            'disposition' => ['nullable', Rule::in(['rework', 'concession', 'downgrade', 'scrap'])],
            // QC1 — an in-process verdict releases exactly one operation, so it must name it.
            'job_card_operation_id' => [
                Rule::requiredIf(fn (): bool => $request->input('stage') === 'in_process'),
                'nullable', 'integer', 'exists:job_card_operations,id',
            ],
            'disposition_ref' => ['nullable', 'string', 'max:180'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'defects' => ['array'],
            'defects.*.defect_id' => ['required', 'integer', 'exists:defects,id'],
            'defects.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        // BR-30 — the seeded plan is the authority; the resolver falls back to the ISO table
        // only if the lookup has not been seeded.
        $seeded = DB::table('aql_plans')
            ->where('standard', 'ISO 2859-1')->where('inspection_level', 'II')->where('aql_value', 2.5)
            ->orderBy('lot_size_from')
            ->get()
            ->map(fn ($row): array => [
                'min' => (int) $row->lot_size_from,
                'max' => (int) $row->lot_size_to,
                'sample' => (int) $row->sample_size,
                'accept' => (int) $row->accept_number,
                'reject' => (int) $row->reject_number,
            ])
            ->all();

        $outcome = $this->aql->inspect(
            (int) $data['lot_size'],
            (int) ($data['major_found'] ?? 0),
            (int) ($data['critical_found'] ?? 0),
            $seeded ?: null,
            (int) ($data['minor_found'] ?? 0),
        );

        // QC1 — the inspection names the plan row its sample came from, so an auditor can
        // re-derive the verdict. The lookup had the rows and never kept the id.
        $aqlPlanId = DB::table('aql_plans')
            ->where('standard', 'ISO 2859-1')->where('inspection_level', 'II')->where('aql_value', 2.5)
            ->where('lot_size_from', '<=', (int) $data['lot_size'])
            ->where('lot_size_to', '>=', (int) $data['lot_size'])
            ->value('id');

        // BR-33 / QC2 — mirrored here so the message is useful; the CHECK constraint is what
        // makes it impossible.
        if ($outcome['result'] === 'rejected' && blank($data['disposition'])) {
            return back()->with('error', 'BR-33: a rejected lot needs a disposition — rework, concession, downgrade or scrap.');
        }

        $inspection = DB::transaction(function () use ($data, $outcome, $aqlPlanId, $request): QcInspection {
            // QL-1 AC1 — a lot received into quarantine already has its inspection waiting,
            // raised by the GRN posting with the number allocated. The verdict lands on that
            // row rather than beside it, so the queue of pending incoming inspections empties
            // as the inspector works through it.
            $pending = $data['stage'] === 'incoming' && ! empty($data['lot_id'])
                ? QcInspection::query()->where('stage', 'incoming')->where('lot_id', $data['lot_id'])->where('result', 'pending')->first()
                : null;

            $inspection = $pending ?? new QcInspection(['number' => $this->numbers->next('qc_inspection')]);
            $inspection->fill([
                'aql_plan_id' => $aqlPlanId,
                'job_card_id' => $data['job_card_id'] ?? null,
                'job_card_operation_id' => $data['job_card_operation_id'] ?? null,
                'lot_id' => $data['lot_id'] ?? null,
                'grn_line_id' => $data['grn_line_id'] ?? null,
                'stage' => $data['stage'],
                'lot_size' => $data['lot_size'],
                'sample_size' => $outcome['sample_size'],
                'accept_number' => $outcome['accept_number'],
                'reject_number' => $outcome['reject_number'],
                'major_found' => $data['major_found'] ?? 0,
                'minor_found' => $data['minor_found'] ?? 0,
                'critical_found' => $data['critical_found'] ?? 0,
                'dhu' => $outcome['dhu'],
                'result' => $outcome['result'],
                'disposition' => $data['disposition'] ?? null,
                'disposition_ref' => $data['disposition_ref'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'inspector_id' => $request->user()->id,
                'inspected_on' => now()->toDateString(),
                'created_by' => $request->user()->id,
            ])->save();

            foreach ($data['defects'] ?? [] as $defect) {
                DB::table('qc_inspection_defects')->insert([
                    'qc_inspection_id' => $inspection->id,
                    'defect_id' => $defect['defect_id'],
                    'qty' => $defect['qty'],
                ]);
            }

            // P0-3 — an accepted *final* verdict releases this job's quarantined FG lots.
            // A status flip, not a movement: the pieces never moved, so the ledger is silent.
            // Reject-grade receipts stay quarantined inside releaseForJob.
            if ($inspection->stage === 'final'
                && in_array($inspection->result, ['accepted', 'accepted_with_concession'], true)
                && $inspection->job_card_id !== null) {
                $this->fgReceipts->releaseForJob((int) $inspection->job_card_id);
            }

            // QL-1 AC3 — the incoming verdict moves the received lot: accepted to `available`,
            // rejected to `blocked` with its disposition owed on the NCR below. Whole-lot
            // granularity; the GRN line's accepted and rejected quantities follow.
            if ($inspection->stage === 'incoming' && $inspection->lot_id !== null) {
                $this->settleIncomingLot($inspection);
            }

            // P1-3 — a rejection is a controlled event, not a note: raise the NCR, freeze the
            // rejected batch's FG, and — on a rework disposition — put the job back on the floor.
            if ($inspection->result === 'rejected') {
                $this->onRejected($inspection, $request);
            }

            return $inspection;
        });

        return redirect()
            ->route('qc-inspections.show', $inspection)
            ->with(
                $outcome['result'] === 'rejected' ? 'warning' : 'success',
                sprintf(
                    'BR-30: sample %d, reject at %d, %d major found — %s.',
                    $outcome['sample_size'],
                    $outcome['reject_number'],
                    (int) ($data['major_found'] ?? 0),
                    strtoupper($outcome['result']),
                ),
            );
    }

    /**
     * P1-3 — the three consequences of a rejection, inside the store transaction.
     *
     * The rework quantity is the inspected lot size from the QC record itself — never a
     * number the client typed separately.
     */
    private function onRejected(QcInspection $inspection, Request $request): void
    {
        // 1. The NCR, on the existing schema — severity from what the inspection found.
        // Eloquent so Auditable records creation; the row shape is unchanged from P1-3.
        $ncr = Ncr::query()->create([
            'number' => $this->numbers->next('ncr'),
            'source' => $inspection->stage,
            'qc_inspection_id' => $inspection->id,
            'job_card_id' => $inspection->job_card_id,
            'raised_on' => now()->toDateString(),
            'description' => sprintf(
                'Inspection %s rejected %d pcs (%d critical / %d major found, reject at %d). Disposition: %s.%s',
                $inspection->number,
                $inspection->lot_size,
                $inspection->critical_found,
                $inspection->major_found,
                $inspection->reject_number,
                $inspection->disposition,
                filled($inspection->remarks) ? ' '.$inspection->remarks : '',
            ),
            'severity' => $inspection->critical_found > 0 ? 'critical' : 'major',
            'status' => Ncr::OPEN,
            'raised_by' => $request->user()->id,
        ]);

        // Raised with no owner yet, so nothing else would have told Quality it exists.
        $this->notifier->notifyNcrRaised($ncr);

        if ($inspection->job_card_id === null) {
            return;
        }

        // 2. Freeze the rejected batch: this job's quarantined FG is blocked, so a later
        //    accepted inspection of reworked output cannot accidentally release it. Status
        //    flip only — the ledger stays silent.
        if ($inspection->stage === 'final') {
            DB::table('stock_lots')
                ->where('job_card_id', $inspection->job_card_id)
                ->where('kind', 'finished_goods')
                ->where('status', 'quarantine')
                ->update(['status' => 'blocked']);

            // QL-4 AC4/AC5 — the disposition moves stock, not just a status. Scrap writes the
            // waste off at lot cost; downgrade moves the pieces into a second-quality lot that
            // carries no certification claim and so can never ship under one.
            match ($inspection->disposition) {
                'scrap' => $this->scrapBlockedLots($inspection, $ncr, $request),
                'downgrade' => $this->downgradeBlockedLots($inspection, $ncr),
                default => null,
            };
        }

        // QL-2 AC2 — a failed in-process check stops the job and tells the supervisor. A
        // rework disposition is the exception: the step is reopened below and the card keeps
        // running, because the fix is to run it again.
        if ($inspection->stage === 'in_process' && $inspection->disposition !== 'rework') {
            $this->holdForFailedCheck($inspection, $ncr);
        }

        // 3. Rework goes back to the floor through the state machine — the flagged operation
        //    (or the final one) reopens, history untouched: old logs stay, new output adds.
        if ($inspection->disposition === 'rework') {
            $jobCard = JobCard::query()->findOrFail($inspection->job_card_id);

            $operationId = $inspection->job_card_operation_id
                ?? JobCardOperation::query()
                    ->where('job_card_id', $jobCard->id)
                    ->reorder('sequence_no', 'desc')
                    ->value('id');

            JobCardOperation::query()
                ->whereKey($operationId)
                ->update(['status' => JobCardOperation::READY, 'finished_at' => null]);

            if ($jobCard->status === JobCard::QC_PENDING) {
                $this->jobCards->transition($jobCard, JobCard::IN_PRODUCTION);
            }
        }
    }

    /** QL-1 AC3 — the received lot leaves quarantine one way or the other. */
    private function settleIncomingLot(QcInspection $inspection): void
    {
        $accepted = in_array($inspection->result, ['accepted', 'accepted_with_concession'], true);

        DB::table('stock_lots')
            ->where('id', $inspection->lot_id)
            ->whereIn('status', ['quarantine', 'blocked'])
            ->update(['status' => $accepted ? 'available' : 'blocked']);

        if ($inspection->grn_line_id !== null) {
            $received = (float) DB::table('grn_lines')->where('id', $inspection->grn_line_id)->value('received_qty');

            DB::table('grn_lines')->where('id', $inspection->grn_line_id)->update([
                'accepted_qty' => $accepted ? $received : 0,
                'rejected_qty' => $accepted ? 0 : $received,
            ]);
        }
    }

    /**
     * QL-4 AC5 — scrap is a waste movement at lot cost, and a waste log that carries the
     * value, so the cost of quality is a figure rather than a feeling.
     */
    private function scrapBlockedLots(QcInspection $inspection, Ncr $ncr, Request $request): void
    {
        foreach ($this->blockedLotsOf((int) $inspection->job_card_id) as $lot) {
            $qty = (float) $lot->balance_qty;

            $this->posting->post($lot, 'scrap', -$qty, $inspection, null, "QC scrap: {$inspection->number} / {$ncr->number}");

            DB::table('waste_logs')->insert([
                'job_card_id' => $inspection->job_card_id,
                'job_card_operation_id' => $inspection->job_card_operation_id,
                'item_id' => $lot->item_id,
                'lot_id' => $lot->getKey(),
                'waste_type' => 'other',
                'qty' => $qty,
                'uom_id' => $lot->uom_id,
                'value' => round($qty * (float) $lot->unit_cost, 4),
                'occurred_at' => now(),
                'reported_by' => $request->user()->id,
                'remarks' => "Scrapped at final QC ({$inspection->number}, {$ncr->number})",
            ]);

            // The posting marks a lot drawn to zero as consumed; these pieces were destroyed.
            DB::table('stock_lots')->where('id', $lot->getKey())->update(['status' => 'scrapped']);
        }
    }

    /**
     * QL-4 AC4 — the pieces move to a `second_quality` lot: same product, same cost, no
     * certification claim. BR-40 then dilutes any list it is packed into, and the picker
     * shows the kind, so it cannot be sold as first quality by accident.
     */
    private function downgradeBlockedLots(QcInspection $inspection, Ncr $ncr): void
    {
        foreach ($this->blockedLotsOf((int) $inspection->job_card_id) as $lot) {
            $qty = (float) $lot->balance_qty;
            $cost = (float) $lot->unit_cost;

            $this->posting->post($lot, 'adjustment_out', -$qty, $inspection, $cost, "Downgraded at final QC: {$inspection->number} / {$ncr->number}");

            $this->posting->receive(
                [
                    'lot_no' => $this->numbers->nextLotNumber(),
                    'product_id' => $lot->product_id,
                    'item_id' => $lot->item_id,
                    'kind' => 'second_quality',
                    'warehouse_id' => $lot->warehouse_id,
                    'uom_id' => $lot->uom_id,
                    'job_card_id' => $lot->job_card_id,
                    'received_on' => now()->toDateString(),
                    'cert_scheme' => null,
                    'cert_claim_pct' => 0,
                    'status' => 'available',
                ],
                $qty,
                $cost,
                $inspection,
                movementType: 'adjustment_in',
            );
        }
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, StockLot> */
    private function blockedLotsOf(int $jobCardId): \Illuminate\Database\Eloquent\Collection
    {
        return StockLot::query()
            ->where('job_card_id', $jobCardId)
            ->where('kind', 'finished_goods')
            ->where('status', 'blocked')
            ->where('balance_qty', '>', 0)
            ->orderBy('id')
            ->get();
    }

    /** QL-2 AC2 — the card stops until a supervisor lifts the hold with a resolution (MF-6). */
    private function holdForFailedCheck(QcInspection $inspection, Ncr $ncr): void
    {
        $jobCard = JobCard::query()->find($inspection->job_card_id);

        if ($jobCard === null || $jobCard->status !== JobCard::IN_PRODUCTION) {
            return;
        }

        $reason = sprintf('In-process QC %s rejected (%s). Disposition: %s.', $inspection->number, $ncr->number, $inspection->disposition);

        // As the system: the hold is the consequence of the verdict, not a second decision
        // by the inspector, who holds no job_card.update.
        StateMachine::asSystem(fn () => $this->jobCards->transition($jobCard, JobCard::ON_HOLD, ['hold_reason' => $reason]));

        $this->notifier->notifyJobCardHeld($jobCard, $reason);
    }

    public function show(QcInspection $inspection): Response
    {
        $inspection->load('jobCard');

        $ncr = Ncr::query()
            ->where('qc_inspection_id', $inspection->id)
            ->orderByDesc('id')
            ->first(['id', 'number', 'status', 'severity']);

        return Inertia::render('Quality/Inspections/Show', [
            'inspection' => $inspection,
            'jobCard' => $inspection->jobCard?->only(['id', 'number', 'status']),
            'ncr' => $ncr,
            'defects' => DB::table('qc_inspection_defects as d')
                ->join('defects as c', 'c.id', '=', 'd.defect_id')
                ->where('d.qc_inspection_id', $inspection->id)
                ->get(['d.id', 'd.qty', 'c.code', 'c.name', 'c.severity', 'c.process']),
        ]);
    }
}
