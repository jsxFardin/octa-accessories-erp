<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\Costing\Models\CostSheet as CostSheetModel;
use App\Modules\Costing\Models\CostSheetLine;
use App\Modules\MasterData\Models\Item;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Product\Models\Tool;
use App\Support\Calculators\CostLine;
use App\Support\Calculators\CostSheet;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Calculators\CostSheetInput;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Bridges the pure CostSheetCalculator to the database: gathers rates, runs the formulas,
 * and persists the result with `formula_ref` on every line.
 *
 * Q1 — when a quotation is sent the sheet is snapshotted and locked. Item rates, machine
 * rates and overhead percentages are copied as values, so master data moving next month never
 * alters a quotation already in a customer's inbox.
 */
class CostSheetService
{
    /**
     * The calculator names its packing line `packing` after BR-14's table; the schema's
     * `cost_sheet_lines_type_chk` calls the same thing `material_packing`, and admin overhead
     * shares the `overhead` type. Translated here, at the boundary, rather than bending
     * either the rule or the constraint.
     */
    private const TYPE_MAP = [
        'packing' => 'material_packing',
        'admin_overhead' => 'overhead',
    ];

    public function __construct(
        private readonly CostSheetCalculator $calculator,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function calculate(
        Product $product,
        ProductSpec $spec,
        int $orderQtyPcs,
        array $overrides = [],
    ): CostSheet {
        return $this->calculator->build($this->buildInput($product, $spec, $orderQtyPcs, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function persist(
        Product $product,
        ProductSpec $spec,
        int $orderQtyPcs,
        array $overrides = [],
        ?int $quotationLineId = null,
    ): CostSheetModel {
        $sheet = $this->calculate($product, $spec, $orderQtyPcs, $overrides);
        $plan = (new \App\Support\Calculators\ConsumptionCalculator)->plan(
            $spec->toCalculatorInput($product->product_type),
            $orderQtyPcs,
            $product->routing?->toCalculatorSteps() ?? [],
            $spec->colourWeights(),
        );

        return DB::transaction(function () use ($sheet, $plan, $product, $spec, $orderQtyPcs, $quotationLineId): CostSheetModel {
            $model = CostSheetModel::query()->create([
                'quotation_line_id' => $quotationLineId,
                'product_id' => $product->id,
                'product_spec_id' => $spec->id,
                'basis_qty' => $orderQtyPcs,
                'gross_metres' => $plan->grossMetres,
                'total_wastage_pct' => $plan->totalWastagePct,
                'overhead_pct' => $sheet->lines !== [] ? $this->settings->decimal('overhead_pct', 12) : 12,
                'admin_pct' => $this->settings->decimal('admin_pct', 5),
                'margin_pct' => $sheet->marginPct,
                'material_cost' => $sheet->materialCost,
                'tooling_cost' => $sheet->toolingCost,
                'machine_cost' => $sheet->machineCost,
                'labour_cost' => $sheet->labourCost,
                'energy_cost' => $sheet->energyCost,
                'packing_cost' => $this->sumOf($sheet, 'packing'),
                'other_cost' => $this->sumOf($sheet, 'outsourcing') + $this->sumOf($sheet, 'freight'),
                'overhead_amount' => $sheet->factoryOverhead + $sheet->adminOverhead,
                'total_cost' => $sheet->totalCost,
                'unit_cost' => $sheet->unitCost,
                'rate_per_m' => $sheet->ratePerM,
                'is_locked' => false,
                'created_by' => auth()->id(),
            ]);

            foreach ($sheet->lines as $line) {
                CostSheetLine::query()->create([
                    'cost_sheet_id' => $model->id,
                    'sequence_no' => $line->seq,
                    'cost_type' => self::TYPE_MAP[$line->costType] ?? $line->costType,
                    'description' => $line->description ?? ucfirst(str_replace('_', ' ', $line->costType)),
                    'basis_uom' => $line->basis,
                    'qty' => $line->qty,
                    'rate' => $line->rate,
                    'amount' => $line->amount,
                    'formula_ref' => $line->formulaRef,
                ]);
            }

            return $model;
        });
    }

    /** Q1 — a sent quotation's sheet is read-only. */
    public function lock(CostSheetModel $sheet): void
    {
        $sheet->update(['is_locked' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function buildInput(Product $product, ProductSpec $spec, int $orderQtyPcs, array $overrides): CostSheetInput
    {
        $routing = $product->routing;
        $routing?->loadMissing('operations.machineGroup');

        return new CostSheetInput(
            spec: $spec->toCalculatorInput($product->product_type),
            orderQtyPcs: $orderQtyPcs,
            routing: $this->routingSteps($product),
            materialRates: $overrides['materialRates'] ?? $this->materialRates($product),
            colourWeights: $spec->colourWeights(),
            labourRatePerHour: (float) ($overrides['labourRatePerHour'] ?? $this->settings->decimal('labour_rate_per_hour', 80)),
            tariffPerKwh: (float) ($overrides['tariffPerKwh'] ?? $this->settings->decimal('tariff_per_kwh', 12)),
            toolingCost: (float) ($overrides['toolingCost'] ?? $this->toolingCost($product, $spec, $orderQtyPcs)),
            customerPaysTooling: (bool) ($overrides['customerPaysTooling'] ?? false),
            // BR-15 — a running programme spreads tooling over the annual forecast, not this
            // order, which is why `is_running_programme` exists on the product.
            amortisationQty: $overrides['amortisationQty']
                ?? ($product->is_running_programme ? (float) $product->annual_forecast_qty : null),
            packingRatePerBundle: (float) ($overrides['packingRatePerBundle'] ?? 0),
            packingRatePerPolybag: (float) ($overrides['packingRatePerPolybag'] ?? 0),
            packingRatePerCarton: (float) ($overrides['packingRatePerCarton'] ?? 0),
            outsourcingCost: (float) ($overrides['outsourcingCost'] ?? 0),
            freightCost: (float) ($overrides['freightCost'] ?? 0),
            overheadPct: (float) ($overrides['overheadPct'] ?? $this->settings->decimal('overhead_pct', 12)),
            adminPct: (float) ($overrides['adminPct'] ?? $this->settings->decimal('admin_pct', 5)),
            marginPct: (float) ($overrides['marginPct'] ?? $this->settings->decimal('default_margin_pct', 20)),
            minOrderValue: (float) ($overrides['minOrderValue'] ?? $product->customer->min_order_value),
            exchangeRate: (float) ($overrides['exchangeRate'] ?? 1),
            currency: (string) ($overrides['currency'] ?? $this->settings->get('base_currency', 'BDT')),
        );
    }

    /** @return list<\App\Support\Calculators\RoutingStep> */
    private function routingSteps(Product $product): array
    {
        if ($product->routing === null) {
            return [];
        }

        $product->routing->loadMissing('operations.machineGroup');

        // BR-16 — the rate that costs the job is the machine's, when one is nominated for the
        // group; otherwise the routing's standard rate stands in.
        return $product->routing->operations->map(function ($operation) {
            $machine = \App\Modules\MasterData\Models\Machine::query()
                ->where('machine_group_id', $operation->machine_group_id)
                ->where('is_active', true)
                ->orderByDesc('std_rate_per_hour')
                ->first();

            return $operation->toCalculatorStep($machine);
        })->all();
    }

    /**
     * Item rates by cost type, taken from the BOM where one exists and from the item master
     * otherwise. Weighted average is preferred over standard rate: it is what the material
     * actually cost.
     *
     * @return array<string, float>
     */
    private function materialRates(Product $product): array
    {
        $bom = $product->activeBom()->with('lines.item.category')->first();

        if ($bom === null) {
            return [];
        }

        $rates = [];

        foreach ($bom->lines as $line) {
            $item = $line->item;

            if ($item === null) {
                continue;
            }

            $costType = match ($item->category?->item_class) {
                'yarn' => 'material_yarn',
                'ribbon', 'tape' => 'material_ribbon',
                'ink' => 'material_ink',
                'chemical' => 'material_chemical',
                'paper' => 'material_paper',
                'film' => 'material_film',
                default => null,
            };

            if ($costType === null) {
                continue;
            }

            $rate = (float) ($item->avg_rate > 0 ? $item->avg_rate : $item->std_rate);

            $rates[$costType] = max($rates[$costType] ?? 0.0, $rate);
        }

        return $rates;
    }

    /**
     * BR-13 / BR-15 — only the tools that cannot be reused enter the sheet. A plate with
     * enough impressions left costs this order nothing.
     */
    private function toolingCost(Product $product, ProductSpec $spec, int $orderQtyPcs): float
    {
        $consumption = new \App\Support\Calculators\ConsumptionCalculator;
        $specInput = $spec->toCalculatorInput($product->product_type);

        $plan = $consumption->plan($specInput, $orderQtyPcs, $this->routingSteps($product), $spec->colourWeights());

        $existing = Tool::query()
            ->where('product_spec_id', $spec->id)
            ->where('status', 'available')
            ->get()
            ->map(fn (Tool $tool): array => [
                'colour_index' => $tool->colour_index,
                'remaining_life_impressions' => $tool->remainingLifeImpressions(),
            ])
            ->all();

        $requirement = $consumption->toolRequirement($specInput, $plan, $existing);

        if ($requirement['to_purchase'] === 0) {
            return 0.0;
        }

        $unitCost = (float) (Item::query()
            ->whereHas('category', fn ($q) => $q->where('item_class', 'tool_stock'))
            ->orderByDesc('std_rate')
            ->value('std_rate') ?? 0);

        return $requirement['to_purchase'] * $unitCost;
    }

    private function sumOf(CostSheet $sheet, string $costType): float
    {
        return array_sum(array_map(
            fn (CostLine $line): float => $line->costType === $costType ? $line->amount : 0.0,
            $sheet->lines,
        ));
    }
}
