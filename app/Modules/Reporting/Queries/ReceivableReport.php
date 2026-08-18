<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receivables. Outstanding is the P2-1 formula: total − received_amount − Σ(applied credits).
 * Overdue is the invoice status, or an unpaid issued invoice past due_date.
 */
class ReceivableReport extends ReportQuery
{
    public function key(): string
    {
        return 'receivables';
    }

    public function title(): string
    {
        return 'Invoice / receivables';
    }

    public function subtitle(): string
    {
        return 'total = received + credited + outstanding. Overdue uses due_date and status.';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Invoice'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'invoice_date', 'label' => 'Invoice date', 'format' => 'date'],
            ['key' => 'due_date', 'label' => 'Due', 'format' => 'date'],
            ['key' => 'total', 'label' => 'Total', 'align' => 'right', 'format' => 'money'],
            ['key' => 'received_amount', 'label' => 'Received', 'align' => 'right', 'format' => 'money'],
            ['key' => 'credited_amount', 'label' => 'Credited', 'align' => 'right', 'format' => 'money'],
            ['key' => 'outstanding_amount', 'label' => 'Outstanding', 'align' => 'right', 'format' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'is_overdue', 'label' => 'Overdue'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/invoices';
    }

    public function filterFields(): array
    {
        $statuses = ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst($s))], $statuses)],
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
            ['key' => 'overdue', 'label' => 'Overdue', 'options' => [['value' => '1', 'label' => 'Overdue only']]],
        ];
    }

    protected function base(Request $request): Builder
    {
        $credits = DB::table('credit_notes')
            ->where('status', 'applied')
            ->whereNotNull('sales_invoice_id')
            ->groupBy('sales_invoice_id')
            ->selectRaw('sales_invoice_id, SUM(amount) as amount');

        $query = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.id', '=', 'si.customer_id')
            ->leftJoinSub($credits, 'cr', 'cr.sales_invoice_id', '=', 'si.id')
            ->selectRaw("
                si.id,
                si.number,
                c.name as customer,
                si.invoice_date,
                si.due_date,
                si.total,
                si.received_amount,
                COALESCE(cr.amount, 0) as credited_amount,
                ROUND(si.total - si.received_amount - COALESCE(cr.amount, 0), 4) as outstanding_amount,
                si.status,
                CASE
                    WHEN si.status = 'overdue' THEN 'yes'
                    WHEN si.status IN ('issued', 'partially_paid')
                         AND si.due_date IS NOT NULL
                         AND si.due_date < CURDATE()
                         AND (si.total - si.received_amount - COALESCE(cr.amount, 0)) > 0.0001
                    THEN 'yes'
                    ELSE 'no'
                END as is_overdue
            ")
            ->orderByDesc('si.id');

        $this->applySearch($query, $request, 'si.number', 'c.name');
        $this->applyDate($query, $request, 'si.invoice_date');

        if ($request->query('status')) {
            $query->where('si.status', $request->query('status'));
        }

        if ($request->query('customer')) {
            $query->where('si.customer_id', $request->query('customer'));
        }

        if ($request->query('overdue') === '1') {
            $query->where(function (Builder $overdue): void {
                $overdue->where('si.status', 'overdue')
                    ->orWhere(function (Builder $pastDue): void {
                        $pastDue->whereIn('si.status', ['issued', 'partially_paid'])
                            ->whereNotNull('si.due_date')
                            ->where('si.due_date', '<', now()->toDateString())
                            ->whereRaw('(si.total - si.received_amount - COALESCE(cr.amount, 0)) > 0.0001');
                    });
            });
        }

        return $query;
    }
}
