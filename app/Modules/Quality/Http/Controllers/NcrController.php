<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Quality\Services\NcrService;
use App\Modules\Quality\States\NcrStateMachine;
use App\Support\Audit\AuditLog;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P2-2 — NCR list and the investigation / CAPA / close workflow.
 *
 * Creation stays on QC rejection (P1-3). This controller never drafts an NCR by hand and
 * never writes stock.
 */
class NcrController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly NcrService $ncrs,
        private readonly NcrStateMachine $states,
    ) {}

    public function index(Request $request): Response
    {
        $query = Ncr::query()->with([
            'inspection:id,number,disposition,lot_size,result,stage',
            'jobCard:id,number,product_id',
            'jobCard.product:id,code,name',
            'owner:id,name',
        ]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: [
                'status' => 'status',
                'severity' => 'severity',
                'source' => 'source',
                'owner' => 'owner_id',
            ],
            sortable: ['number', 'raised_on', 'severity', 'status'],
            defaultSort: '-id',
        );

        if ($request->query('disposition')) {
            $query->whereHas(
                'inspection',
                fn ($inspections) => $inspections->where('disposition', $request->query('disposition')),
            );
        }

        $counts = Ncr::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $overdue = (int) DB::table('capas')
            ->join('ncrs', 'ncrs.id', '=', 'capas.ncr_id')
            ->whereNotNull('capas.due_date')
            ->where('capas.due_date', '<', now()->toDateString())
            ->whereNotIn('capas.status', ['completed', 'verified'])
            ->where('ncrs.status', '!=', Ncr::CLOSED)
            ->count();

        return Inertia::render('Quality/Ncrs/Index', [
            'ncrs' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Ncr $ncr): array => [
                    ...$ncr->only(['id', 'number', 'source', 'severity', 'status', 'raised_on']),
                    'disposition' => $ncr->inspection?->disposition,
                    'job_card' => $ncr->jobCard?->number,
                    'product' => $ncr->jobCard?->product?->only(['id', 'code', 'name']),
                    'inspection' => $ncr->inspection?->number,
                    'owner' => $ncr->owner?->name,
                    'age_days' => $ncr->raised_on->diffInDays(now()),
                ],
            ),
            'counts' => [
                'open' => (int) ($counts[Ncr::OPEN] ?? 0),
                'investigating' => (int) ($counts[Ncr::INVESTIGATING] ?? 0),
                'action_taken' => (int) ($counts[Ncr::ACTION_TAKEN] ?? 0),
                'verified' => (int) ($counts[Ncr::VERIFIED] ?? 0),
                'closed' => (int) ($counts[Ncr::CLOSED] ?? 0),
                'overdue' => $overdue,
            ],
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $this->listingFilters($request, ['status', 'severity', 'source', 'owner', 'disposition']),
        ]);
    }

    public function show(Ncr $ncr): Response
    {
        $ncr->load([
            'inspection',
            'jobCard.product:id,code,name',
            'jobCard.salesOrderLine.salesOrder:id,number',
            'owner:id,name',
            'raiser:id,name',
            'capas.responsible:id,name',
            'supplier:id,code,name',
            'customer:id,code,name',
        ]);

        $operation = null;

        if ($ncr->inspection?->job_card_operation_id) {
            $operation = DB::table('job_card_operations')
                ->where('id', $ncr->inspection->job_card_operation_id)
                ->first(['id', 'code', 'name', 'sequence_no', 'status']);
        }

        $salesOrder = $ncr->jobCard?->salesOrderLine?->salesOrder;

        return Inertia::render('Quality/Ncrs/Show', [
            'ncr' => [
                ...$ncr->only([
                    'id', 'number', 'source', 'severity', 'status', 'description',
                    'raised_on', 'closed_on', 'qc_inspection_id', 'job_card_id',
                ]),
                'owner' => $ncr->owner?->only(['id', 'name']),
                'raiser' => $ncr->raiser?->only(['id', 'name']),
                'supplier' => $ncr->supplier?->only(['id', 'code', 'name']),
                'customer' => $ncr->customer?->only(['id', 'code', 'name']),
            ],
            'inspection' => $ncr->inspection ? [
                ...$ncr->inspection->only([
                    'id', 'number', 'stage', 'result', 'disposition', 'disposition_ref',
                    'lot_size', 'sample_size', 'critical_found', 'major_found', 'minor_found',
                    'remarks',
                ]),
            ] : null,
            'jobCard' => $ncr->jobCard?->only(['id', 'number', 'status']),
            'operation' => $operation,
            'product' => $ncr->jobCard?->product?->only(['id', 'code', 'name']),
            'salesOrder' => $salesOrder?->only(['id', 'number']),
            'capas' => $ncr->capas->map(fn ($capa): array => [
                ...$capa->only([
                    'id', 'kind', 'root_cause', 'action', 'due_date', 'completed_on',
                    'effectiveness', 'status',
                ]),
                'responsible' => $capa->responsible?->only(['id', 'name']),
            ]),
            'pendingAction' => $ncr->pendingAction(),
            'availableTransitions' => $this->states->available($ncr),
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'audit' => AuditLog::query()
                ->with('user:id,name')
                ->where('auditable_type', Ncr::class)
                ->where('auditable_id', $ncr->id)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (AuditLog $row): array => [
                    'id' => $row->id,
                    'event' => $row->event,
                    'old_values' => $row->old_values,
                    'new_values' => $row->new_values,
                    'created_at' => $row->created_at,
                    'user' => $row->user?->only(['id', 'name']),
                ]),
        ]);
    }

    public function assign(Request $request, Ncr $ncr): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->ncrs->assign($ncr, (int) $data['owner_id']);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "NCR {$ncr->number} assigned.");
    }

    public function investigate(Request $request, Ncr $ncr): RedirectResponse
    {
        $data = $request->validate([
            'root_cause' => ['required', 'string', 'max:4000'],
            'action' => ['required', 'string', 'max:4000'],
            'preventive_action' => ['nullable', 'string', 'max:4000'],
            'due_date' => ['nullable', 'date'],
            'responsible_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $this->ncrs->investigate($ncr, $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Investigation recorded on {$ncr->number}.");
    }

    public function disposition(Ncr $ncr): RedirectResponse
    {
        try {
            $this->ncrs->disposition($ncr);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "CAPA action recorded on {$ncr->number}.");
    }

    public function verify(Request $request, Ncr $ncr): RedirectResponse
    {
        $data = $request->validate([
            'effectiveness' => ['required', Rule::in(['effective', 'not_effective'])],
        ]);

        try {
            $this->ncrs->verify($ncr, $data['effectiveness']);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "NCR {$ncr->number} verified.");
    }

    public function close(Ncr $ncr): RedirectResponse
    {
        try {
            $this->ncrs->close($ncr);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "NCR {$ncr->refresh()->number} is closed.");
    }
}
