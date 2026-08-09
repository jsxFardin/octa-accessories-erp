<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use InvalidArgumentException;

/**
 * BR-14 … BR-23 — cost sheet, overhead, margin and selling price.
 *
 * The rule worth reading twice is BR-20: margin is applied **on price** (divide by
 * `1 - margin`), not on cost (multiply by `1 + margin`). Using the wrong one silently loses
 * margin² on every order and is the single most common costing error in this industry.
 */
class CostSheetCalculator
{
    public function __construct(private readonly ConsumptionCalculator $consumption) {}

    public function build(CostSheetInput $input): CostSheet
    {
        if ($input->orderQtyPcs <= 0) {
            throw new InvalidArgumentException('Order quantity must be greater than zero.');
        }

        $plan = $this->consumption->plan(
            $input->spec,
            $input->orderQtyPcs,
            $input->routing,
            $input->colourWeights,
        );

        $lines = [];

        // --- Lines 1–6: materials (BR-9, BR-7, BR-10, BR-11) ---------------------------
        $lines = [...$lines, ...$this->materialLines($input, $plan)];
        $materialCost = $this->sum($lines);

        // --- Line 7: tooling (BR-13, BR-15) --------------------------------------------
        $toolingLine = $this->toolingLine($input, count($lines) + 1);

        if ($toolingLine !== null) {
            $lines[] = $toolingLine;
        }

        $toolingCost = $toolingLine === null ? 0.0 : $toolingLine->amount;

        // --- Lines 8–10: machine, labour, energy (BR-16, BR-17, BR-18) -----------------
        [$machineLines, $machineCost, $labourCost, $energyCost, $totalHours] =
            $this->conversionLines($input, $plan, count($lines) + 1);

        $lines = [...$lines, ...$machineLines];

        // --- Lines 11–13: packing, outsourcing, freight (BR-12) ------------------------
        $lines = [...$lines, ...$this->packingLines($input, $plan, count($lines) + 1)];

        $seq = count($lines) + 1;

        if ($input->outsourcingCost > 0) {
            $lines[] = CostLine::of($seq++, 'outsourcing', 'job', 1, $input->outsourcingCost, 'BR-14');
        }

        if ($input->freightCost > 0) {
            $lines[] = CostLine::of($seq++, 'freight', 'job', 1, $input->freightCost, 'BR-14');
        }

        // --- Line 14: overhead (BR-19) --------------------------------------------------
        // Factory overhead applies to the conversion base: material, tooling, machine,
        // labour and energy. Packing, outsourcing and freight are pass-through costs and
        // are not marked up by the factory rate.
        $directCost = $materialCost + $toolingCost + $machineCost + $labourCost + $energyCost;
        $factoryOverhead = $directCost * $input->overheadPct / 100;

        $lines[] = new CostLine(
            $seq++,
            'overhead',
            '%',
            $input->overheadPct,
            $directCost,
            round($factoryOverhead, 4),
            'BR-19',
            'Factory overhead on direct cost',
        );

        $preAdminSubtotal = $this->sum($lines);
        $adminOverhead = $preAdminSubtotal * $input->adminPct / 100;

        $lines[] = new CostLine(
            $seq++,
            'admin_overhead',
            '%',
            $input->adminPct,
            $preAdminSubtotal,
            round($adminOverhead, 4),
            'BR-19',
            'Administrative overhead on subtotal',
        );

        $subtotal = $this->sum($lines);

        // --- BR-21: minimum order value -------------------------------------------------
        // Flagged and charged, never silently ignored.
        $belowMinimum = $input->minOrderValue > 0 && $subtotal < $input->minOrderValue;
        $minimumCharge = $belowMinimum ? $input->minOrderValue - $subtotal : 0.0;

        if ($belowMinimum) {
            $lines[] = new CostLine(
                $seq++,
                'minimum_charge',
                'job',
                1,
                round($minimumCharge, 4),
                round($minimumCharge, 4),
                'BR-21',
                'Top-up to the customer minimum order value',
            );
        }

        $totalCost = $subtotal + $minimumCharge;

        // --- BR-20: margin on price, not on cost ---------------------------------------
        $unitCost = $totalCost / $input->orderQtyPcs;
        $ratePerM = $this->ratePerM($unitCost, $input->marginPct);

        $sellingValue = $ratePerM * $input->orderQtyPcs / 1000;
        $marginAmount = $sellingValue - $totalCost;

        $lines[] = new CostLine(
            $seq,
            'margin',
            '%',
            $input->marginPct,
            $totalCost,
            round($marginAmount, 4),
            'BR-20',
            'Margin on price (÷ (1 − margin)), not on cost',
        );

        return new CostSheet(
            lines: $lines,
            materialCost: $materialCost,
            toolingCost: $toolingCost,
            machineCost: $machineCost,
            labourCost: $labourCost,
            energyCost: $energyCost,
            directCost: $directCost,
            factoryOverhead: $factoryOverhead,
            adminOverhead: $adminOverhead,
            subtotal: $subtotal,
            minimumCharge: $minimumCharge,
            totalCost: $totalCost,
            unitCost: $unitCost,
            ratePerM: $ratePerM,
            ratePerMInCurrency: $this->convert($ratePerM, $input->exchangeRate),
            marginPct: $input->marginPct,
            marginAmount: $marginAmount,
            sellingValue: $sellingValue,
            currency: $input->currency,
            exchangeRate: $input->exchangeRate,
            belowMinimumOrderValue: $belowMinimum,
            totalMachineHours: $totalHours,
        );
    }

    /**
     * BR-20 — the selling rate per 1000 pieces.
     *
     * `unit_cost * 1000 / (1 - margin/100)`. Dividing is what makes the stated margin the
     * share of the *price*; multiplying by `1 + margin` would make it a share of cost, which
     * is a different and smaller number.
     */
    public function ratePerM(float $unitCost, float $marginPct): float
    {
        if ($marginPct >= 100.0) {
            throw new InvalidArgumentException('A margin of 100% or more has no finite price (BR-20).');
        }

        return $unitCost * 1000 / (1 - $marginPct / 100);
    }

    /** BR-1 — line value from a per-1000 rate. */
    public function lineValue(int $qtyPcs, float $ratePerM): float
    {
        return round($qtyPcs / 1000 * $ratePerM, 4);
    }

    /**
     * BR-23 — post-production cost variance. Positive means the job cost more than quoted.
     *
     * @return array{actual_unit_cost: float, variance_pct: float, variance_amount: float}
     */
    public function variance(
        float $actualMaterialCost,
        float $actualMachineCost,
        float $actualLabourCost,
        float $actualEnergyCost,
        int $goodQtyProduced,
        float $quotedUnitCost,
    ): array {
        if ($goodQtyProduced <= 0) {
            throw new InvalidArgumentException('Cost variance needs a produced quantity (BR-23).');
        }

        $actualTotal = $actualMaterialCost + $actualMachineCost + $actualLabourCost + $actualEnergyCost;
        $actualUnitCost = $actualTotal / $goodQtyProduced;

        $variancePct = $quotedUnitCost > 0
            ? ($actualUnitCost - $quotedUnitCost) / $quotedUnitCost * 100
            : 0.0;

        return [
            'actual_unit_cost' => round($actualUnitCost, 6),
            'variance_pct' => round($variancePct, 4),
            'variance_amount' => round(($actualUnitCost - $quotedUnitCost) * $goodQtyProduced, 4),
        ];
    }

    /** BR-22 — costs are computed in BDT and converted at the snapshotted rate. */
    public function convert(float $amountBdt, float $exchangeRate): float
    {
        if ($exchangeRate <= 0) {
            throw new InvalidArgumentException('Exchange rate must be greater than zero (BR-22).');
        }

        return $amountBdt / $exchangeRate;
    }

    /** @return list<CostLine> */
    private function materialLines(CostSheetInput $input, ConsumptionPlan $plan): array
    {
        $rates = $input->materialRates;
        $lines = [];
        $seq = 1;

        if ($plan->yarnKg > 0 && ($rates['material_yarn'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq++, 'material_yarn', 'kg', $plan->yarnKg, $rates['material_yarn'], 'BR-9');
        }

        if ($plan->grossMetres > 0 && ($rates['material_ribbon'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq++, 'material_ribbon', 'metre', $plan->grossMetres, $rates['material_ribbon'], 'BR-7');
        }

        if ($plan->inkKg > 0 && ($rates['material_ink'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq++, 'material_ink', 'kg', $plan->inkKg, $rates['material_ink'], 'BR-10');
        }

        if (($rates['material_chemical'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq++, 'material_chemical', 'kg', $plan->inkKg, $rates['material_chemical'], 'BR-14');
        }

        if ($plan->grossSheets > 0 && ($rates['material_paper'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq++, 'material_paper', 'sheet', (float) $plan->grossSheets, $rates['material_paper'], 'BR-11');
        }

        if ($plan->printedAreaM2 > 0 && ($rates['material_film'] ?? 0) > 0) {
            $lines[] = CostLine::of($seq, 'material_film', 'm2', $plan->printedAreaM2, $rates['material_film'], 'BR-14');
        }

        return $lines;
    }

    /**
     * BR-15 — tool amortisation.
     *
     * A reused tool costs the sheet nothing; a customer-funded tool is a separate quotation
     * line and is excluded from the /M rate; otherwise the purchase cost is spread over the
     * amortisation quantity, which defaults to this order.
     */
    private function toolingLine(CostSheetInput $input, int $seq): ?CostLine
    {
        if ($input->toolingCost <= 0 || $input->customerPaysTooling) {
            return null;
        }

        $amortisationQty = $input->amortisationQty ?? (float) $input->orderQtyPcs;

        if ($amortisationQty <= 0) {
            throw new InvalidArgumentException('Amortisation quantity must be greater than zero (BR-15).');
        }

        $perPiece = $input->toolingCost / $amortisationQty;

        return new CostLine(
            $seq,
            'tooling',
            'piece',
            (float) $input->orderQtyPcs,
            round($perPiece, 6),
            round($perPiece * $input->orderQtyPcs, 4),
            'BR-15',
            $amortisationQty > $input->orderQtyPcs
                ? 'Amortised over the annual programme volume'
                : 'Amortised over this order',
        );
    }

    /**
     * BR-16, BR-17, BR-18 — machine, labour and energy, per routing operation.
     *
     * @return array{0: list<CostLine>, 1: float, 2: float, 3: float, 4: float}
     */
    private function conversionLines(CostSheetInput $input, ConsumptionPlan $plan, int $seq): array
    {
        $machineCost = 0.0;
        $labourCost = 0.0;
        $energyCost = 0.0;
        $totalHours = 0.0;

        foreach ($input->routing as $step) {
            if ($step->stdRatePerHour <= 0) {
                continue;
            }

            // Output units are metres/hour for looms and presses, sheets/hour for offset.
            $outputUnits = $input->spec->productType->consumesSheets()
                ? (float) $plan->grossSheets
                : $plan->grossMetres;

            $hours = $outputUnits / $step->stdRatePerHour + $step->setupMinutes / 60;
            $totalHours += $hours;

            $machineCost += $hours * $step->machineHourlyRate;

            $labourRate = $step->labourRatePerHour > 0 ? $step->labourRatePerHour : $input->labourRatePerHour;
            $labourCost += $hours * $step->manningLevel * $labourRate;

            $energyCost += $hours * $step->machineKwRating * $input->tariffPerKwh;
        }

        $lines = [];

        if ($machineCost > 0) {
            $lines[] = new CostLine($seq++, 'machine', 'hour', round($totalHours, 6), 0.0, round($machineCost, 4), 'BR-16');
        }

        if ($labourCost > 0) {
            $lines[] = new CostLine($seq++, 'labour', 'hour', round($totalHours, 6), 0.0, round($labourCost, 4), 'BR-17');
        }

        if ($energyCost > 0) {
            $lines[] = new CostLine($seq, 'energy', 'kWh', round($totalHours, 6), $input->tariffPerKwh, round($energyCost, 4), 'BR-18');
        }

        return [$lines, $machineCost, $labourCost, $energyCost, $totalHours];
    }

    /** @return list<CostLine> */
    private function packingLines(CostSheetInput $input, ConsumptionPlan $plan, int $seq): array
    {
        $amount = $plan->bundles * $input->packingRatePerBundle
            + $plan->polybags * $input->packingRatePerPolybag
            + $plan->cartons * $input->packingRatePerCarton;

        if ($amount <= 0) {
            return [];
        }

        return [new CostLine(
            $seq,
            'packing',
            'piece',
            (float) ($plan->bundles + $plan->polybags + $plan->cartons),
            0.0,
            round($amount, 4),
            'BR-12',
            "{$plan->bundles} bundles · {$plan->polybags} polybags · {$plan->cartons} cartons",
        )];
    }

    /** @param list<CostLine> $lines */
    private function sum(array $lines): float
    {
        return array_sum(array_map(fn (CostLine $line): float => $line->amount, $lines));
    }
}
