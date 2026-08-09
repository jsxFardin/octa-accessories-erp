<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockLot;
use App\Support\Http\ListsResources;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A lot is the identity of a physical quantity: one barcode, one origin, one certification
 * claim (I5). Every carton traces back through these to a GRN, and forward to a customer.
 */
class StockLotController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = StockLot::query()->with(['item:id,code,name', 'warehouse:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['lot_no', 'barcode', 'supplier_batch_no', 'shade_code'],
            filters: ['status' => 'status', 'kind' => 'kind', 'warehouse' => 'warehouse_id', 'scheme' => 'cert_scheme'],
            sortable: ['lot_no', 'received_on', 'balance_qty', 'expiry_date'],
            defaultSort: '-id',
        );

        return Inertia::render('Inventory/Lots/Index', [
            'lots' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (StockLot $lot): array => [
                    ...$lot->only([
                        'id', 'lot_no', 'kind', 'balance_qty', 'received_qty', 'unit_cost',
                        'shade_code', 'roll_length_m', 'received_on', 'expiry_date',
                        'cert_scheme', 'cert_claim_pct', 'status', 'barcode',
                    ]),
                    'item' => $lot->item?->only(['id', 'code', 'name']),
                    'warehouse' => $lot->warehouse?->code,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'kind', 'warehouse', 'scheme']),
            'warehouses' => DB::table('warehouses')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function show(StockLot $lot): Response
    {
        $lot->load(['item', 'warehouse', 'product']);

        return Inertia::render('Inventory/Lots/Show', [
            'lot' => $lot,
            // I1/I3 — the ledger is the truth; this is the audit trail a traceability query
            // walks. Append-only, so it reads as a history rather than a current state.
            'ledger' => DB::table('stock_ledger')
                ->where('lot_id', $lot->id)
                ->orderBy('occurred_at')
                ->get(['id', 'movement_type', 'qty', 'unit_cost', 'value', 'source_type', 'source_id', 'occurred_at', 'remarks']),
            'ledgerBalance' => (float) DB::table('stock_ledger')->where('lot_id', $lot->id)->sum('qty'),
            // G6 — any carton to its lots to its GRNs, in three clicks.
            'genealogy' => [
                'parent' => $lot->parent_lot_id
                    ? DB::table('stock_lots')->where('id', $lot->parent_lot_id)->first(['id', 'lot_no', 'kind'])
                    : null,
                'children' => DB::table('stock_lots')->where('parent_lot_id', $lot->id)
                    ->get(['id', 'lot_no', 'kind', 'balance_qty']),
                'grn' => $lot->grn_line_id
                    ? DB::table('grn_lines as gl')->join('grns as g', 'g.id', '=', 'gl.grn_id')
                        ->where('gl.id', $lot->grn_line_id)
                        ->first(['g.id', 'g.number', 'g.received_on', 'gl.cert_scheme', 'gl.cert_claim_pct'])
                    : null,
                'job_card' => $lot->job_card_id
                    ? DB::table('job_cards')->where('id', $lot->job_card_id)->first(['id', 'number', 'status'])
                    : null,
            ],
        ]);
    }
}
