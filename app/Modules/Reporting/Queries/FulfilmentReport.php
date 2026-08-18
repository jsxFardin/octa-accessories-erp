<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales / order fulfilment. Quantities match the fulfilment strip on the sales-order show
 * page (SalesOrderController): ordered/produced/delivered/invoiced from the lines; FG from
 * posted receipts and available lots; money from invoices minus applied credits.
 */
class FulfilmentReport extends ReportQuery
{
    public function key(): string
    {
        return 'fulfilment';
    }

    public function title(): string
    {
        return 'Sales / order fulfilment';
    }

    public function subtitle(): string
    {
        return 'Ordered through invoiced, using the same figures as the sales-order screen';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Order'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'order_date', 'label' => 'Order date', 'format' => 'date'],
            ['key' => 'ordered_qty', 'label' => 'Ordered', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'produced_qty', 'label' => 'Produced', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'fg_received_qty', 'label' => 'FG received', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'available_qty', 'label' => 'Available', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'dispatched_qty', 'label' => 'Dispatched', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'delivered_qty', 'label' => 'Delivered', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'invoiced_qty', 'label' => 'Invoiced', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'received_amount', 'label' => 'Received', 'align' => 'right', 'format' => 'money'],
            ['key' => 'credited_amount', 'label' => 'Credited', 'align' => 'right', 'format' => 'money'],
            ['key' => 'outstanding_amount', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'],
            ['key' => 'fulfilment_status', 'label' => 'Status', 'format' => 'status'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/sales-orders';
    }

    public function filterFields(): array
    {
        $statuses = DB::table('sales_orders')->distinct()->orderBy('status')->pluck('status');
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => $statuses->map(fn ($s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst((string) $s))])->all()],
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
        ];
    }

    protected function base(Request $request): Builder
    {
        $lines = DB::table('sales_order_lines')
            ->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(ordered_qty) as ordered_qty, SUM(produced_qty) as produced_qty, SUM(delivered_qty) as delivered_qty, SUM(invoiced_qty) as invoiced_qty');

        $fgReceived = DB::table('fg_receipts as fr')
            ->join('job_cards as jc', 'jc.id', '=', 'fr.job_card_id')
            ->join('sales_order_lines as sol', 'sol.id', '=', 'jc.sales_order_line_id')
            ->where('fr.status', 'posted')
            ->groupBy('sol.sales_order_id')
            ->selectRaw('sol.sales_order_id, SUM(fr.qty) as qty');

        $available = DB::table('stock_lots as sl')
            ->join('job_cards as jc', 'jc.id', '=', 'sl.job_card_id')
            ->join('sales_order_lines as sol', 'sol.id', '=', 'jc.sales_order_line_id')
            ->where('sl.kind', 'finished_goods')
            ->where('sl.status', 'available')
            ->groupBy('sol.sales_order_id')
            ->selectRaw('sol.sales_order_id, SUM(sl.balance_qty) as qty');

        $dispatched = DB::table('delivery_challan_lines as dcl')
            ->join('delivery_challans as dc', 'dc.id', '=', 'dcl.delivery_challan_id')
            ->whereIn('dc.status', ['issued', 'in_transit', 'delivered', 'returned'])
            ->whereNotNull('dc.sales_order_id')
            ->groupBy('dc.sales_order_id')
            ->selectRaw('dc.sales_order_id, SUM(dcl.qty) as qty');

        $invoices = DB::table('sales_invoices')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('sales_order_id')
            ->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, SUM(total) as invoiced_total, SUM(received_amount) as received_amount');

        $credits = DB::table('credit_notes as cn')
            ->join('sales_invoices as si', 'si.id', '=', 'cn.sales_invoice_id')
            ->where('cn.status', 'applied')
            ->whereNotNull('si.sales_order_id')
            ->groupBy('si.sales_order_id')
            ->selectRaw('si.sales_order_id, SUM(cn.amount) as amount');

        $query = DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoinSub($lines, 'lines', 'lines.sales_order_id', '=', 'so.id')
            ->leftJoinSub($fgReceived, 'fg', 'fg.sales_order_id', '=', 'so.id')
            ->leftJoinSub($available, 'avail', 'avail.sales_order_id', '=', 'so.id')
            ->leftJoinSub($dispatched, 'disp', 'disp.sales_order_id', '=', 'so.id')
            ->leftJoinSub($invoices, 'inv', 'inv.sales_order_id', '=', 'so.id')
            ->leftJoinSub($credits, 'cr', 'cr.sales_order_id', '=', 'so.id')
            ->selectRaw('
                so.id,
                so.number,
                c.name as customer,
                so.order_date,
                COALESCE(lines.ordered_qty, 0) as ordered_qty,
                COALESCE(lines.produced_qty, 0) as produced_qty,
                COALESCE(fg.qty, 0) as fg_received_qty,
                COALESCE(avail.qty, 0) as available_qty,
                COALESCE(disp.qty, 0) as dispatched_qty,
                COALESCE(lines.delivered_qty, 0) as delivered_qty,
                COALESCE(lines.invoiced_qty, 0) as invoiced_qty,
                COALESCE(inv.received_amount, 0) as received_amount,
                COALESCE(cr.amount, 0) as credited_amount,
                ROUND(COALESCE(inv.invoiced_total, 0) - COALESCE(inv.received_amount, 0) - COALESCE(cr.amount, 0), 4) as outstanding_amount,
                so.status as fulfilment_status
            ')
            ->orderByDesc('so.id');

        $this->applySearch($query, $request, 'so.number', 'c.name');
        $this->applyDate($query, $request, 'so.order_date');

        if ($request->query('status')) {
            $query->where('so.status', $request->query('status'));
        }

        if ($request->query('customer')) {
            $query->where('so.customer_id', $request->query('customer'));
        }

        return $query;
    }
}
