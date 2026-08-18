<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Current stock by lot. Balances come from `stock_lots.balance_qty` (the posting cache);
 * reconciliation against `v_stock_balances` is the same check as the stock enquiry screen.
 * Consumed is the absolute sum of negative ledger quantities for that lot.
 */
class StockReport extends ReportQuery
{
    public function key(): string
    {
        return 'stock';
    }

    public function title(): string
    {
        return 'Inventory / stock';
    }

    public function subtitle(): string
    {
        return 'Lot balances by status, reconciled to the ledger view';
    }

    public function columns(): array
    {
        return [
            ['key' => 'lot_no', 'label' => 'Lot'],
            ['key' => 'item_or_product', 'label' => 'Item / product'],
            ['key' => 'warehouse', 'label' => 'Warehouse'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'balance_qty', 'label' => 'Balance', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'available_qty', 'label' => 'Available', 'align' => 'right', 'format' => 'qty', 'total' => false],
            ['key' => 'quarantine_qty', 'label' => 'Quarantine', 'align' => 'right', 'format' => 'qty', 'total' => false],
            ['key' => 'blocked_qty', 'label' => 'Blocked', 'align' => 'right', 'format' => 'qty', 'total' => false],
            ['key' => 'consumed_qty', 'label' => 'Consumed', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'ledger_qty', 'label' => 'Ledger', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'received_on', 'label' => 'Received', 'format' => 'date'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/lots';
    }

    public function filterFields(): array
    {
        $warehouses = DB::table('warehouses')->orderBy('code')->get(['id', 'code', 'name']);
        $statuses = ['available', 'quarantine', 'blocked', 'reserved', 'consumed', 'expired', 'scrapped'];

        return [
            ['key' => 'warehouse', 'label' => 'Warehouse', 'options' => $warehouses->map(fn ($w): array => ['value' => $w->id, 'label' => $w->code.' — '.$w->name])->all()],
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => ucfirst($s)], $statuses)],
        ];
    }

    protected function base(Request $request): Builder
    {
        $consumed = DB::table('stock_ledger')
            ->where('qty', '<', 0)
            ->groupBy('lot_id')
            ->selectRaw('lot_id, SUM(-qty) as qty');

        $query = DB::table('stock_lots as sl')
            ->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')
            ->leftJoin('items as i', 'i.id', '=', 'sl.item_id')
            ->leftJoin('products as p', 'p.id', '=', 'sl.product_id')
            ->leftJoin('v_stock_balances as v', 'v.lot_id', '=', 'sl.id')
            ->leftJoinSub($consumed, 'cons', 'cons.lot_id', '=', 'sl.id')
            ->selectRaw("
                sl.id,
                sl.lot_no,
                COALESCE(i.code, p.code) as item_or_product,
                w.code as warehouse,
                sl.status,
                sl.balance_qty,
                CASE WHEN sl.status = 'available' THEN sl.balance_qty ELSE 0 END as available_qty,
                CASE WHEN sl.status = 'quarantine' THEN sl.balance_qty ELSE 0 END as quarantine_qty,
                CASE WHEN sl.status = 'blocked' THEN sl.balance_qty ELSE 0 END as blocked_qty,
                COALESCE(cons.qty, 0) as consumed_qty,
                COALESCE(v.balance_qty, 0) as ledger_qty,
                sl.received_on
            ")
            ->orderByDesc('sl.id');

        $this->applySearch($query, $request, 'sl.lot_no', 'i.code', 'p.code');
        $this->applyDate($query, $request, 'sl.received_on');

        if ($request->query('warehouse')) {
            $query->where('sl.warehouse_id', $request->query('warehouse'));
        }

        if ($request->query('status')) {
            $query->where('sl.status', $request->query('status'));
        }

        return $query;
    }

    public function extras(Request $request): array
    {
        $from = is_string($request->query('from')) && $request->query('from') !== ''
            ? $request->query('from')
            : now()->subDays(90)->toDateString();
        $to = is_string($request->query('to')) && $request->query('to') !== ''
            ? $request->query('to')
            : now()->toDateString();

        $movements = DB::table('stock_ledger')
            ->whereDate('occurred_at', '>=', $from)
            ->whereDate('occurred_at', '<=', $to)
            ->groupBy('movement_type')
            ->orderBy('movement_type')
            ->get([
                'movement_type',
                DB::raw('SUM(qty) as qty'),
                DB::raw('COUNT(*) as movements'),
            ]);

        $mismatched = DB::table('stock_balances as sb')
            ->join('v_stock_balances as v', 'v.lot_id', '=', 'sb.lot_id')
            ->whereRaw('ABS(sb.balance_qty - v.balance_qty) > 0.000001')
            ->limit(20)
            ->get(['sb.lot_id', 'sb.lot_no', 'sb.balance_qty as cached_qty', 'v.balance_qty as ledger_qty']);

        return [
            'movements' => $movements->map(fn ($row): array => [
                'movement_type' => $row->movement_type,
                'qty' => (float) $row->qty,
                'movements' => (int) $row->movements,
            ])->all(),
            'movement_period' => ['from' => $from, 'to' => $to],
            'reconciliation' => [
                'checked' => (int) DB::table('stock_balances')->count(),
                'mismatched' => $mismatched->map(fn ($row): array => (array) $row)->all(),
            ],
        ];
    }
}
