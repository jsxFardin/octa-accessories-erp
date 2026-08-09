<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use InvalidArgumentException;

/**
 * BR-4 … BR-13 — the arithmetic that turns "50,000 pieces" into metres of ribbon, kilograms
 * of yarn, kilograms of ink, sheets of art card, cartons and tools.
 *
 * Labels are quoted per 1000 pieces but consumed in metres across N ends of a loom. Getting
 * this wrong makes every price and every material plan wrong, which is why this class has no
 * persistence, no framework dependency, and one test per rule ID.
 */
class ConsumptionCalculator
{
    /**
     * @param  list<RoutingStep>  $routing
     * @param  array<int, float>  $colourWeights  colour_index => weight_pct, from the spec's colour list
     */
    public function plan(
        SpecInput $spec,
        int $orderQtyPcs,
        array $routing = [],
        array $colourWeights = [],
    ): ConsumptionPlan {
        if ($orderQtyPcs <= 0) {
            throw new InvalidArgumentException('Order quantity must be greater than zero.');
        }

        $pitchMm = $this->pitchMm($spec);
        $labelsPerMetre = $this->labelsPerMetre($spec);
        $suggestedEnds = $this->suggestedEnds($spec);

        // BR-5: `ends` is decided by engineering and stored on the spec; the formula is the
        // suggestion, not an override. A spec that has not decided yet falls back to it.
        $ends = $spec->ends ?? $suggestedEnds;

        if ($ends < 1) {
            throw new InvalidArgumentException(
                'Ends must be at least 1 (BR-5): the web is too narrow for one label across.',
            );
        }

        $labelsPerWebMetre = $this->labelsPerWebMetre($labelsPerMetre, $ends);
        $totalWastagePct = $this->totalWastagePct($routing);
        $setupMetres = $this->setupMetres($routing);

        $netMetres = $orderQtyPcs / $labelsPerWebMetre;
        $grossMetres = $this->grossMetres($netMetres, $totalWastagePct, $setupMetres);

        [$yarnKg, $warpKg, $weftKg, $weftByColour] = $this->yarn($spec, $grossMetres, $colourWeights);
        [$inkKg, $printedAreaM2] = $this->ink($spec, $grossMetres);
        [$tagsPerSheet, $grossSheets] = $this->sheets($spec, $orderQtyPcs, $totalWastagePct, $routing);
        [$bundles, $polybags, $cartons] = $this->packing($spec, $orderQtyPcs);

        $requiredImpressions = $spec->productType->consumesSheets()
            ? (float) $grossSheets
            : $grossMetres * $labelsPerMetre;

        return new ConsumptionPlan(
            orderQtyPcs: $orderQtyPcs,
            pitchMm: $pitchMm,
            labelsPerMetre: $labelsPerMetre,
            ends: $ends,
            suggestedEnds: $suggestedEnds,
            labelsPerWebMetre: $labelsPerWebMetre,
            totalWastagePct: $totalWastagePct,
            setupMetres: $setupMetres,
            netMetres: $netMetres,
            grossMetres: $grossMetres,
            yarnKg: $yarnKg,
            warpKg: $warpKg,
            weftKg: $weftKg,
            weftKgByColour: $weftByColour,
            inkKg: $inkKg,
            printedAreaM2: $printedAreaM2,
            tagsPerSheet: $tagsPerSheet,
            grossSheets: $grossSheets,
            bundles: $bundles,
            polybags: $polybags,
            cartons: $cartons,
            requiredImpressions: $requiredImpressions,
        );
    }

    /** BR-4 — pitch is the label plus the ribbon the cutter eats between labels. */
    public function pitchMm(SpecInput $spec): float
    {
        return $spec->labelHeightMm + $spec->effectiveCutGapMm();
    }

    /** BR-4 — labels per metre in the length direction. */
    public function labelsPerMetre(SpecInput $spec): float
    {
        return 1000.0 / $this->pitchMm($spec);
    }

    /** BR-5 — how many label columns fit across the usable web width. */
    public function suggestedEnds(SpecInput $spec): int
    {
        $usableWidthMm = $spec->webWidthMm - (2 * $spec->selvedgeMm);
        $lanePitchMm = $spec->labelWidthMm + $spec->laneGapMm;

        if ($lanePitchMm <= 0) {
            throw new InvalidArgumentException('Label width plus lane gap must be greater than zero (BR-5).');
        }

        return (int) floor($usableWidthMm / $lanePitchMm);
    }

    /** BR-6 */
    public function labelsPerWebMetre(float $labelsPerMetre, int $ends): float
    {
        return $labelsPerMetre * $ends;
    }

    /**
     * BR-8 — wastage is additive across the routing, counting only operations that consume
     * the web. Packing and QC do not eat ribbon.
     *
     * @param  list<RoutingStep>  $routing
     */
    public function totalWastagePct(array $routing): float
    {
        return array_sum(array_map(
            fn (RoutingStep $step): float => $step->consumesWeb ? $step->wastagePct : 0.0,
            $routing,
        ));
    }

    /**
     * BR-7 — make-ready is additive in absolute units, not a percentage: bringing a press to
     * saleable quality costs the same 80 m whether the order is 5,000 or 500,000.
     *
     * @param  list<RoutingStep>  $routing
     */
    public function setupMetres(array $routing): float
    {
        return array_sum(array_map(
            fn (RoutingStep $step): float => $step->consumesWeb ? $step->setupQty : 0.0,
            $routing,
        ));
    }

    /** BR-7 */
    public function grossMetres(float $netMetres, float $totalWastagePct, float $setupMetres): float
    {
        return $netMetres * (1 + $totalWastagePct / 100) + $setupMetres;
    }

    /**
     * BR-9 — yarn for woven labels, split warp/weft and then weft across colours.
     *
     * @param  array<int, float>  $colourWeights
     * @return array{0: float, 1: float, 2: float, 3: array<string, float>}
     */
    private function yarn(SpecInput $spec, float $grossMetres, array $colourWeights): array
    {
        if (! $spec->productType->consumesYarn() || $spec->fabricGsm <= 0) {
            return [0.0, 0.0, 0.0, []];
        }

        $gramsPerLinearMetre = ($spec->webWidthMm / 1000) * $spec->fabricGsm;
        $yarnKg = $grossMetres * $gramsPerLinearMetre / 1000;
        $warpKg = $yarnKg * $spec->warpRatio;
        $weftKg = $yarnKg - $warpKg;

        return [$yarnKg, $warpKg, $weftKg, $this->splitWeft($weftKg, $colourWeights, $spec->colours)];
    }

    /**
     * BR-9 — colour-wise weft split from the spec's colour weighting; even split if absent.
     *
     * PHP folds a numeric string key back to an integer, so the key type here is
     * `array-key` rather than `string` however it is written.
     *
     * @param  array<int, float>  $colourWeights
     * @return array<array-key, float>
     */
    private function splitWeft(float $weftKg, array $colourWeights, int $colours): array
    {
        $totalWeight = array_sum($colourWeights);

        if ($colourWeights !== [] && $totalWeight > 0) {
            $split = [];

            foreach ($colourWeights as $index => $weight) {
                $split[(string) $index] = $weftKg * ($weight / $totalWeight);
            }

            return $split;
        }

        $split = [];

        for ($index = 1; $index <= $colours; $index++) {
            $split[(string) $index] = $weftKg / $colours;
        }

        return $split;
    }

    /**
     * BR-10 — ink for flexo, screen, offset and heat transfer.
     *
     * @return array{0: float, 1: float}
     */
    private function ink(SpecInput $spec, float $grossMetres): array
    {
        if (! $spec->productType->consumesInk() || $spec->coveragePct <= 0) {
            return [0.0, 0.0];
        }

        $printedAreaM2 = $grossMetres * ($spec->webWidthMm / 1000);

        $inkKg = ($spec->coveragePct / 100)
            * $printedAreaM2
            * $spec->effectiveInkLayGsm()
            * $spec->colours
            / 1000;

        return [$inkKg, $printedAreaM2];
    }

    /**
     * BR-11 — offset tags and tickets are imposed on a sheet, not run off a web.
     *
     * @param  list<RoutingStep>  $routing
     * @return array{0: int, 1: int}
     */
    private function sheets(SpecInput $spec, int $orderQtyPcs, float $wastagePct, array $routing): array
    {
        if (! $spec->productType->consumesSheets() || $spec->sheetLengthMm <= 0 || $spec->sheetWidthMm <= 0) {
            return [0, 0];
        }

        $down = (int) floor($spec->sheetLengthMm / ($spec->labelHeightMm + $spec->bleedMm));
        $across = (int) floor($spec->sheetWidthMm / ($spec->labelWidthMm + $spec->bleedMm));
        $tagsPerSheet = $down * $across;

        if ($tagsPerSheet < 1) {
            throw new InvalidArgumentException('No tag fits on the declared sheet size (BR-11).');
        }

        $setupSheets = (int) round(array_sum(array_map(
            fn (RoutingStep $step): float => $step->consumesWeb ? $step->setupQty : 0.0,
            $routing,
        )));

        $grossSheets = (int) ceil($orderQtyPcs / $tagsPerSheet * (1 + $wastagePct / 100)) + $setupSheets;

        return [$tagsPerSheet, $grossSheets];
    }

    /**
     * BR-12 — bundles, polybags and cartons.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function packing(SpecInput $spec, int $orderQtyPcs): array
    {
        $bundleSize = max(1, $spec->bundleSize);
        $bundlesPerCarton = max(1, $spec->bundlesPerCarton);

        $bundles = (int) ceil($orderQtyPcs / $bundleSize);
        $cartons = (int) ceil($bundles / $bundlesPerCarton);

        return [$bundles, $bundles, $cartons];
    }

    /**
     * BR-13 — how many tools this product needs, and whether existing ones cover it.
     *
     * @param  list<array{colour_index: int|null, remaining_life_impressions: float}>  $existingTools
     * @return array{required: int, reusable: int, to_purchase: int, required_impressions: float}
     */
    public function toolRequirement(SpecInput $spec, ConsumptionPlan $plan, array $existingTools = []): array
    {
        $required = 0;

        if ($spec->productType->requiresToolPerColour()) {
            $required += $spec->colours;
        }

        if ($spec->cutType->requiresTool()) {
            $required += 1;
        }

        if ($required === 0) {
            return ['required' => 0, 'reusable' => 0, 'to_purchase' => 0, 'required_impressions' => 0.0];
        }

        $reusable = count(array_filter(
            $existingTools,
            fn (array $tool): bool => $tool['remaining_life_impressions'] >= $plan->requiredImpressions,
        ));

        $reusable = min($reusable, $required);

        return [
            'required' => $required,
            'reusable' => $reusable,
            'to_purchase' => $required - $reusable,
            'required_impressions' => $plan->requiredImpressions,
        ];
    }
}
