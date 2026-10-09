<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Support\Scoping\FactoryUnitFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cost variance (spec §4): every closed job's actual cost against the benchmark its order
 * was confirmed at, split the way a costing clerk explains it — usage, price, conversion,
 * the rest — with the reason the clerk gave, or none yet.
 *
 * The benchmark is scaled to the quantity the job made before the two are compared. Price
 * variance is struck per material: (actual rate − benchmark rate) × actual qty, matched on
 * the item when the benchmark names one and on the cost type otherwise; usage is what is
 * left of the material variance. Every sheet is in the base currency, so the totals add.
 */
class CostVarianceReport extends ReportQuery
{
    public function key(): string
    {
        return 'cost-variance';
    }

    public function title(): string
    {
        return 'Cost variance';
    }

    public function subtitle(): string
    {
        return 'Closed jobs: actual cost against the benchmark, as usage, price and conversion, with the reason given';
    }

    public function columns(): array
    {
        return [
            ['key' => 'number', 'label' => 'Job card'],
            ['key' => 'customer', 'label' => 'Customer'],
            ['key' => 'product_code', 'label' => 'Product'],
            ['key' => 'basis_qty', 'label' => 'Made', 'align' => 'right', 'format' => 'qty'],
            ['key' => 'benchmark_unit_cost', 'label' => 'Benchmark / 1,000', 'align' => 'right', 'format' => 'money', 'total' => false],
            ['key' => 'actual_unit_cost', 'label' => 'Actual / 1,000', 'align' => 'right', 'format' => 'money', 'total' => false],
            ['key' => 'usage_variance', 'label' => 'Usage', 'align' => 'right', 'format' => 'money'],
            ['key' => 'price_variance', 'label' => 'Price', 'align' => 'right', 'format' => 'money'],
            ['key' => 'conversion_variance', 'label' => 'Conversion', 'align' => 'right', 'format' => 'money'],
            ['key' => 'other_variance', 'label' => 'Other', 'align' => 'right', 'format' => 'money'],
            ['key' => 'total_variance', 'label' => 'Total variance', 'align' => 'right', 'format' => 'money'],
            ['key' => 'actual_margin_pct', 'label' => 'Actual margin %', 'align' => 'right', 'format' => 'pct'],
            ['key' => 'variance_reason', 'label' => 'Reason', 'wrap' => true],
            ['key' => 'costed_at', 'label' => 'Costed', 'format' => 'date'],
        ];
    }

    /** A row opens the post-production sheet, where the lines behind the figures are. */
    public function documentPath(): ?string
    {
        return '/costing/sheets';
    }

    public function filterFields(): array
    {
        $customers = DB::table('customers')->orderBy('name')->get(['id', 'name']);
        $products = DB::table('products as p')->join('items as pi', 'pi.id', '=', 'p.item_id')
            ->whereNull('p.deleted_at')->orderBy('pi.code')->get(['p.id', 'pi.code']);

        return [
            ['key' => 'customer', 'label' => 'Customer', 'options' => $customers->map(fn ($c): array => ['value' => $c->id, 'label' => $c->name])->all()],
            ['key' => 'product', 'label' => 'Product', 'options' => $products->map(fn ($p): array => ['value' => $p->id, 'label' => $p->code])->all()],
            ['key' => 'explained', 'label' => 'Reason', 'options' => [
                ['value' => 'no', 'label' => 'Not yet explained'],
                ['value' => 'yes', 'label' => 'Explained'],
            ]],
        ];
    }

    protected function base(Request $request): Builder
    {
        // Material lines per sheet, grouped the way the two stages are matched.
        $materials = "SELECT l.cost_sheet_id, l.item_id, l.cost_type, SUM(l.qty) AS qty,
                SUM(l.amount) / NULLIF(SUM(l.qty), 0) AS rate
            FROM cost_sheet_lines l WHERE l.cost_type LIKE 'material%'
            GROUP BY l.cost_sheet_id, l.item_id, l.cost_type";

        // Price variance per post-production sheet: (actual rate − benchmark rate) × actual qty,
        // over the materials both sheets carry.
        $price = DB::table(DB::raw("({$materials}) AS a"))
            ->join('cost_sheets as post', fn ($join) => $join->on('post.id', '=', 'a.cost_sheet_id')->where('post.stage', 'post_production'))
            ->join('cost_sheets as pre', fn ($join) => $join->on('pre.sales_order_line_id', '=', 'post.sales_order_line_id')->where('pre.stage', 'pre_production'))
            ->join(DB::raw("({$materials}) AS p"), function ($join): void {
                $join->on('p.cost_sheet_id', '=', 'pre.id')
                    ->where(fn ($on) => $on
                        ->where(fn ($byItem) => $byItem->whereNotNull('p.item_id')->whereColumn('p.item_id', 'a.item_id'))
                        ->orWhere(fn ($byType) => $byType->whereNull('p.item_id')->whereColumn('p.cost_type', 'a.cost_type')));
            })
            ->groupBy('a.cost_sheet_id')
            ->selectRaw('a.cost_sheet_id AS post_id, SUM((a.rate - p.rate) * a.qty) AS price_variance');

        // AD-4 — scoped like the job card list is.
        $query = app(FactoryUnitFilter::class)->apply(
            DB::table('cost_sheets as cs'),
            'jc.factory_unit_id',
        )
            ->join('job_cards as jc', 'jc.id', '=', 'cs.job_card_id')
            ->join('products as p', 'p.id', '=', 'cs.product_id')
            ->join('items as pi', 'pi.id', '=', 'p.item_id')
            ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'cs.sales_order_line_id')
            ->leftJoin('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('cost_sheets as pre', fn ($join) => $join->on('pre.sales_order_line_id', '=', 'cs.sales_order_line_id')->where('pre.stage', 'pre_production'))
            ->leftJoinSub($price, 'pv', 'pv.post_id', '=', 'cs.id')
            ->where('cs.stage', 'post_production')
            ->selectRaw('
                cs.id,
                jc.number,
                c.name AS customer,
                pi.code AS product_code,
                cs.basis_qty,
                ROUND(pre.unit_cost * 1000, 2) AS benchmark_unit_cost,
                ROUND(cs.unit_cost * 1000, 2) AS actual_unit_cost,
                ROUND(cs.material_cost - pre.material_cost * (cs.basis_qty / NULLIF(pre.basis_qty, 0)) - COALESCE(pv.price_variance, 0), 4) AS usage_variance,
                ROUND(COALESCE(pv.price_variance, 0), 4) AS price_variance,
                ROUND((cs.machine_cost + cs.labour_cost + cs.energy_cost)
                    - (pre.machine_cost + pre.labour_cost + pre.energy_cost) * (cs.basis_qty / NULLIF(pre.basis_qty, 0)), 4) AS conversion_variance,
                ROUND((cs.total_cost - cs.material_cost - cs.machine_cost - cs.labour_cost - cs.energy_cost)
                    - (pre.total_cost - pre.material_cost - pre.machine_cost - pre.labour_cost - pre.energy_cost) * (cs.basis_qty / NULLIF(pre.basis_qty, 0)), 4) AS other_variance,
                ROUND(cs.total_cost - pre.total_cost * (cs.basis_qty / NULLIF(pre.basis_qty, 0)), 4) AS total_variance,
                CASE WHEN cs.revenue_per_unit > 0 THEN ROUND((cs.revenue_per_unit - cs.unit_cost) / cs.revenue_per_unit * 100, 2) ELSE NULL END AS actual_margin_pct,
                cs.variance_reason,
                cs.created_at AS costed_at
            ')
            ->orderByDesc('cs.id');

        $this->applySearch($query, $request, 'jc.number', 'so.number', 'pi.code', 'c.name');
        $this->applyDate($query, $request, 'cs.created_at');

        if ($request->query('customer')) {
            $query->where('so.customer_id', $request->query('customer'));
        }

        if ($request->query('product')) {
            $query->where('cs.product_id', $request->query('product'));
        }

        if ($request->query('explained') === 'no') {
            $query->where(fn ($q) => $q->whereNull('cs.variance_reason')->orWhere('cs.variance_reason', ''));
        } elseif ($request->query('explained') === 'yes') {
            $query->whereNotNull('cs.variance_reason')->where('cs.variance_reason', '!=', '');
        }

        return $query;
    }
}
