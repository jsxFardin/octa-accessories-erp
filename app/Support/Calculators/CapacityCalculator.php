<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * BR-27 — the capacity model behind the planning board.
 *
 * Available minutes are shift minutes discounted twice: by planned downtime (maintenance,
 * changeover windows) and by the machine's own efficiency. A loom rated at 100 m/h that runs
 * at 85% efficiency does not deliver 100 m/h, and a plan built on the nameplate figure is a
 * plan that slips.
 */
class CapacityCalculator
{
    public function availableMinutes(
        float $shiftMinutes,
        float $plannedDowntimePct = 0.0,
        float $efficiencyPct = 100.0,
    ): float {
        return $shiftMinutes
            * (1 - $plannedDowntimePct / 100)
            * ($efficiencyPct / 100);
    }

    /**
     * Minutes a scheduled operation occupies: run time plus setup.
     */
    public function loadMinutes(float $outputUnits, float $stdRatePerHour, float $setupMinutes = 0.0): float
    {
        if ($stdRatePerHour <= 0) {
            return $setupMinutes;
        }

        return $outputUnits / $stdRatePerHour * 60 + $setupMinutes;
    }

    /**
     * @return array{available: float, load: float, utilisation_pct: float, over_capacity: bool, spare_minutes: float}
     */
    public function utilisation(float $loadMinutes, float $availableMinutes): array
    {
        $utilisation = $availableMinutes > 0 ? $loadMinutes / $availableMinutes * 100 : 0.0;

        return [
            'available' => round($availableMinutes, 2),
            'load' => round($loadMinutes, 2),
            'utilisation_pct' => round($utilisation, 2),
            // The planning board blocks scheduling past 100% unless the planner overrides
            // with a reason — an over-committed machine is a missed delivery date.
            'over_capacity' => $loadMinutes > $availableMinutes,
            'spare_minutes' => round($availableMinutes - $loadMinutes, 2),
        ];
    }

    /**
     * How long a quantity takes on a machine, in hours — the input to a promised date.
     */
    public function runHours(float $outputUnits, float $stdRatePerHour, float $setupMinutes = 0.0): float
    {
        return $this->loadMinutes($outputUnits, $stdRatePerHour, $setupMinutes) / 60;
    }
}
