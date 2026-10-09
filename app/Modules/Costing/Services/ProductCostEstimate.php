<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\MasterData\Models\Machine;
use App\Modules\Product\Models\Product;
use App\Support\Calculators\CostLine;
use App\Support\Calculators\CostSheet;
use App\Support\Settings\Settings;

/**
 * The cost of a made item that is not a label (spec §4, marketing and pre-production).
 *
 * The label families cost through their geometry — metres of web, ends, ink lay. Every other
 * family costs the way the specification lays out: the bill of materials grossed up by its
 * wastage at a rate, the routing's steps as machine, labour and energy hours, overhead on top,
 * margin on the price. Same lines, same sheet, so a zipper and a woven label read alike.
 */
class ProductCostEstimate
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  array<string, mixed>  $overrides  marginPct, overheadPct, adminPct, labourRatePerHour, tariffPerKwh, materialRates (item id => rate)
     */
    public function build(Product $product, float $qty, array $overrides = []): CostSheet
    {
        $product->loadMissing(['activeBom.lines.item', 'routing.operations.machineGroup', 'item']);

        $lines = [];
        $seq = 1;
        $materialCost = 0.0;

        // --- Material: the bill at this quantity, each line grossed up by its own wastage ---
        $bom = $product->activeBom;

        foreach ($bom?->lines ?? [] as $line) {
            $item = $line->item;

            if ($item === null) {
                continue;
            }

            $gross = (float) $line->qty_per_base * ($qty / max(0.000001, (float) $bom->base_qty)) * (1 + (float) ($line->wastage_pct ?? 0) / 100);
            $rate = (float) ($overrides['materialRates'][$item->id] ?? ($item->avg_rate > 0 ? $item->avg_rate : $item->std_rate));
            $amount = $gross * $rate;
            $materialCost += $amount;

            $lines[] = new CostLine(
                seq: $seq++,
                costType: self::materialType($item),
                basis: (string) ($line->uom?->code ?? $item->baseUom?->code ?? 'unit'),
                qty: round($gross, 6),
                rate: $rate,
                amount: round($amount, 4),
                formulaRef: 'CS-M',
                description: "{$item->code} {$item->name}",
            );
        }

        // --- Conversion: every routing step as hours on its machine group ------------------
        $labourRate = (float) ($overrides['labourRatePerHour'] ?? $this->settings->decimal('labour_rate_per_hour', 80));
        $tariff = (float) ($overrides['tariffPerKwh'] ?? $this->settings->decimal('tariff_per_kwh', 12));
        $machineCost = $labourCost = $energyCost = $hours = 0.0;

        // Each step must put out enough for the steps after it (the job card plans the same way).
        $need = $qty;
        $stepQty = [];

        foreach (($product->routing?->operations ?? collect())->sortByDesc('sequence_no') as $operation) {
            $need = $need * (1 + (float) $operation->wastage_pct / 100);
            $stepQty[$operation->id] = $need;
        }

        foreach ($product->routing?->operations ?? [] as $operation) {
            $rate = (float) ($operation->std_rate_per_hour ?? 0);
            $machine = Machine::query()->where('machine_group_id', $operation->machine_group_id)->where('is_active', true)->orderByDesc('hourly_rate')->first();
            $runHours = $rate > 0 ? $stepQty[$operation->id] / $rate : 0.0;
            $stepHours = $runHours + (float) $operation->setup_minutes / 60;
            $hours += $stepHours;

            $machineAmount = $stepHours * (float) ($machine?->hourly_rate ?? 0);
            $labourAmount = $stepHours * (float) $operation->manning_level * $labourRate;
            $energyAmount = $stepHours * (float) ($machine?->kw_rating ?? 0) * $tariff;
            $machineCost += $machineAmount;
            $labourCost += $labourAmount;
            $energyCost += $energyAmount;

            $lines[] = new CostLine($seq++, 'machine', 'hour', round($stepHours, 4), (float) ($machine?->hourly_rate ?? 0), round($machineAmount, 4), 'CS-C', "{$operation->name}: machine");
            $lines[] = new CostLine($seq++, 'labour', 'hour', round($stepHours, 4), round((float) $operation->manning_level * $labourRate, 4), round($labourAmount, 4), 'CS-C', "{$operation->name}: labour");

            if ($energyAmount > 0) {
                $lines[] = new CostLine($seq++, 'energy', 'kWh', round($stepHours * (float) $machine->kw_rating, 4), $tariff, round($energyAmount, 4), 'CS-C', "{$operation->name}: energy");
            }
        }

        // --- Overhead and margin, the way the label sheet does it (BR-19, BR-20) ----------
        $direct = $materialCost + $machineCost + $labourCost + $energyCost;
        $overheadPct = (float) ($overrides['overheadPct'] ?? $this->settings->decimal('overhead_pct', 12));
        $adminPct = (float) ($overrides['adminPct'] ?? $this->settings->decimal('admin_pct', 5));
        $marginPct = (float) ($overrides['marginPct'] ?? $this->settings->decimal('default_margin_pct', 20));

        $factoryOverhead = $direct * $overheadPct / 100;
        $adminOverhead = ($direct + $factoryOverhead) * $adminPct / 100;
        $total = $direct + $factoryOverhead + $adminOverhead;

        $lines[] = new CostLine($seq++, 'overhead', '%', $overheadPct, round($direct, 4), round($factoryOverhead, 4), 'BR-19', 'Factory overhead');
        $lines[] = new CostLine($seq++, 'overhead', '%', $adminPct, round($direct + $factoryOverhead, 4), round($adminOverhead, 4), 'BR-19', 'Administrative overhead');

        $unitCost = $qty > 0 ? $total / $qty : 0.0;
        $unitPrice = $marginPct < 100 ? $unitCost / (1 - $marginPct / 100) : $unitCost;
        $marginAmount = ($unitPrice - $unitCost) * $qty;

        return new CostSheet(
            lines: $lines,
            materialCost: round($materialCost, 4),
            toolingCost: 0.0,
            machineCost: round($machineCost, 4),
            labourCost: round($labourCost, 4),
            energyCost: round($energyCost, 4),
            directCost: round($direct, 4),
            factoryOverhead: round($factoryOverhead, 4),
            adminOverhead: round($adminOverhead, 4),
            subtotal: round($total, 4),
            minimumCharge: 0.0,
            totalCost: round($total, 4),
            unitCost: round($unitCost, 6),
            ratePerM: round($unitPrice, 4),
            ratePerMInCurrency: round($unitPrice, 4),
            marginPct: $marginPct,
            marginAmount: round($marginAmount, 4),
            sellingValue: round($unitPrice * $qty, 4),
            currency: (string) $this->settings->get('base_currency', 'BDT'),
            exchangeRate: 1.0,
            belowMinimumOrderValue: false,
            totalMachineHours: round($hours, 4),
        );
    }

    /** The cost line a material lands on: by what it is, then by what it is made of. */
    public static function materialType(\App\Modules\MasterData\Models\Item $item): string
    {
        return match ($item->category?->item_class ?? null) {
            'yarn' => 'material_yarn',
            'ribbon', 'tape' => 'material_ribbon',
            'ink' => 'material_ink',
            'chemical' => 'material_chemical',
            'paper' => 'material_paper',
            'film' => 'material_film',
            'packing' => 'material_packing',
            default => match (true) {
                $item->item_type === 'consumable' => 'material_chemical',
                in_array($item->item_type, ['component', 'semi_finished', 'finished_good'], true) => 'material_component',
                $item->material_base === 'textile' => 'material_ribbon',
                $item->material_base === 'paper' => 'material_paper',
                $item->material_base === 'film' => 'material_film',
                default => 'material_other',
            },
        };
    }
}
