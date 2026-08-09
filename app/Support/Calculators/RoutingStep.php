<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * One routing operation as the calculators see it (BR-8, BR-16, BR-17, BR-18).
 *
 * `consumesWeb = false` marks operations — packing, QC — that must be excluded from the
 * additive wastage total, otherwise wrapping a carton would be charged ribbon.
 */
final readonly class RoutingStep
{
    public function __construct(
        public int $sequenceNo,
        public string $code,
        public string $name,
        public float $wastagePct = 0.0,
        public float $setupQty = 0.0,
        public bool $consumesWeb = true,
        public float $stdRatePerHour = 0.0,
        public float $setupMinutes = 0.0,
        public float $manningLevel = 1.0,
        public float $machineHourlyRate = 0.0,
        public float $machineKwRating = 0.0,
        public float $labourRatePerHour = 0.0,
        public bool $allowParallel = false,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            sequenceNo: (int) ($row['sequence_no'] ?? 1),
            code: (string) ($row['code'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            wastagePct: (float) ($row['wastage_pct'] ?? 0),
            setupQty: (float) ($row['setup_qty'] ?? 0),
            consumesWeb: (bool) ($row['consumes_web'] ?? true),
            stdRatePerHour: (float) ($row['std_rate_per_hour'] ?? 0),
            setupMinutes: (float) ($row['setup_minutes'] ?? 0),
            manningLevel: (float) ($row['manning_level'] ?? 1),
            machineHourlyRate: (float) ($row['machine_hourly_rate'] ?? 0),
            machineKwRating: (float) ($row['machine_kw_rating'] ?? 0),
            labourRatePerHour: (float) ($row['labour_rate_per_hour'] ?? 0),
            allowParallel: (bool) ($row['allow_parallel'] ?? false),
        );
    }
}
