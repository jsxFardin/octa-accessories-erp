<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase register — PO lines by supplier, item, period.
 */
class PurchaseReport extends ReportQuery
{
    public function key(): string
    {
        return 'purchases';
    }

    public function title(): string
    {
        return 'Purchase register';
    }

    public function subtitle(): string
    {
        return 'PO lines by supplier and item, with received and pending quantities.';
    }

    public function columns(): array
    {
        return [
            ['key' => 'po_number', 'label' => 'PO'],
            ['key' => 'supplier', 'label' => 'Supplier'],
            ['key' => 'item_code', 'label' => 'Item'],
            ['key' => 'qty', 'label' => 'Ordered', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'received_qty', 'label' => 'Received', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'pending_qty', 'label' => 'Pending', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'rate', 'label' => 'Rate', 'align' => 'right', 'format' => 'money'],
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'money'],
            ['key' => 'po_date', 'label' => 'PO date', 'format' => 'date'],
            ['key' => 'po_status', 'label' => 'Status', 'format' => 'status'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/purchase-orders';
    }

    public function filterFields(): array
    {
        $suppliers = DB::table('suppliers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'supplier', 'label' => 'Supplier', 'options' => $suppliers->map(fn ($s): array => ['value' => $s->id, 'label' => $s->name])->all()],
        ];
    }

    protected function base(Request $request): Builder
    {
        $query = DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.po_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('items as i', 'i.id', '=', 'pol.item_id')
            ->selectRaw("
                po.id,
                po.number as po_number,
                s.name as supplier,
                COALESCE(i.code, '') as item_code,
                pol.qty,
                pol.received_qty,
                GREATEST(pol.qty - pol.received_qty, 0) as pending_qty,
                pol.rate,
                pol.amount,
                po.order_date as po_date,
                po.status as po_status
            ")
            ->whereIn('po.status', ['approved', 'sent', 'partially_received', 'received', 'closed'])
            ->orderByDesc('po.id');

        $this->applySearch($query, $request, 'po.number', 's.name', 'i.code');
        $this->applyDate($query, $request, 'po.order_date');

        if ($request->query('supplier')) {
            $query->where('po.supplier_id', $request->query('supplier'));
        }

        return $query;
    }
}
