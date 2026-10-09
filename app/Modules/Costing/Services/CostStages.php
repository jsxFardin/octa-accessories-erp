<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\Costing\Models\CostSheet;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The three stages side by side (spec §4–5): marketing, pre-production, post-production,
 * per unit, and the variance between them by element — price, usage, conversion, overhead.
 */
class CostStages
{
    /** The elements a sheet is compared on, in the order the specification's table reads. */
    private const ELEMENTS = [
        'material_cost' => 'Material',
        'tooling_cost' => 'Tooling',
        'machine_cost' => 'Machine',
        'labour_cost' => 'Labour',
        'energy_cost' => 'Energy',
        'packing_cost' => 'Packing',
        'other_cost' => 'Subcontract & freight',
        'overhead_amount' => 'Overhead',
        'total_cost' => 'Total cost',
    ];

    public function __construct(private readonly Settings $settings) {}

    /**
     * A job's actuals against its order line's benchmark.
     *
     * @return array<string, mixed>
     */
    public function forJobCard(JobCard $jobCard): array
    {
        $post = CostSheet::query()->with('lines')->where('job_card_id', $jobCard->id)->where('stage', CostSheet::POST_PRODUCTION)->latest('id')->first();
        $pre = $jobCard->sales_order_line_id === null ? null
            : CostSheet::query()->where('sales_order_line_id', $jobCard->sales_order_line_id)->where('stage', CostSheet::PRE_PRODUCTION)->latest('id')->first();
        $marketing = $this->marketingFor($jobCard->salesOrderLine);

        $output = $jobCard->finalOperationOutput();
        $waste = (float) DB::table('waste_logs')->where('job_card_id', $jobCard->id)->sum('qty');
        $produced = $output['good'] + $waste;

        return [
            'pre' => $this->summary($pre),
            'post' => $this->summary($post),
            'marketing' => $this->summary($marketing),
            'variance' => $this->variance($pre, $post),
            'line_variance' => $this->lineVariance($pre, $post),
            'lines' => $post?->lines->map(fn ($line): array => $line->only(['sequence_no', 'cost_type', 'description', 'basis_uom', 'qty', 'rate', 'amount']))->all() ?? [],
            'reason' => $post?->variance_reason,
            'post_sheet_id' => $post?->id,
            'margin_floor_pct' => $this->settings->decimal('margin_floor_pct', 12),
            // Spec §4 — what goes back to the masters: the wastage the job actually ran at.
            'wastage' => [
                'actual_pct' => $produced > 0 ? round($waste / $produced * 100, 4) : null,
                'standard_pct' => $jobCard->product?->item?->standard_wastage_pct === null ? null : (float) $jobCard->product->item->standard_wastage_pct,
            ],
        ];
    }

    /**
     * Each line of an order with its three stages, for the order page.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(SalesOrder $order): array
    {
        $floor = $this->settings->decimal('margin_floor_pct', 12);
        $rows = [];

        foreach ($order->lines as $line) {
            $pre = CostSheet::query()->where('sales_order_line_id', $line->id)->where('stage', CostSheet::PRE_PRODUCTION)->latest('id')->first();
            $posts = CostSheet::query()->where('sales_order_line_id', $line->id)->where('stage', CostSheet::POST_PRODUCTION)->get();
            $marketing = $this->marketingFor($line);

            // Actuals across every job that filled the line, weighted by what each made.
            $actualQty = (float) $posts->sum('basis_qty');
            $actualUnit = $actualQty > 0 ? (float) $posts->sum('total_cost') / $actualQty : null;
            $revenue = $pre?->revenue_per_unit ?? ((float) $line->ordered_qty > 0 ? (float) $line->line_total / (float) $line->ordered_qty : null);

            $rows[] = [
                'line_id' => $line->id,
                'line_no' => $line->line_no,
                'product' => $line->product?->only(['id', 'code', 'name']),
                'marketing' => $this->summary($marketing),
                'pre' => $this->summary($pre),
                'post' => $actualUnit === null ? null : [
                    'unit_cost' => round($actualUnit, 6),
                    'basis_qty' => $actualQty,
                    'margin_pct' => $revenue > 0 ? round(($revenue - $actualUnit) / $revenue * 100, 4) : null,
                    'jobs' => $posts->count(),
                ],
                'revenue_per_unit' => $revenue === null ? null : round((float) $revenue, 6),
                'below_floor' => $pre !== null && $this->marginOf($pre) !== null && $this->marginOf($pre) < $floor,
            ];
        }

        return $rows;
    }

    /** The quotation line's sheet that priced this order line, when the order came from a quote. */
    private function marketingFor(?SalesOrderLine $line): ?CostSheet
    {
        if ($line === null) {
            return null;
        }

        $order = $line->salesOrder;

        if ($order === null || $order->quotation_id === null) {
            return null;
        }

        return CostSheet::query()
            ->whereIn('quotation_line_id', DB::table('quotation_lines')->where('quotation_id', $order->quotation_id)->where('product_id', $line->product_id)->select('id'))
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function summary(?CostSheet $sheet): ?array
    {
        if ($sheet === null) {
            return null;
        }

        $qty = max(0.000001, (float) $sheet->basis_qty);
        $perUnit = [];

        foreach (array_keys(self::ELEMENTS) as $key) {
            $perUnit[$key] = round((float) $sheet->{$key} / $qty, 6);
        }

        return [
            'id' => $sheet->id,
            'stage' => $sheet->stage,
            'basis_qty' => (float) $sheet->basis_qty,
            'unit_cost' => (float) $sheet->unit_cost,
            'revenue_per_unit' => $sheet->revenue_per_unit === null ? null : (float) $sheet->revenue_per_unit,
            'margin_pct' => $this->marginOf($sheet),
            'per_unit' => $perUnit,
            'locked_at' => $sheet->locked_at,
            'created_at' => $sheet->created_at,
        ];
    }

    /**
     * Post against pre, line by line, so a material that cost more is told apart from a
     * material of which more was used (spec §4: price variance vs usage variance).
     *
     * The benchmark was struck for the order's quantity and the actuals for what the job
     * made, so the benchmark is scaled to the job's output before the two are compared.
     * Materials match on the item when the benchmark names one (a product costed from its
     * bill), and on the cost type otherwise (a label sheet costs yarn, ribbon, ink by type).
     *
     *   usage variance = (actual qty − planned qty) × planned rate
     *   price variance = (actual rate − planned rate) × actual qty
     *
     * The two sum to the amount variance exactly. Conversion is compared per operation:
     * planned hours against the minutes actually run, with machine, labour and energy together.
     *
     * @return array{scale: ?float, materials: list<array<string, mixed>>, conversion: list<array<string, mixed>>}
     */
    public function lineVariance(?CostSheet $pre, ?CostSheet $post): array
    {
        if ($post === null) {
            return ['scale' => null, 'materials' => [], 'conversion' => []];
        }

        $pre?->loadMissing('lines');
        $post->loadMissing('lines');

        $scale = $pre === null || (float) $pre->basis_qty <= 0 ? null : (float) $post->basis_qty / (float) $pre->basis_qty;
        $preMaterials = $pre === null ? collect() : $pre->lines->filter(fn ($line): bool => str_starts_with((string) $line->cost_type, 'material'));
        $postMaterials = $post->lines->filter(fn ($line): bool => str_starts_with((string) $line->cost_type, 'material'));
        $byItem = $preMaterials->contains(fn ($line): bool => $line->item_id !== null);
        $key = fn ($line): string => $byItem && $line->item_id !== null ? 'item:'.$line->item_id : 'type:'.$line->cost_type;

        $group = function ($lines, float $factor) use ($key): array {
            $rows = [];

            foreach ($lines as $line) {
                $k = $key($line);
                $rows[$k] ??= ['description' => $line->description, 'cost_type' => $line->cost_type, 'item_id' => $line->item_id, 'uom' => $line->basis_uom, 'qty' => 0.0, 'amount' => 0.0];
                $rows[$k]['qty'] += (float) $line->qty * $factor;
                $rows[$k]['amount'] += (float) $line->amount * $factor;
            }

            return $rows;
        };

        $planned = $group($preMaterials, $scale ?? 1.0);
        $actual = $group($postMaterials, 1.0);
        $materials = [];

        foreach (array_unique([...array_keys($actual), ...array_keys($planned)]) as $k) {
            $p = $planned[$k] ?? null;
            $a = $actual[$k] ?? null;
            $plannedRate = $p !== null && $p['qty'] > 0 ? $p['amount'] / $p['qty'] : null;
            $actualRate = $a !== null && $a['qty'] > 0 ? $a['amount'] / $a['qty'] : null;
            $plannedQty = $p['qty'] ?? 0.0;
            $actualQty = $a['qty'] ?? 0.0;
            // No benchmark rate for a material the plan never named: its whole cost is usage.
            $usage = $plannedRate === null ? ($a['amount'] ?? 0.0) : ($actualQty - $plannedQty) * $plannedRate;
            $price = $plannedRate === null || $actualRate === null ? 0.0 : ($actualRate - $plannedRate) * $actualQty;

            $materials[] = [
                'key' => $k,
                'description' => $a['description'] ?? $p['description'],
                'cost_type' => $a['cost_type'] ?? $p['cost_type'],
                'item_id' => $a['item_id'] ?? $p['item_id'],
                'uom' => $a['uom'] ?? $p['uom'],
                'planned' => $p === null ? null : ['qty' => round($plannedQty, 6), 'rate' => round((float) $plannedRate, 6), 'amount' => round($p['amount'], 4)],
                'actual' => $a === null ? null : ['qty' => round($actualQty, 6), 'rate' => round((float) $actualRate, 6), 'amount' => round($a['amount'], 4)],
                'qty_variance' => round($actualQty - $plannedQty, 6),
                'rate_variance' => $plannedRate === null || $actualRate === null ? null : round($actualRate - $plannedRate, 6),
                'usage_variance' => round($usage, 4),
                'price_variance' => round($price, 4),
                'total_variance' => round(($a['amount'] ?? 0.0) - ($p['amount'] ?? 0.0), 4),
            ];
        }

        // Conversion by operation: "Printing: machine" and "Printing: labour" fold into "Printing".
        $operations = function ($lines, float $factor): array {
            $rows = [];

            foreach ($lines as $line) {
                if (! in_array($line->cost_type, ['machine', 'labour', 'energy'], true)) {
                    continue;
                }

                $name = trim((string) strstr((string) $line->description, ':', true)) ?: (string) $line->description;
                $rows[$name] ??= ['hours' => 0.0, 'labour_hours' => 0.0, 'amount' => 0.0];
                $rows[$name]['amount'] += (float) $line->amount * $factor;

                // Hours come from the machine line; a step with no machine rate has only labour.
                if ($line->cost_type === 'machine') {
                    $rows[$name]['hours'] += (float) $line->qty * $factor;
                } elseif ($line->cost_type === 'labour') {
                    $rows[$name]['labour_hours'] += (float) $line->qty * $factor;
                }
            }

            foreach ($rows as &$row) {
                $row['hours'] = $row['hours'] > 0 ? $row['hours'] : $row['labour_hours'];
            }

            return $rows;
        };

        $plannedOps = $operations($pre?->lines ?? collect(), $scale ?? 1.0);
        $actualOps = $operations($post->lines, 1.0);

        // A label sheet benchmarks conversion as one blended line across its operations, not
        // step by step; when no step name is shared, the comparison is the run as a whole.
        if ($plannedOps !== [] && $actualOps !== [] && array_intersect_key($plannedOps, $actualOps) === []) {
            $fold = fn (array $rows): array => [
                'hours' => array_sum(array_column($rows, 'hours')),
                'labour_hours' => 0.0,
                'amount' => array_sum(array_column($rows, 'amount')),
            ];
            $plannedOps = ['All operations' => $fold($plannedOps)];
            $actualOps = ['All operations' => $fold($actualOps)];
        }

        $conversion = [];

        foreach (array_unique([...array_keys($actualOps), ...array_keys($plannedOps)]) as $name) {
            $p = $plannedOps[$name] ?? null;
            $a = $actualOps[$name] ?? null;

            $conversion[] = [
                'key' => $name,
                'operation' => $name,
                'planned' => $p === null ? null : ['hours' => round($p['hours'], 4), 'amount' => round($p['amount'], 4)],
                'actual' => $a === null ? null : ['hours' => round($a['hours'], 4), 'amount' => round($a['amount'], 4)],
                'hours_variance' => round(($a['hours'] ?? 0.0) - ($p['hours'] ?? 0.0), 4),
                'total_variance' => round(($a['amount'] ?? 0.0) - ($p['amount'] ?? 0.0), 4),
            ];
        }

        return ['scale' => $scale === null ? null : round($scale, 6), 'materials' => $materials, 'conversion' => $conversion];
    }

    private function marginOf(CostSheet $sheet): ?float
    {
        $revenue = $sheet->revenue_per_unit === null ? null : (float) $sheet->revenue_per_unit;

        return $revenue === null || $revenue <= 0 ? null : round(($revenue - (float) $sheet->unit_cost) / $revenue * 100, 4);
    }

    /**
     * Post against pre, per unit and for the job's quantity, element by element.
     *
     * @return list<array{key: string, label: string, pre: ?float, post: ?float, per_unit: ?float, total: ?float}>
     */
    private function variance(?CostSheet $pre, ?CostSheet $post): array
    {
        $rows = [];
        $preQty = $pre === null ? null : max(0.000001, (float) $pre->basis_qty);
        $postQty = $post === null ? null : max(0.000001, (float) $post->basis_qty);

        foreach (self::ELEMENTS as $key => $label) {
            $a = $pre === null ? null : round((float) $pre->{$key} / $preQty, 6);
            $b = $post === null ? null : round((float) $post->{$key} / $postQty, 6);
            $diff = $a === null || $b === null ? null : round($b - $a, 6);

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'pre' => $a,
                'post' => $b,
                'per_unit' => $diff,
                'total' => $diff === null ? null : round($diff * $postQty, 4),
            ];
        }

        return $rows;
    }
}
