<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Uom;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\States\PurchaseRequisitionStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase requisitions — what the factory has asked for, before anyone has agreed to buy it.
 *
 * Two origins: a planner raising one by hand, and an MRP run turning a shortage into a
 * requisition (BR-24). `origin` records which, because a buyer treats them differently.
 */
class PurchaseRequisitionController extends Controller
{
    use ListsResources;

    public function __construct(private readonly PurchaseRequisitionStateMachine $states) {}

    public function index(Request $request): Response
    {
        $query = PurchaseRequisition::query()->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'remarks'],
            filters: ['status' => 'status', 'origin' => 'origin'],
            sortable: ['number', 'requested_on', 'required_by', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/Requisitions/Index', [
            'purchase_requisitions' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'origin']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Procurement/Requisitions/Form', [
            'requisition' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $requisition = DB::transaction(function () use ($data, $request): PurchaseRequisition {
            $requisition = PurchaseRequisition::query()->create([
                ...collect($data)->except('lines')->all(),
                'origin' => 'manual',
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($requisition, $data['lines']);

            return $requisition;
        });

        return redirect()
            ->route('purchase-requisitions.show', $requisition)
            ->with('success', 'Requisition saved as a draft.');
    }

    public function show(PurchaseRequisition $purchaseRequisition): Response
    {
        return Inertia::render('Procurement/Requisitions/Show', [
            'requisition' => $purchaseRequisition,
            'lines' => DB::table('purchase_requisition_lines as prl')
                ->join('items as i', 'i.id', '=', 'prl.item_id')
                ->leftJoin('uoms as u', 'u.id', '=', 'prl.uom_id')
                ->where('prl.pr_id', $purchaseRequisition->id)
                ->orderBy('prl.line_no')
                ->get([
                    'prl.id', 'prl.line_no', 'i.code as item_code', 'i.name as item_name',
                    'u.code as uom', 'prl.qty', 'prl.ordered_qty', 'prl.required_by', 'prl.remarks',
                ]),
        ]);
    }

    public function edit(PurchaseRequisition $purchaseRequisition): Response
    {
        if ($purchaseRequisition->status !== 'draft') {
            abort(403, 'Only a draft requisition can be edited.');
        }

        $purchaseRequisition->load('lines');

        return Inertia::render('Procurement/Requisitions/Form', [
            'requisition' => $purchaseRequisition,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        if ($purchaseRequisition->status !== 'draft') {
            return back()->with('error', 'Only a draft requisition can be edited.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($purchaseRequisition, $data): void {
            $purchaseRequisition->update(collect($data)->except('lines')->all());
            $this->syncLines($purchaseRequisition, $data['lines']);
        });

        return redirect()
            ->route('purchase-requisitions.show', $purchaseRequisition)
            ->with('success', 'Requisition updated.');
    }

    /**
     * Submit and approve. Kept inline rather than in a state machine: a requisition has no
     * side effects beyond its number and an approver's name.
     */
    public function transition(Request $request, PurchaseRequisition $purchaseRequisition): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            // The guards, the numbering and the permission per target all live in the state
            // machine, so this path and the bulk one cannot drift apart.
            $this->states->transition($purchaseRequisition, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Requisition moved to {$data['to']}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'factory_unit_id' => ['required', 'integer', 'exists:factory_units,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'requested_on' => ['required', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:requested_on'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.required_by' => ['nullable', 'date'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(PurchaseRequisition $requisition, array $lines): void
    {
        DB::table('purchase_requisition_lines')->where('pr_id', $requisition->id)->delete();

        foreach ($lines as $index => $line) {
            DB::table('purchase_requisition_lines')->insert([
                'pr_id' => $requisition->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'uom_id' => $line['uom_id'],
                'qty' => $line['qty'],
                'required_by' => $line['required_by'] ?? null,
                'remarks' => $line['remarks'] ?? null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'units' => FactoryUnit::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => Department::query()->orderBy('code')->get(['id', 'code', 'name']),
            'items' => Item::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'base_uom_id', 'min_order_qty', 'order_multiple', 'safety_days']),
            'uoms' => Uom::query()->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}
