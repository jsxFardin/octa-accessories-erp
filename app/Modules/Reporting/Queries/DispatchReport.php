<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Delivery challans. Dispatched qty is the challan total (posted at issue). Returned qty
 * is that total when the challan is `returned` — the machine returns the whole document.
 * Current delivered on the order is `sales_order_lines.delivered_qty` (net of returns).
 */
class DispatchReport extends ReportQuery
{
    public function key(): string
    {
        return 'dispatch';
    }

    public function title(): string
    {
        return 'Dispatch / delivery';
    }

    public function subtitle(): string
    {
        return 'Challans, packing lists and net delivered qty after returns';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Challan'],
            ['key' => 'packing_list', 'label' => 'Packing list'],
            ['key' => 'so_number', 'label' => 'Sales order'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'challan_date', 'label' => 'Dispatch date', 'format' => 'date'],
            ['key' => 'dispatched_qty', 'label' => 'Dispatched', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'returned_qty', 'label' => 'Returned', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'order_delivered_qty', 'label' => 'SO delivered', 'align' => 'right', 'format' => 'qty', 'total' => false],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/delivery-challans';
    }

    public function filterFields(): array
    {
        $statuses = ['draft', 'issued', 'in_transit', 'delivered', 'returned', 'cancelled'];
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst($s))], $statuses)],
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
        ];
    }

    protected function base(Request $request): Builder
    {
        $orderDelivered = DB::table('sales_order_lines')
            ->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(delivered_qty) as qty');

        $query = DB::table('delivery_challans as dc')
            ->join('customers as c', 'c.id', '=', 'dc.customer_id')
            ->leftJoin('packing_lists as pl', 'pl.id', '=', 'dc.packing_list_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'dc.sales_order_id')
            ->leftJoinSub($orderDelivered, 'od', 'od.sales_order_id', '=', 'dc.sales_order_id')
            ->selectRaw("
                dc.id,
                dc.number,
                pl.number as packing_list,
                so.number as so_number,
                c.name as customer,
                dc.challan_date,
                dc.total_qty as dispatched_qty,
                CASE WHEN dc.status = 'returned' THEN dc.total_qty ELSE 0 END as returned_qty,
                COALESCE(od.qty, 0) as order_delivered_qty,
                dc.status
            ")
            ->orderByDesc('dc.id');

        $this->applySearch($query, $request, 'dc.number', 'pl.number', 'so.number', 'c.name');
        $this->applyDate($query, $request, 'dc.challan_date');

        if ($request->query('status')) {
            $query->where('dc.status', $request->query('status'));
        }

        if ($request->query('customer')) {
            $query->where('dc.customer_id', $request->query('customer'));
        }

        return $query;
    }
}
