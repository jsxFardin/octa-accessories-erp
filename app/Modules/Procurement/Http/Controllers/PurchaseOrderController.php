<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Approval routes by value band; the bands are settings, not code (06-rbac §5).
 */
class PurchaseOrderController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = PurchaseOrder::query()->with(['supplier:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'supplier' => 'supplier_id'],
            sortable: ['number', 'order_date', 'status', 'total'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/PurchaseOrders/Index', [
            'purchase_orders' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['status', 'supplier']),
        ]);
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
        ]);
    }
}
