<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\Carton;
use App\Modules\Dispatch\Models\CartonContent;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Dispatch\States\PackingListStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P0-4.1 — packing: the soft allocation between available FG and the challan.
 *
 * No stock moves here. Eligibility (D1) and the ceilings live in the state machine's
 * `packed` guard, under lot locks; this controller assembles the document.
 */
class PackingListController extends Controller
{
    use ListsResources;

    public function __construct(private readonly PackingListStateMachine $states) {}

    public function index(Request $request): Response
    {
        $query = PackingList::query()->with(['salesOrder:id,number']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'packed_on', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/PackingLists/Index', [
            'packing_lists' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (PackingList $list): array => [
                    ...$list->only(['id', 'number', 'packed_on', 'total_cartons', 'total_qty', 'status',
                        'cert_claim_scheme', 'cert_claim_pct']),
                    'sales_order' => $list->salesOrder?->number,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Dispatch/PackingLists/Form', [
            'orders' => DB::table('sales_orders as so')
                ->join('customers as c', 'c.id', '=', 'so.customer_id')
                ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
                ->orderByDesc('so.id')
                ->get(['so.id', 'so.number', 'so.customer_id', 'so.delivery_address_id', 'c.name as customer_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
        ]);

        $order = DB::table('sales_orders')->where('id', $data['sales_order_id'])->first();

        $list = PackingList::query()->create([
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'delivery_address_id' => $order->delivery_address_id,
            'packed_on' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('packing-lists.show', $list)
            ->with('success', 'Packing list drafted. Build cartons from available FG lots.');
    }

    public function show(PackingList $packingList): Response
    {
        $packingList->load('salesOrder');

        $contents = DB::table('carton_contents as cc')
            ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
            ->leftJoin('stock_lots as sl', 'sl.id', '=', 'cc.lot_id')
            ->leftJoin('products as p', 'p.id', '=', 'cc.product_id')
            ->where('c.packing_list_id', $packingList->id)
            ->get(['cc.id', 'cc.carton_id', 'cc.sales_order_line_id', 'cc.qty', 'cc.bundles',
                'sl.lot_no', 'sl.status as lot_status', 'sl.balance_qty', 'sl.cert_scheme',
                'p.code as product_code']);

        return Inertia::render('Dispatch/PackingLists/Show', [
            'packingList' => [
                ...$packingList->only(['id', 'number', 'packed_on', 'total_cartons', 'total_qty',
                    'gross_weight_kg', 'net_weight_kg', 'status', 'cert_claim_scheme', 'cert_claim_pct', 'remarks']),
                'sales_order' => $packingList->salesOrder?->only(['id', 'number']),
            ],
            'cartons' => $packingList->cartons->map(fn (Carton $carton): array => [
                ...$carton->only(['id', 'carton_no', 'barcode', 'gross_weight_kg', 'net_weight_kg']),
                'contents' => $contents->where('carton_id', $carton->id)->values(),
            ]),
            'orderLines' => $packingList->sales_order_id
                ? DB::table('sales_order_lines as sol')
                    ->join('products as p', 'p.id', '=', 'sol.product_id')
                    ->where('sol.sales_order_id', $packingList->sales_order_id)
                    ->get(['sol.id', 'sol.line_no', 'sol.ordered_qty', 'sol.produced_qty', 'sol.delivered_qty',
                        'sol.over_tolerance_pct', 'p.id as product_id', 'p.code as product_code'])
                : [],
            // The picker offers only what D1 will accept — and D1 re-checks anyway.
            'availableLots' => $packingList->sales_order_id
                ? DB::table('stock_lots as sl')
                    ->join('sales_order_lines as sol', 'sol.product_id', '=', 'sl.product_id')
                    ->where('sol.sales_order_id', $packingList->sales_order_id)
                    ->where('sl.kind', 'finished_goods')
                    ->where('sl.status', 'available')
                    ->where('sl.balance_qty', '>', 0)
                    ->distinct()
                    ->get(['sl.id', 'sl.lot_no', 'sl.product_id', 'sl.balance_qty', 'sl.warehouse_id',
                        'sl.cert_scheme', 'sl.cert_claim_pct'])
                : [],
            'availableTransitions' => $this->states->available($packingList),
            'challans' => DB::table('delivery_challans')->where('packing_list_id', $packingList->id)
                ->get(['id', 'number', 'status', 'challan_date']),
        ]);
    }

    /** Add a carton; its number is the next integer within this list (composite unique key). */
    public function storeCarton(Request $request, PackingList $packingList): RedirectResponse
    {
        abort_unless($packingList->status === 'draft', 422, 'Cartons can only be edited on a draft packing list.');

        $data = $request->validate([
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'net_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $next = (int) Carton::query()->where('packing_list_id', $packingList->id)->max(DB::raw('CAST(carton_no AS UNSIGNED)')) + 1;

        Carton::query()->create([
            'packing_list_id' => $packingList->id,
            'carton_no' => (string) $next,
            'barcode' => "CTN-{$packingList->id}-{$next}",
            'gross_weight_kg' => $data['gross_weight_kg'] ?? null,
            'net_weight_kg' => $data['net_weight_kg'] ?? null,
        ]);

        return back()->with('success', "Carton {$next} added.");
    }

    public function destroyCarton(PackingList $packingList, Carton $carton): RedirectResponse
    {
        abort_unless($packingList->status === 'draft', 422, 'Cartons can only be edited on a draft packing list.');
        abort_unless((int) $carton->packing_list_id === (int) $packingList->id, 404);

        $carton->delete();

        return back()->with('success', 'Carton removed.');
    }

    /**
     * Add contents to a carton. Light validation here; the authoritative D1 + ceiling checks
     * run in the `packed` guard under locks — the frontend is never trusted.
     */
    public function storeContent(Request $request, PackingList $packingList, Carton $carton): RedirectResponse
    {
        abort_unless($packingList->status === 'draft', 422, 'Contents can only be edited on a draft packing list.');
        abort_unless((int) $carton->packing_list_id === (int) $packingList->id, 404);

        $data = $request->validate([
            'sales_order_line_id' => ['nullable', 'integer', 'exists:sales_order_lines,id'],
            'lot_id' => ['required', 'integer', 'exists:stock_lots,id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'bundles' => ['nullable', 'integer', 'min:1'],
        ]);

        $lot = DB::table('stock_lots')->where('id', $data['lot_id'])->first();

        if ($lot->kind !== 'finished_goods' || $lot->status !== 'available') {
            return back()->with('error', "D1: lot {$lot->lot_no} is {$lot->status} — only available finished goods can be packed.");
        }

        if ($data['sales_order_line_id'] !== null) {
            $lineProduct = DB::table('sales_order_lines')->where('id', $data['sales_order_line_id'])->value('product_id');

            if ((int) $lineProduct !== (int) $lot->product_id) {
                return back()->with('error', 'This lot does not hold the product on that order line.');
            }
        }

        CartonContent::query()->create([
            'carton_id' => $carton->id,
            'sales_order_line_id' => $data['sales_order_line_id'] ?? null,
            'product_id' => $lot->product_id,
            'lot_id' => $lot->id,
            'qty' => $data['qty'],
            'bundles' => $data['bundles'] ?? null,
        ]);

        return back()->with('success', 'Added to carton.');
    }

    public function destroyContent(PackingList $packingList, Carton $carton, CartonContent $content): RedirectResponse
    {
        abort_unless($packingList->status === 'draft', 422, 'Contents can only be edited on a draft packing list.');
        abort_unless((int) $content->carton_id === (int) $carton->id
            && (int) $carton->packing_list_id === (int) $packingList->id, 404);

        $content->delete();

        return back()->with('success', 'Removed from carton.');
    }

    public function transition(Request $request, PackingList $packingList): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($packingList, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Packing list {$packingList->refresh()->number} is now {$data['to']}.");
    }
}
