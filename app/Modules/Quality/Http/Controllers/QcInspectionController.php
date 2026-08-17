<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Quality\Models\QcInspection;
use App\Support\Calculators\AqlResolver;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
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
    use ListsResources;

    public function __construct(
        private readonly AqlResolver $aql,
        private readonly NumberAllocator $numbers,
        private readonly FgReceiptService $fgReceipts,
        private readonly JobCardStateMachine $jobCards,
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

    public function create(Request $request): Response
    {
        return Inertia::render('Quality/Inspections/Form', [
            'jobCards' => DB::table('job_cards')
                ->whereIn('status', ['in_production', 'qc_pending'])
                ->orderBy('number')
                ->get(['id', 'number', 'planned_qty', 'good_qty']),
            'defects' => DB::table('defects')->where('is_active', true)
                ->orderBy('severity')->orderBy('name')
                ->get(['id', 'code', 'name', 'process', 'severity']),
            'plans' => DB::table('aql_plans')
                ->where('standard', 'ISO 2859-1')->where('inspection_level', 'II')->where('aql_value', 2.5)
                ->orderBy('lot_size_from')
                ->get(['id', 'lot_size_from', 'lot_size_to', 'sample_size', 'accept_number', 'reject_number']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_card_id' => ['nullable', 'integer', 'exists:job_cards,id'],
            'lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'grn_line_id' => ['nullable', 'integer', 'exists:grn_lines,id'],
            // Stage vocabulary must match `qc_inspections_stage_chk` — the CHECK constraint is
            // the canonical list. `pre_dispatch` was a drift that the database rejected mid-save.
            'stage' => ['required', Rule::in(['incoming', 'in_process', 'final', 'pre_shipment'])],
            'lot_size' => ['required', 'integer', 'min:1'],
            'major_found' => ['integer', 'min:0'],
            'minor_found' => ['integer', 'min:0'],
            'critical_found' => ['integer', 'min:0'],
            'disposition' => ['nullable', Rule::in(['rework', 'concession', 'downgrade', 'scrap'])],
            'job_card_operation_id' => ['nullable', 'integer', 'exists:job_card_operations,id'],
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
        );

        // BR-33 / QC2 — mirrored here so the message is useful; the CHECK constraint is what
        // makes it impossible.
        if ($outcome['result'] === 'rejected' && blank($data['disposition'])) {
            return back()->with('error', 'BR-33: a rejected lot needs a disposition — rework, concession, downgrade or scrap.');
        }

        $inspection = DB::transaction(function () use ($data, $outcome, $request): QcInspection {
            $inspection = QcInspection::query()->create([
                'number' => $this->numbers->next('qc_inspection'),
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
            ]);

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
        DB::table('ncrs')->insert([
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
            'status' => 'open',
            'raised_by' => $request->user()->id,
        ]);

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

    public function show(QcInspection $inspection): Response
    {
        $inspection->load('jobCard');

        return Inertia::render('Quality/Inspections/Show', [
            'inspection' => $inspection,
            'jobCard' => $inspection->jobCard?->only(['id', 'number', 'status']),
            'defects' => DB::table('qc_inspection_defects as d')
                ->join('defects as c', 'c.id', '=', 'd.defect_id')
                ->where('d.qc_inspection_id', $inspection->id)
                ->get(['d.id', 'd.qty', 'c.code', 'c.name', 'c.severity', 'c.process']),
        ]);
    }
}
