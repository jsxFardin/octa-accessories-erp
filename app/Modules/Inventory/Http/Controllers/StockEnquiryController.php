<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Calculators\InventoryValuator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock enquiry reads `stock_balances`, the summary table, and reports its agreement with
 * `v_stock_balances`, which recomputes live from the append-only ledger.
 *
 * A difference is not corrected here. It means a posting path wrote the ledger without going
 * through StockPostingService, and it is raised as a bug (02-database-schema §4).
 */
class StockEnquiryController extends Controller
{
    public function __construct(private readonly InventoryValuator $valuator) {}

    public function __invoke(Request $request): Response
    {
        $rows = DB::table('stock_balances as sb')
            ->join('stock_lots as sl', 'sl.id', '=', 'sb.lot_id')
            ->leftJoin('items as i', 'i.id', '=', 'sb.item_id')
            ->leftJoin('products as p', 'p.id', '=', 'sb.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->when($request->query('q'), fn ($q, $term) => $q->where(function ($sub) use ($term): void {
                $sub->where('i.code', 'like', "%{$term}%")
                    ->orWhere('i.name', 'like', "%{$term}%")
                    ->orWhere('p.code', 'like', "%{$term}%")
                    ->orWhere('sb.lot_no', 'like', "%{$term}%");
            }))
            ->when($request->query('warehouse'), fn ($q, $id) => $q->where('sb.warehouse_id', $id))
            ->when($request->query('scheme'), fn ($q, $s) => $q->where('sb.cert_scheme', $s))
            ->when($request->query('nettable') === '1', fn ($q) => $q->where('w.is_nettable', true))
            ->where('sb.balance_qty', '>', 0)
            ->orderBy('i.code')
            ->orderBy('sb.received_on')
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($row): array => [
                'lot_id' => $row->lot_id,
                'lot_no' => $row->lot_no,
                'item_code' => $row->code ?? $row->product_code ?? null,
                'item_name' => $row->name ?? null,
                'warehouse' => $row->warehouse_code ?? null,
                'is_nettable' => (bool) $row->is_nettable,
                'shade_code' => $row->shade_code,
                'balance_qty' => (float) $row->balance_qty,
                'unit_cost' => (float) $row->unit_cost,
                'value' => round((float) $row->balance_qty * (float) $row->unit_cost, 4),
                'cert_scheme' => $row->cert_scheme,
                'cert_claim_pct' => (float) $row->cert_claim_pct,
                'received_on' => $row->received_on,
                'expiry_date' => $row->expiry_date,
                // BR-39 — ageing and the 30-day expiry warning, computed the same way
                // everywhere because they come from one calculator.
                'ageing_bucket' => $this->valuator->ageingBucket(CarbonImmutable::parse($row->received_on)),
                'expiry_alert' => $row->expiry_date
                    ? $this->valuator->expiryAlert(CarbonImmutable::parse($row->expiry_date))
                    : null,
            ]);

        return Inertia::render('Inventory/Stock/Index', [
            'rows' => $rows,
            'filters' => $request->only(['q', 'warehouse', 'scheme', 'nettable']),
            'warehouses' => DB::table('warehouses')->orderBy('code')->get(['id', 'code', 'name', 'kind', 'is_nettable']),
            'reconciliation' => $this->reconciliation(),
        ]);
    }

    /**
     * The defect check: summary table against live ledger. Anything listed here is a bug in a
     * posting path, not a rounding artefact.
     *
     * @return array{checked: int, mismatched: list<array<string, mixed>>}
     */
    private function reconciliation(): array
    {
        $mismatched = DB::table('stock_balances as sb')
            ->join('v_stock_balances as v', 'v.lot_id', '=', 'sb.lot_id')
            ->whereRaw('ABS(sb.balance_qty - v.balance_qty) > 0.000001')
            ->limit(20)
            ->get(['sb.lot_id', 'sb.lot_no', 'sb.balance_qty as cached_qty', 'v.balance_qty as ledger_qty']);

        return [
            'checked' => (int) DB::table('stock_balances')->count(),
            'mismatched' => $mismatched->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
