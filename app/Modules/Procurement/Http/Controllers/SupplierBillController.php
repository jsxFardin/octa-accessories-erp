<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\Models\SupplierBillLine;
use App\Modules\Procurement\States\SupplierBillStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FN-4 — match and approve a supplier bill. Bills are entered against a PO + GRN pair;
 * approval runs the three-way match. Lines default from the GRN when a GRN is chosen.
 */
class SupplierBillController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SupplierBillStateMachine $states,
    ) {}

    public function index(Request $request): Response
    {
        $query = SupplierBill::query()->with(['supplier:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'bill_no'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id'],
            sortable: ['number', 'bill_date', 'due_date', 'total', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/Bills/Index', [
            'bills' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SupplierBill $bill): array => [
                    ...$bill->only(['id', 'number', 'bill_no', 'bill_date', 'due_date', 'total', 'paid_amount', 'status']),
                    'supplier' => $bill->supplier?->name,
                    'outstanding' => round((float) $bill->total - (float) $bill->paid_amount, 2),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier']),
        ]);
    }

    public function create(Request $request): Response
    {
        $suppliers = DB::table('suppliers')
            ->where('is_active', true)
            ->orderBy('name')
            ->select(['id', 'code', 'name'])
            ->get();

        $purchaseOrders = DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'sent', 'partially_received', 'received'])
            ->orderByDesc('id')
            ->select(['id', 'number', 'supplier_id'])
            ->get();

        $grns = DB::table('grns')
            ->where('status', 'posted')
            ->orderByDesc('id')
            ->select(['id', 'number', 'po_id', 'supplier_id'])
            ->get();

        $currencies = DB::table('currencies')->orderBy('code')->select(['id', 'code', 'name'])->get();

        $prefill = null;

        if ($request->query('grn_id')) {
            $grn = Grn::query()->find($request->query('grn_id'));

            if ($grn !== null) {
                $grnLines = DB::table('grn_lines')->where('grn_id', $grn->id)->get();

                $poLines = $grn->po_id
                    ? DB::table('purchase_order_lines')->where('purchase_order_id', $grn->po_id)->get()->keyBy('item_id')
                    : collect();

                $itemIds = $grnLines->pluck('item_id')->filter()->all();
                $items = DB::table('items')->whereIn('id', $itemIds)->get()->keyBy('id');

                $prefill = [
                    'supplier_id' => $grn->supplier_id,
                    'po_id' => $grn->po_id,
                    'grn_id' => $grn->id,
                    'currency_id' => $grn->po_id ? DB::table('purchase_orders')->where('id', $grn->po_id)->value('currency_id') : null,
                    'lines' => $grnLines->values()->map(function (object $line, int $index) use ($poLines, $items): array {
                        $poLine = $line->item_id ? $poLines->get($line->item_id) : null;
                        $item = $line->item_id ? $items->get($line->item_id) : null;

                        return [
                            'line_no' => $index + 1,
                            'item_id' => $line->item_id,
                            'item_code' => $item->code ?? '',
                            'description' => $item->name ?? '',
                            'qty' => (float) $line->accepted_qty,
                            'rate' => $poLine ? (float) $poLine->rate : 0,
                            'po_rate' => $poLine ? (float) $poLine->rate : null,
                            'grn_qty' => (float) $line->accepted_qty,
                            'tax_id' => null,
                            'amount' => $poLine ? round((float) $line->accepted_qty * (float) $poLine->rate, 4) : 0,
                        ];
                    })->all(),
                ];
            }
        }

        return Inertia::render('Procurement/Bills/Form', [
            'suppliers' => $suppliers,
            'purchaseOrders' => $purchaseOrders,
            'grns' => $grns,
            'currencies' => $currencies,
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'po_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'grn_id' => ['nullable', 'integer', 'exists:grns,id'],
            'bill_no' => ['required', 'string', 'max:80'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', 'exists:items,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
        ]);

        $bill = DB::transaction(function () use ($data, $request): SupplierBill {
            $subtotal = 0;

            /** @var SupplierBill $bill */
            $bill = new SupplierBill;
            $bill->forceFill([
                ...\Illuminate\Support\Arr::except($data, ['lines']),
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
                'paid_amount' => 0,
                'status' => SupplierBill::DRAFT,
                'created_by' => $request->user()->id,
            ])->save();

            foreach ($data['lines'] as $index => $lineData) {
                $amount = round((float) $lineData['qty'] * (float) $lineData['rate'], 4);
                $subtotal += $amount;

                $line = new SupplierBillLine;
                $line->forceFill([
                    'supplier_bill_id' => $bill->id,
                    'line_no' => $index + 1,
                    'item_id' => $lineData['item_id'] ?? null,
                    'description' => $lineData['description'] ?? null,
                    'qty' => $lineData['qty'],
                    'rate' => $lineData['rate'],
                    'tax_id' => $lineData['tax_id'] ?? null,
                    'amount' => $amount,
                ])->save();
            }

            $bill->forceFill([
                'subtotal' => round($subtotal, 4),
                'total' => round($subtotal, 4),
            ])->save();

            return $bill;
        });

        return redirect()
            ->route('supplier-bills.show', $bill)
            ->with('success', 'Supplier bill created.');
    }

    public function show(SupplierBill $supplierBill): Response
    {
        $supplierBill->load(['supplier:id,code,name', 'lines', 'purchaseOrder:id,number', 'grn:id,number', 'creator:id,name']);

        $items = DB::table('items')
            ->whereIn('id', $supplierBill->lines->pluck('item_id')->filter())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $matchData = null;

        if ($supplierBill->po_id !== null && $supplierBill->grn_id !== null) {
            $poLines = DB::table('purchase_order_lines')
                ->where('purchase_order_id', $supplierBill->po_id)
                ->get()
                ->keyBy('item_id');

            $grnLines = DB::table('grn_lines')
                ->where('grn_id', $supplierBill->grn_id)
                ->get()
                ->keyBy('item_id');

            $matchData = $supplierBill->lines->map(function (SupplierBillLine $line) use ($poLines, $grnLines): ?array {
                if ($line->item_id === null) {
                    return null;
                }

                $poLine = $poLines->get($line->item_id);
                $grnLine = $grnLines->get($line->item_id);

                return [
                    'item_id' => $line->item_id,
                    'bill_qty' => (float) $line->qty,
                    'bill_rate' => (float) $line->rate,
                    'po_qty' => $poLine ? (float) $poLine->qty : null,
                    'po_rate' => $poLine ? (float) $poLine->rate : null,
                    'grn_qty' => $grnLine ? (float) $grnLine->accepted_qty : null,
                    'qty_ok' => $grnLine ? (float) $line->qty <= (float) $grnLine->accepted_qty + 0.0001 : null,
                    'rate_variance_pct' => $poLine && (float) $poLine->rate > 0
                        ? round(abs((float) $line->rate - (float) $poLine->rate) / (float) $poLine->rate * 100, 1)
                        : null,
                ];
            })->filter()->values()->all();
        }

        $payments = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.supplier_bill_id', $supplierBill->id)
            ->select(['p.id', 'p.number', 'p.payment_date', 'p.method', 'pa.amount'])
            ->orderBy('p.payment_date')
            ->get();

        return Inertia::render('Procurement/Bills/Show', [
            'bill' => [
                ...$supplierBill->only(['id', 'number', 'bill_no', 'bill_date', 'due_date', 'subtotal', 'tax_amount', 'total', 'paid_amount', 'status']),
                'supplier' => $supplierBill->supplier,
                'po_number' => $supplierBill->purchaseOrder?->number,
                'po_id' => $supplierBill->po_id,
                'grn_number' => $supplierBill->grn?->number,
                'grn_id' => $supplierBill->grn_id,
                'outstanding' => round((float) $supplierBill->total - (float) $supplierBill->paid_amount, 4),
                'created_by' => $supplierBill->creator?->name,
            ],
            'lines' => $supplierBill->lines->map(function (SupplierBillLine $line) use ($items): array {
                $item = $line->item_id ? $items->get($line->item_id) : null;

                return [
                    ...$line->only(['id', 'line_no', 'item_id', 'description', 'qty', 'rate', 'amount']),
                    'item_code' => $item !== null ? (string) $item->code : '',
                    'item_name' => $item !== null ? (string) $item->name : '',
                ];
            })->all(),
            'matchData' => $matchData,
            'payments' => $payments,
            'availableTransitions' => $this->states->available($supplierBill),
        ]);
    }

    public function transition(Request $request, SupplierBill $supplierBill): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($supplierBill, $data['to'], $request->only(['override', 'reason']));
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Bill moved to {$data['to']}.");
    }
}
