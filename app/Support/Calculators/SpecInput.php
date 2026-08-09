<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use InvalidArgumentException;

/**
 * The technical inputs the consumption formulas consume, lifted off a `product_specs` row.
 *
 * A value object rather than the model itself so the calculators stay pure and a costing
 * dispute can be reproduced from numbers typed into a test (08-architecture §2).
 */
final readonly class SpecInput
{
    public function __construct(
        public ProductType $productType,
        public CutType $cutType,
        public float $labelWidthMm,
        public float $labelHeightMm,
        public float $webWidthMm,
        public float $selvedgeMm = 0.0,
        public float $laneGapMm = 0.0,
        public ?float $cutGapMm = null,
        public ?int $ends = null,
        public float $fabricGsm = 0.0,
        public float $warpRatio = 0.60,
        public int $colours = 1,
        public float $coveragePct = 0.0,
        public ?float $inkLayGsm = null,
        public int $bundleSize = 500,
        public int $bundlesPerCarton = 20,
        // Offset only (BR-11)
        public float $sheetLengthMm = 0.0,
        public float $sheetWidthMm = 0.0,
        public float $bleedMm = 3.0,
    ) {
        if ($this->labelHeightMm <= 0 || $this->labelWidthMm <= 0) {
            throw new InvalidArgumentException('Label dimensions must be greater than zero.');
        }

        if ($this->warpRatio < 0 || $this->warpRatio > 1) {
            throw new InvalidArgumentException('warp_ratio is a fraction between 0 and 1 (BR-9).');
        }

        if ($this->colours < 1) {
            throw new InvalidArgumentException('A product has at least one colour.');
        }
    }

    /** BR-4 — the spec's own gap wins; otherwise the cut type's default. */
    public function effectiveCutGapMm(): float
    {
        return $this->cutGapMm ?? $this->cutType->defaultCutGapMm();
    }

    /** BR-10 — item master overrides the process default. */
    public function effectiveInkLayGsm(): float
    {
        return $this->inkLayGsm ?? $this->productType->defaultInkLayGsm() ?? 0.0;
    }

    /**
     * Build from a `product_specs` row (or any array with the same keys).
     *
     * @param  array<string, mixed>  $spec
     */
    public static function fromArray(array $spec): self
    {
        return new self(
            productType: ProductType::from((string) ($spec['product_type'] ?? 'woven')),
            cutType: CutType::from((string) ($spec['cut_type'] ?? 'hot_cut')),
            labelWidthMm: (float) ($spec['label_width_mm'] ?? 0),
            labelHeightMm: (float) ($spec['label_height_mm'] ?? 0),
            webWidthMm: (float) ($spec['web_width_mm'] ?? 0),
            selvedgeMm: (float) ($spec['selvedge_mm'] ?? 0),
            laneGapMm: (float) ($spec['lane_gap_mm'] ?? 0),
            cutGapMm: isset($spec['cut_gap_mm']) ? (float) $spec['cut_gap_mm'] : null,
            ends: isset($spec['ends']) ? (int) $spec['ends'] : null,
            fabricGsm: (float) ($spec['fabric_gsm'] ?? 0),
            warpRatio: (float) ($spec['warp_ratio'] ?? 0.60),
            colours: (int) ($spec['colours'] ?? 1),
            coveragePct: (float) ($spec['coverage_pct'] ?? 0),
            inkLayGsm: isset($spec['ink_lay_gsm']) ? (float) $spec['ink_lay_gsm'] : null,
            bundleSize: (int) ($spec['bundle_size'] ?? 500),
            bundlesPerCarton: (int) ($spec['bundles_per_carton'] ?? 20),
            sheetLengthMm: (float) ($spec['sheet_length_mm'] ?? 0),
            sheetWidthMm: (float) ($spec['sheet_width_mm'] ?? 0),
            bleedMm: (float) ($spec['bleed_mm'] ?? 3.0),
        );
    }
}
