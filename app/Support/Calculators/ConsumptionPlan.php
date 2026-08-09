<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * The output of ConsumptionCalculator — everything a cost sheet, a BOM explosion and a job
 * card need, computed once.
 *
 * A job card snapshots these figures at release: if engineering revises the spec mid-run,
 * the job card keeps producing to the numbers it was released with (02-database-schema §3.8).
 */
final readonly class ConsumptionPlan
{
    /**
     * @param  array<array-key, float>  $weftKgByColour
     */
    public function __construct(
        public int $orderQtyPcs,
        public float $pitchMm,
        public float $labelsPerMetre,
        public int $ends,
        public int $suggestedEnds,
        public float $labelsPerWebMetre,
        public float $totalWastagePct,
        public float $setupMetres,
        public float $netMetres,
        public float $grossMetres,
        public float $yarnKg = 0.0,
        public float $warpKg = 0.0,
        public float $weftKg = 0.0,
        public array $weftKgByColour = [],
        public float $inkKg = 0.0,
        public float $printedAreaM2 = 0.0,
        public int $tagsPerSheet = 0,
        public int $grossSheets = 0,
        public int $bundles = 0,
        public int $polybags = 0,
        public int $cartons = 0,
        public float $requiredImpressions = 0.0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_qty_pcs' => $this->orderQtyPcs,
            'pitch_mm' => round($this->pitchMm, 2),
            'labels_per_metre' => round($this->labelsPerMetre, 6),
            'ends' => $this->ends,
            'suggested_ends' => $this->suggestedEnds,
            'labels_per_web_metre' => round($this->labelsPerWebMetre, 6),
            'total_wastage_pct' => round($this->totalWastagePct, 4),
            'setup_metres' => round($this->setupMetres, 6),
            'net_metres' => round($this->netMetres, 6),
            'gross_metres' => round($this->grossMetres, 6),
            'yarn_kg' => round($this->yarnKg, 6),
            'warp_kg' => round($this->warpKg, 6),
            'weft_kg' => round($this->weftKg, 6),
            'weft_kg_by_colour' => array_map(fn (float $kg): float => round($kg, 6), $this->weftKgByColour),
            'ink_kg' => round($this->inkKg, 6),
            'printed_area_m2' => round($this->printedAreaM2, 6),
            'tags_per_sheet' => $this->tagsPerSheet,
            'gross_sheets' => $this->grossSheets,
            'bundles' => $this->bundles,
            'polybags' => $this->polybags,
            'cartons' => $this->cartons,
            'required_impressions' => round($this->requiredImpressions, 2),
        ];
    }
}
