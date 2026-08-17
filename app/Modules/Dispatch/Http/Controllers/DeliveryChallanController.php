<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Dispatch\States\DeliveryChallanStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods leave the factory on a challan; issuing it is the stock boundary (05-workflows §10).
 * The controller drafts the document from a packed list; every physical consequence lives in
 * DeliveryChallanStateMachine / DispatchService.
 */
class DeliveryChallanController extends Controller
{
    use ListsResources;

    public function __construct(private readonly DeliveryChallanStateMachine $states) {}

    public function index(Request $request): Response
    {
        $query = DeliveryChallan::query();

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'tracking_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'challan_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/Challans/Index', [
            'delivery_challans' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
        ]);
    }

    /** Draft a challan from a packed list: lines aggregate carton contents per (SO line, lot). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'packing_list_id' => ['required', 'integer', 'exists:packing_lists,id'],
            'mode' => ['required', Rule::in(['own_fleet', 'courier', 'customer_pickup', 'freight_forwarder'])],
        ]);

        $packingList = PackingList::query()->findOrFail($data['packing_list_id']);

        if ($packingList->status !== 'packed') {
            return back()->with('error', "D3: only a packed packing list can raise a challan (this one is {$packingList->status}).");
        }

        $existing = DeliveryChallan::query()
            ->where('packing_list_id', $packingList->id)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($existing) {
            return back()->with('error', 'A challan already exists for this packing list.');
        }

        $challan = DB::transaction(function () use ($packingList, $data, $request): DeliveryChallan {
            $challan = DeliveryChallan::query()->create([
                'packing_list_id' => $packingList->id,
                'sales_order_id' => $packingList->sales_order_id,
                'customer_id' => $packingList->customer_id,
                'delivery_address_id' => $packingList->delivery_address_id,
                'challan_date' => now()->toDateString(),
                'mode' => $data['mode'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            // Aggregated per (order line, lot) — quantities never duplicated across cartons.
            $lines = DB::table('carton_contents as cc')
                ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                ->where('c.packing_list_id', $packingList->id)
                ->groupBy('cc.sales_order_line_id', 'cc.lot_id', 'cc.product_id')
                ->orderBy('cc.sales_order_line_id')
                ->get([
                    'cc.sales_order_line_id', 'cc.lot_id', 'cc.product_id',
                    DB::raw('SUM(cc.qty) as qty'), DB::raw('COUNT(DISTINCT cc.carton_id) as cartons'),
                ]);

            foreach ($lines as $index => $line) {
                DB::table('delivery_challan_lines')->insert([
                    'delivery_challan_id' => $challan->id,
                    'line_no' => $index + 1,
                    'sales_order_line_id' => $line->sales_order_line_id,
                    'product_id' => $line->product_id,
                    'lot_id' => $line->lot_id,
                    'qty' => $line->qty,
                    'cartons' => $line->cartons,
                ]);
            }

            $challan->forceFill([
                'total_cartons' => (int) DB::table('cartons')->where('packing_list_id', $packingList->id)->count(),
                'total_qty' => (float) $lines->sum('qty'),
            ])->save();

            return $challan;
        });

        return redirect()
            ->route('delivery-challans.show', $challan)
            ->with('success', 'Challan drafted. Issuing it posts the dispatch and moves delivered quantities.');
    }

    public function show(DeliveryChallan $deliveryChallan): Response
    {
        $deliveryChallan->load('packingList:id,number,status,cert_claim_scheme,cert_claim_pct');

        return Inertia::render('Dispatch/Challans/Show', [
            'challan' => [
                ...$deliveryChallan->only(['id', 'number', 'challan_date', 'mode', 'courier_name',
                    'tracking_no', 'total_cartons', 'total_qty', 'status', 'gate_pass_no', 'remarks']),
                'packing_list' => $deliveryChallan->packingList?->only(['id', 'number', 'status', 'cert_claim_scheme', 'cert_claim_pct']),
                'sales_order_id' => $deliveryChallan->sales_order_id,
            ],
            'lines' => DB::table('delivery_challan_lines as dcl')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'dcl.lot_id')
                ->leftJoin('products as p', 'p.id', '=', 'dcl.product_id')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dcl.sales_order_line_id')
                ->where('dcl.delivery_challan_id', $deliveryChallan->id)
                ->orderBy('dcl.line_no')
                ->get(['dcl.id', 'dcl.line_no', 'dcl.qty', 'dcl.cartons', 'sl.lot_no', 'sl.balance_qty',
                    'p.code as product_code', 'sol.line_no as so_line_no', 'sol.ordered_qty',
                    'sol.delivered_qty', 'sol.over_tolerance_pct', 'sol.under_tolerance_pct']),
            'availableTransitions' => $this->states->available($deliveryChallan),
        ]);
    }

    public function transition(Request $request, DeliveryChallan $deliveryChallan): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'return_reason' => ['nullable', 'string', 'max:500'],
            'pod_ref' => ['nullable', 'string', 'max:120'],
            'courier_name' => ['nullable', 'string', 'max:80'],
            'tracking_no' => ['nullable', 'string', 'max:80'],
        ]);

        if (filled($data['courier_name'] ?? null) || filled($data['tracking_no'] ?? null)) {
            $deliveryChallan->fill([
                'courier_name' => $data['courier_name'] ?? $deliveryChallan->courier_name,
                'tracking_no' => $data['tracking_no'] ?? $deliveryChallan->tracking_no,
            ])->save();
        }

        try {
            $this->states->transition($deliveryChallan, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Challan {$deliveryChallan->refresh()->number} is now {$data['to']}.");
    }
}
