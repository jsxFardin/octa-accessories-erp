<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\Uom;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\States\PurchaseOrderStateMachine;
use App\Support\Http\ListsResources;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders. Approval routes by value band (06-rbac §5) and quantities are rounded to
 * the item's pack multiple before the order is raised (BR-25) — ordering 1.3 cartons of yarn
 * is not a thing.
 */
class PurchaseOrderController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly PurchaseOrderStateMachine $states,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $query = PurchaseOrder::query()->with(['supplier:id,code,name'])->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'remarks'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id'],
            sortable: ['number', 'order_date', 'expected_date', 'status', 'total'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/PurchaseOrders/Index', [
            'purchase_orders' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'supplier']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Procurement/PurchaseOrders/Form', [
            'order' => null,
            // A requisition line is the usual origin of an order; carrying it through means
            // the buyer does not retype what the planner already asked for.
            'openRequisitionLines' => DB::table('purchase_requisition_lines as prl')
                ->join('purchase_requisitions as pr', 'pr.id', '=', 'prl.pr_id')
                ->join('items as i', 'i.id', '=', 'prl.item_id')
                ->where('pr.status', 'approved')
                ->whereColumn('prl.ordered_qty', '<', 'prl.qty')
                ->orderBy('prl.required_by')
                ->get([
                    'prl.id', 'prl.item_id', 'prl.uom_id', 'prl.qty', 'prl.ordered_qty',
                    'prl.required_by', 'pr.number as pr_number', 'i.code as item_code', 'i.name as item_name',
                ]),
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $order = DB::transaction(function () use ($data, $request): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                ...collect($data)->except('lines')->all(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($order, $data['lines']);

            return $order;
        });

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Purchase order saved as a draft.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load('supplier');

        return Inertia::render('Procurement/PurchaseOrders/Show', [
            'purchaseOrder' => $purchaseOrder,
            'lines' => DB::table('purchase_order_lines as pol')
                ->join('items as i', 'i.id', '=', 'pol.item_id')
                ->leftJoin('uoms as u', 'u.id', '=', 'pol.uom_id')
                ->where('pol.po_id', $purchaseOrder->id)
                ->orderBy('pol.line_no')
                ->get([
                    'pol.id', 'pol.line_no', 'i.code as item_code', 'i.name as item_name',
                    'u.code as uom', 'pol.qty', 'pol.received_qty', 'pol.rate', 'pol.amount',
                    'pol.expected_date', 'pol.cert_claim',
                ]),
            'receipts' => DB::table('grns')
                ->where('po_id', $purchaseOrder->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'received_on', 'status']),
            'availableTransitions' => $this->states->available($purchaseOrder),
            // 06-rbac §5 — say who this order needs *before* anyone presses submit.
            'approval' => $this->states->approvalBand($purchaseOrder),
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        if ($purchaseOrder->status !== 'draft') {
            abort(403, 'An approved purchase order is read-only; changes create a revision.');
        }

        $purchaseOrder->load('lines');

        return Inertia::render('Procurement/PurchaseOrders/Form', [
            'order' => $purchaseOrder,
            'openRequisitionLines' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status !== 'draft') {
            return back()->with('error', 'An approved purchase order is read-only; changes create a revision.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($purchaseOrder, $data): void {
            $purchaseOrder->update(collect($data)->except('lines')->all());
            $this->syncLines($purchaseOrder, $data['lines']);
        });

        return redirect()
            ->route('purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order updated.');
    }

    public function transition(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($purchaseOrder, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Purchase order moved to {$data['to']}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'factory_unit_id' => ['required', 'integer', 'exists:factory_units,id'],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'incoterm' => ['nullable', 'string', 'max:20'],
            'freight_amount' => ['numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.expected_date' => ['nullable', 'date'],
            'lines.*.pr_line_id' => ['nullable', 'integer', 'exists:purchase_requisition_lines,id'],
            'lines.*.cert_claim' => ['nullable', 'string', 'max:20'],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(PurchaseOrder $order, array $lines): void
    {
        DB::table('purchase_order_lines')->where('po_id', $order->id)->delete();

        $subtotal = 0.0;

        foreach ($lines as $index => $line) {
            $amount = round((float) $line['qty'] * (float) $line['rate'], 4);
            $subtotal += $amount;

            DB::table('purchase_order_lines')->insert([
                'po_id' => $order->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'pr_line_id' => $line['pr_line_id'] ?? null,
                'uom_id' => $line['uom_id'],
                'qty' => $line['qty'],
                'rate' => $line['rate'],
                'amount' => $amount,
                'expected_date' => $line['expected_date'] ?? null,
                'cert_claim' => $line['cert_claim'] ?? null,
            ]);

            // The requisition line records how much of it has been ordered, so a partially
            // covered shortage stays visible to the next buyer.
            if (! empty($line['pr_line_id'])) {
                DB::table('purchase_requisition_lines')
                    ->where('id', $line['pr_line_id'])
                    ->update(['ordered_qty' => DB::raw('ordered_qty + '.(float) $line['qty'])]);
            }
        }

        $order->forceFill([
            'subtotal' => $subtotal,
            'total' => $subtotal + (float) $order->tax_amount + (float) $order->freight_amount,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'is_approved', 'lead_time_days', 'currency_id', 'payment_term_id']),
            'units' => FactoryUnit::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name', 'is_base']),
            'paymentTerms' => PaymentTerm::query()->orderBy('net_days')->get(['id', 'code', 'name']),
            'items' => Item::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'base_uom_id', 'std_rate', 'min_order_qty', 'order_multiple']),
            'uoms' => Uom::query()->orderBy('code')->get(['id', 'code', 'name']),
            'approvalBand' => $this->settings->decimal('po_approval_band_manager', 100000),
        ];
    }
}
