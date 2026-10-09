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
