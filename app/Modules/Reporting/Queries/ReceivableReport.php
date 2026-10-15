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
    /** The ageing buckets accounts chase by: not yet due, then days past due. */
    public const BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    public const BUCKET_LABELS = [
        'current' => 'Not yet due',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        '90_plus' => 'Over 90 days',
    ];

    /** The bucket expression; `outstanding` is the P2-1 figure inlined so it works in WHERE too. */
    private const BUCKET_SQL = "CASE
        WHEN si.status IN ('draft', 'cancelled', 'paid') OR (si.total - si.received_amount - COALESCE(cr.amount, 0)) <= 0.0001 THEN NULL
        WHEN si.due_date IS NULL OR si.due_date >= CURDATE() THEN 'current'
        WHEN DATEDIFF(CURDATE(), si.due_date) <= 30 THEN '1_30'
        WHEN DATEDIFF(CURDATE(), si.due_date) <= 60 THEN '31_60'
        WHEN DATEDIFF(CURDATE(), si.due_date) <= 90 THEN '61_90'
        ELSE '90_plus'
    END";

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
            ['key' => 'currency', 'label' => 'Currency'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'is_overdue', 'label' => 'Overdue'],
            ['key' => 'days_overdue', 'label' => 'Days over', 'align' => 'right'],
            ['key' => 'ageing_bucket', 'label' => 'Ageing'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/invoices';
    }

    // BR-50 — invoices here are raised in USD and BDT. Without these the screen labelled every
    // one of them with the factory's currency and the footer added dollars to taka.
    public function currencyColumn(): ?string
    {
        return 'currency';
    }

    public function baseRateColumn(): ?string
    {
        return 'base_rate';
    }

    public function filterFields(): array
    {
        $statuses = ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'cancelled'];
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'code', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => array_map(fn (string $s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst($s))], $statuses)],
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
            ['key' => 'overdue', 'label' => 'Overdue', 'options' => [['value' => '1', 'label' => 'Overdue only']]],
            ['key' => 'bucket', 'label' => 'Ageing', 'options' => array_map(fn (string $b): array => ['value' => $b, 'label' => self::BUCKET_LABELS[$b]], self::BUCKETS)],
        ];
    }

    /**
     * The ageing strip over the list: outstanding per bucket, in the base currency, for the
     * customer and date filters in force (the bucket filter itself is left out, so the strip
     * always shows the whole picture).
     *
     * @return array<string, mixed>
     */
    public function extras(Request $request): array
    {
        $rows = $this->base($request->duplicate($request->except('bucket')))
            ->cloneWithout(['orders', 'limit', 'offset'])
            ->get(['outstanding_amount', 'base_rate', 'ageing_bucket']);

        $totals = array_fill_keys(self::BUCKETS, 0.0);

        foreach ($rows as $row) {
            if ($row->ageing_bucket !== null) {
                $totals[$row->ageing_bucket] += (float) $row->outstanding_amount * (float) $row->base_rate;
            }
        }

        return [
            'ageing' => array_map(fn (string $b): array => ['bucket' => $b, 'label' => self::BUCKET_LABELS[$b], 'amount' => round($totals[$b], 2)], self::BUCKETS),
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
            ->leftJoin('currencies as cur', 'cur.id', '=', 'si.currency_id')
            ->leftJoinSub($credits, 'cr', 'cr.sales_invoice_id', '=', 'si.id')
            ->selectRaw("
                si.id,
                si.number,
                c.name as customer,
                cur.code as currency,
                COALESCE(si.exchange_rate, 1) as base_rate,
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
                END as is_overdue,
                CASE
                    WHEN si.due_date IS NOT NULL AND si.due_date < CURDATE()
                         AND si.status NOT IN ('draft', 'cancelled', 'paid')
                         AND (si.total - si.received_amount - COALESCE(cr.amount, 0)) > 0.0001
                    THEN DATEDIFF(CURDATE(), si.due_date)
                    ELSE 0
                END as days_overdue,
                ".self::BUCKET_SQL.' as ageing_bucket
            ')
            ->orderByDesc('si.id');

        if (in_array($request->query('bucket'), self::BUCKETS, true)) {
            $query->whereRaw('('.self::BUCKET_SQL.') = ?', [$request->query('bucket')]);
        }

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
