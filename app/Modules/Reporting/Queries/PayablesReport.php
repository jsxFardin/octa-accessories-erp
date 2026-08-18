<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supplier ageing — the AP mirror of the receivables report. Outstanding = total − paid_amount.
 */
class PayablesReport extends ReportQuery
{
    public function key(): string
    {
        return 'payables';
    }

    public function title(): string
    {
        return 'Supplier bills / payables';
    }

    public function subtitle(): string
    {
        return 'Outstanding = total − paid. Overdue bills past due_date.';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Bill'],
            ['key' => 'bill_no', 'label' => 'Supplier ref'],
            ['key' => 'supplier', 'label' => 'Supplier'],
            ['key' => 'bill_date', 'label' => 'Bill date', 'format' => 'date'],
            ['key' => 'due_date', 'label' => 'Due', 'format' => 'date'],
            ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'format' => 'money'],
            ['key' => 'paid_amount', 'label' => 'Paid', 'align' => 'right', 'format' => 'money'],
            ['key' => 'outstanding_amount', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'is_overdue', 'label' => 'Overdue'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/supplier-bills';
    }

    public function filterFields(): array
    {
        $statuses = ['draft', 'approved', 'partially_paid', 'paid', 'cancelled'];
        $suppliers = DB::table('suppliers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst($s))], $statuses)],
            ['key' => 'supplier', 'label' => 'Supplier', 'options' => $suppliers->map(fn ($s): array => ['value' => $s->id, 'label' => $s->name])->all()],
            ['key' => 'overdue', 'label' => 'Overdue', 'options' => [['value' => '1', 'label' => 'Overdue only']]],
        ];
    }

    protected function base(Request $request): Builder
    {
        $query = DB::table('supplier_bills as sb')
            ->join('suppliers as s', 's.id', '=', 'sb.supplier_id')
            ->selectRaw("
                sb.id,
                sb.number,
                sb.bill_no,
                s.name as supplier,
                sb.bill_date,
                sb.due_date,
                sb.total,
                sb.paid_amount,
                ROUND(sb.total - sb.paid_amount, 4) as outstanding_amount,
                sb.status,
                CASE
                    WHEN sb.status IN ('approved', 'partially_paid')
                         AND sb.due_date IS NOT NULL
                         AND sb.due_date < CURDATE()
                         AND (sb.total - sb.paid_amount) > 0.0001
                    THEN 'yes'
                    ELSE 'no'
                END as is_overdue
            ")
            ->orderByDesc('sb.id');

        $this->applySearch($query, $request, 'sb.number', 'sb.bill_no', 's.name');
        $this->applyDate($query, $request, 'sb.bill_date');

        if ($request->query('status')) {
            $query->where('sb.status', $request->query('status'));
        }

        if ($request->query('supplier')) {
            $query->where('sb.supplier_id', $request->query('supplier'));
        }

        if ($request->query('overdue') === '1') {
            $query->whereIn('sb.status', ['approved', 'partially_paid'])
                ->whereNotNull('sb.due_date')
                ->where('sb.due_date', '<', now()->toDateString())
                ->whereRaw('(sb.total - sb.paid_amount) > 0.0001');
        }

        return $query;
    }
}
