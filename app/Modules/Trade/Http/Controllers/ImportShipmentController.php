<?php

declare(strict_types=1);

namespace App\Modules\Trade\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Trade\Models\ImportCost;
use App\Modules\Trade\Models\ImportShipment;
use App\Modules\Trade\Services\LandedCostAllocator;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A consignment from the port to the store, and what it cost to get there.
 *
 * The screen exists because of a gap nobody notices until stock is valued: the purchase order
 * knows the supplier's rate, the goods receipt knows the quantity, and neither knows the duty
 * — which arrives weeks later on a C&F agent's bill and is often a fifth of the value. Until
 * that bill is spread across the receipts, every margin the system reports is wrong in the
 * same direction.
 */
class ImportShipmentController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly LandedCostAllocator $allocator,
    ) {}

    public function index(Request $request): Response
    {
        $query = ImportShipment::query()->with(['supplier:id,code,name', 'letterOfCredit:id,number,lc_no']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'invoice_no', 'transport_doc_no', 'bill_of_entry'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id', 'mode' => 'mode'],
            sortable: ['number', 'eta', 'status', 'goods_value'],
            defaultSort: '-id',
        );

        return Inertia::render('Trade/Shipments/Index', [
            'shipments' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (ImportShipment $shipment): array => [
                    ...$shipment->only(['id', 'number', 'invoice_no', 'transport_doc_no', 'mode',
                        'etd', 'eta', 'arrived_on', 'cleared_on', 'goods_value', 'cost_total',
                        'allocated_amount', 'status']),
                    'supplier' => $shipment->supplier?->name,
                    'lc' => $shipment->letterOfCredit->lc_no ?? $shipment->letterOfCredit?->number,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'supplier', 'mode']),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'name']),
            'modes' => ImportShipment::MODES,
            'statuses' => ImportShipment::STATUSES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Trade/Shipments/Form', ['shipment' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $shipment = DB::transaction(function () use ($data, $request): ImportShipment {
            return ImportShipment::query()->create([
                ...$data,
                'number' => $this->numbers->next('import_shipment'),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('import-shipments.show', $shipment)
            ->with('success', "Shipment {$shipment->number} created.");
    }

    public function show(ImportShipment $importShipment): Response
    {
        $importShipment->load(['supplier', 'letterOfCredit']);

        $costs = DB::table('import_costs as ic')
            ->leftJoin('suppliers as s', 's.id', '=', 'ic.supplier_id')
            ->leftJoin('currencies as c', 'c.id', '=', 'ic.currency_id')
            ->where('ic.shipment_id', $importShipment->id)
            ->orderBy('ic.incurred_on')->orderBy('ic.id')
            ->get([
                'ic.id', 'ic.cost_type', 'ic.description', 'ic.reference_no', 'ic.incurred_on',
                'ic.amount', 'ic.base_amount', 'ic.is_allocable', 'ic.exchange_rate',
                's.name as vendor', 'c.code as currency',
            ]);

        return Inertia::render('Trade/Shipments/Show', [
            'shipment' => [
                ...$importShipment->toArray(),
                'supplier_name' => $importShipment->supplier?->name,
                'lc_number' => $importShipment->letterOfCredit->lc_no ?? $importShipment->letterOfCredit?->number,
                'lc_id' => $importShipment->lc_id,
            ],
            'costs' => $costs,
            'receipts' => DB::table('grns as g')
                ->leftJoin('warehouses as w', 'w.id', '=', 'g.warehouse_id')
                ->where('g.import_shipment_id', $importShipment->id)
                ->orderBy('g.id')
                ->get(['g.id', 'g.number', 'g.received_on', 'g.status', 'w.name as warehouse']),
            // The arithmetic behind every landed rate, so a lot's cost can be explained rather
            // than asserted.
            'allocations' => DB::table('landed_cost_allocations as a')
                ->join('grn_lines as gl', 'gl.id', '=', 'a.grn_line_id')
                ->join('grns as g', 'g.id', '=', 'gl.grn_id')
                ->join('items as i', 'i.id', '=', 'gl.item_id')
                ->join('import_costs as ic', 'ic.id', '=', 'a.import_cost_id')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'a.stock_lot_id')
                ->where('a.shipment_id', $importShipment->id)
                ->orderBy('gl.id')
                ->get([
                    'a.id', 'a.amount', 'a.basis', 'a.basis_value', 'ic.cost_type',
                    'g.number as grn_number', 'i.code as item_code', 'i.name as item_name',
                    'gl.received_qty', 'gl.rate', 'gl.landed_rate', 'sl.lot_no',
                ]),
            // Receipts from this supplier not yet claimed by any shipment — the usual way a
            // GRN gets linked, since the store keeper receives before the file is opened.
            'linkableReceipts' => DB::table('grns')
                ->where('supplier_id', $importShipment->supplier_id)
                ->whereNull('import_shipment_id')
                ->whereNotIn('status', ['cancelled'])
                ->orderByDesc('id')->limit(50)
                ->get(['id', 'number', 'received_on', 'status']),
            'costTypes' => ImportCost::TYPES,
            'allocableTypes' => ImportCost::ALLOCABLE_TYPES,
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'vendors' => DB::table('suppliers')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => ImportShipment::STATUSES,
        ]);
    }

    public function edit(ImportShipment $importShipment): Response
    {
        return Inertia::render('Trade/Shipments/Form', [
            'shipment' => $importShipment,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, ImportShipment $importShipment): RedirectResponse
    {
        $importShipment->update($this->validated($request));

        return redirect()->route('import-shipments.show', $importShipment)
            ->with('success', "Shipment {$importShipment->number} updated.");
    }

    /**
     * Arrival, clearance and closure. Dates are stamped by the transition rather than typed,
     * because the date a shipment cleared is the date somebody said so.
     */
    public function transition(Request $request, ImportShipment $importShipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ImportShipment::STATUSES)],
            'bill_of_entry' => ['nullable', 'string', 'max:60'],
            'be_date' => ['nullable', 'date'],
        ]);

        $allowed = match ($importShipment->status) {
            'draft' => ['in_transit', 'cancelled'],
            'in_transit' => ['arrived', 'cancelled'],
            'arrived' => ['cleared', 'cancelled'],
            'cleared' => ['costed', 'closed'],
            'costed' => ['closed'],
            default => [],
        };

        abort_unless(in_array($data['status'], $allowed, true), 422,
            "A shipment cannot go from {$importShipment->status} to {$data['status']}.");

        $importShipment->forceFill(array_filter([
            'status' => $data['status'],
            'arrived_on' => $data['status'] === 'arrived' ? now()->toDateString() : null,
            'cleared_on' => $data['status'] === 'cleared' ? now()->toDateString() : null,
            'bill_of_entry' => $data['bill_of_entry'] ?? null,
            'be_date' => $data['be_date'] ?? null,
        ], fn ($value): bool => $value !== null))->save();

        return back()->with('success', "Shipment {$importShipment->number} is now {$data['status']}.");
    }

    /** Add a cost against the shipment: freight, duty, the C&F agent's bill. */
    public function addCost(Request $request, ImportShipment $importShipment): RedirectResponse
    {
        $data = $request->validate([
            'cost_type' => ['required', Rule::in(ImportCost::TYPES)],
            'description' => ['nullable', 'string', 'max:180'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'expense_id' => ['nullable', 'integer', 'exists:expenses,id'],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'incurred_on' => ['required', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'not_in:0'],
            'is_allocable' => ['boolean'],
        ]);

        ImportCost::query()->create([
            ...$data,
            // Held in both: the foreign amount is what the bill says, the base amount is the
            // only figure that can be added to the others.
            'base_amount' => round((float) $data['amount'] * (float) ($data['exchange_rate'] ?? 1), 4),
            'created_by' => $request->user()->id,
            'shipment_id' => $importShipment->id,
        ]);

        $importShipment->forceFill([
            'cost_total' => round((float) $importShipment->costs()->sum('base_amount'), 4),
        ])->save();

        return back()->with('success', 'Cost recorded. Re-run the allocation to push it into stock.');
    }

    public function removeCost(ImportShipment $importShipment, ImportCost $cost): RedirectResponse
    {
        abort_unless($cost->shipment_id === $importShipment->id, 404);

        $cost->delete();

        $importShipment->forceFill([
            'cost_total' => round((float) $importShipment->costs()->sum('base_amount'), 4),
        ])->save();

        return back()->with('success', 'Cost removed. Re-run the allocation.');
    }

    /** Claim a goods receipt for this shipment, so its lines share the costs. */
    public function linkReceipt(Request $request, ImportShipment $importShipment): RedirectResponse
    {
        $data = $request->validate(['grn_id' => ['required', 'integer', 'exists:grns,id']]);

        $grn = DB::table('grns')->where('id', $data['grn_id'])->first();

        abort_unless($grn !== null && (int) $grn->supplier_id === $importShipment->supplier_id, 422,
            'That receipt is from a different supplier.');

        DB::table('grns')->where('id', $data['grn_id'])->update([
            'import_shipment_id' => $importShipment->id,
            // The shipment knows the LC number; copying it onto the receipt is what makes the
            // receipt printable as a customs document.
            'lc_no' => $importShipment->letterOfCredit->lc_no ?? $grn->lc_no,
        ]);

        return back()->with('success', "Receipt {$grn->number} linked.");
    }

    public function unlinkReceipt(ImportShipment $importShipment, int $grnId): RedirectResponse
    {
        DB::table('grns')->where('id', $grnId)->where('import_shipment_id', $importShipment->id)
            ->update(['import_shipment_id' => null]);

        return back()->with('success', 'Receipt unlinked. Re-run the allocation.');
    }

    /** BR-36 — spread the allocable costs over the receipts and write the landed rate. */
    public function allocate(Request $request, ImportShipment $importShipment): RedirectResponse
    {
        $data = $request->validate(['basis' => ['nullable', Rule::in(['value', 'qty'])]]);

        $result = $this->allocator->allocate($importShipment, $data['basis'] ?? 'value');

        if ($result['lines'] === 0) {
            return back()->with('error', 'Link at least one goods receipt before allocating.');
        }

        return back()->with('success', sprintf(
            'Allocated %s across %d line%s.',
            number_format($result['allocated'], 2),
            $result['lines'],
            $result['lines'] === 1 ? '' : 's',
        ));
    }

    public function destroy(ImportShipment $importShipment): RedirectResponse
    {
        abort_unless($importShipment->status === 'draft', 422, 'Only a draft shipment can be deleted.');

        $importShipment->delete();

        return redirect()->route('import-shipments.index')->with('success', 'Draft shipment deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'lc_id' => ['nullable', 'integer', 'exists:letters_of_credit,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'invoice_no' => ['nullable', 'string', 'max:60'],
            'invoice_date' => ['nullable', 'date'],
            'transport_doc_no' => ['nullable', 'string', 'max:60'],
            'mode' => ['required', Rule::in(ImportShipment::MODES)],
            'carrier' => ['nullable', 'string', 'max:120'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
            'bill_of_entry' => ['nullable', 'string', 'max:60'],
            'be_date' => ['nullable', 'date'],
            'port_of_loading' => ['nullable', 'string', 'max:80'],
            'port_of_discharge' => ['nullable', 'string', 'max:80'],
            'incoterm' => ['nullable', 'string', 'max:20'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['numeric', 'gt:0'],
            'goods_value' => ['numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'suppliers' => DB::table('suppliers')->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name']),
            'letters' => DB::table('letters_of_credit')
                ->whereIn('status', ['applied', 'opened', 'shipped'])
                ->orderByDesc('id')
                ->get(['id', 'number', 'lc_no', 'supplier_id', 'currency_id', 'amount']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'modes' => ImportShipment::MODES,
        ];
    }
}
