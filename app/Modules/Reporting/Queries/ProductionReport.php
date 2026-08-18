<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Production vs plan. Produced is the final operation's good qty (FgReceiptService);
 * FG received is posted receipts; unreceived is the same remaining_receivable gap.
 */
class ProductionReport extends ReportQuery
{
    public function key(): string
    {
        return 'production';
    }

    public function title(): string
    {
        return 'Production';
    }

    public function subtitle(): string
    {
        return 'Job cards against ordered qty, FG receipts, and efficiency where planned qty exists';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Job card'],
            ['key' => 'so_number', 'label' => 'Sales order'],
            ['key' => 'product_code', 'label' => 'Product'],
            ['key' => 'ordered_qty', 'label' => 'Ordered', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'produced_qty', 'label' => 'Produced', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'fg_received_qty', 'label' => 'FG received', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'unreceived_qty', 'label' => 'Unreceived', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'efficiency_pct', 'label' => 'Efficiency %', 'align' => 'right', 'format' => 'pct'],
            ['key' => 'machine', 'label' => 'Machine'],
            ['key' => 'operator', 'label' => 'Operator'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'actual_start', 'label' => 'Started', 'format' => 'date'],
        ];
    }

    public function documentPath(): ?string
    {
        return '/job-cards';
    }

    public function filterFields(): array
    {
        $statuses = DB::table('job_cards')->distinct()->orderBy('status')->pluck('status');
        $products = DB::table('products')->orderBy('code')->get(['id', 'code', 'name']);

        return [
            ['key' => 'status', 'label' => 'Status', 'options' => $statuses->map(fn ($s): array => ['value' => $s, 'label' => str_replace('_', ' ', ucfirst((string) $s))])->all()],
            ['key' => 'product', 'label' => 'Product', 'options' => $products->map(fn ($p): array => ['value' => $p->id, 'label' => $p->code])->all()],
        ];
    }

    protected function base(Request $request): Builder
    {
        $finalOp = DB::table('job_card_operations as jco')
            ->join(DB::raw('(SELECT job_card_id, MAX(sequence_no) as sequence_no FROM job_card_operations GROUP BY job_card_id) as last_op'), function (JoinClause $join): void {
                $join->on('last_op.job_card_id', '=', 'jco.job_card_id')
                    ->on('last_op.sequence_no', '=', 'jco.sequence_no');
            })
            ->select('jco.job_card_id', 'jco.good_qty', 'jco.machine_id');

        $receipts = DB::table('fg_receipts')
            ->where('status', 'posted')
            ->groupBy('job_card_id')
            ->selectRaw('job_card_id, SUM(qty) as qty');

        $query = DB::table('job_cards as jc')
            ->join('products as p', 'p.id', '=', 'jc.product_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'jc.sales_order_line_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->leftJoinSub($finalOp, 'final', 'final.job_card_id', '=', 'jc.id')
            ->leftJoinSub($receipts, 'fr', 'fr.job_card_id', '=', 'jc.id')
            ->leftJoin('machines as m', 'm.id', '=', 'final.machine_id')
            ->selectRaw('
                jc.id,
                jc.number,
                so.number as so_number,
                p.code as product_code,
                COALESCE(sol.ordered_qty, 0) as ordered_qty,
                COALESCE(final.good_qty, 0) as produced_qty,
                COALESCE(fr.qty, 0) as fg_received_qty,
                GREATEST(0, COALESCE(final.good_qty, 0) - COALESCE(fr.qty, 0)) as unreceived_qty,
                CASE WHEN jc.planned_qty > 0
                     THEN ROUND(COALESCE(final.good_qty, 0) / jc.planned_qty * 100, 2)
                     ELSE NULL END as efficiency_pct,
                m.code as machine,
                (
                    SELECT e.name FROM operation_logs ol
                    INNER JOIN job_card_operations jco2 ON jco2.id = ol.job_card_operation_id
                    INNER JOIN employees e ON e.id = ol.operator_id
                    WHERE jco2.job_card_id = jc.id
                    ORDER BY ol.id DESC
                    LIMIT 1
                ) as operator,
                jc.status,
                jc.actual_start
            ')
            ->orderByDesc('jc.id');

        $this->applySearch($query, $request, 'jc.number', 'so.number', 'p.code');
        $this->applyDate($query, $request, 'jc.actual_start');

        if ($request->query('status')) {
            $query->where('jc.status', $request->query('status'));
        }

        if ($request->query('product')) {
            $query->where('jc.product_id', $request->query('product'));
        }

        return $query;
    }
}
