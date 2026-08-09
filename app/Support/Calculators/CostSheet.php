<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * The computed cost sheet: typed lines plus the numbers a merchandiser argues about.
 */
final readonly class CostSheet
{
    /**
     * @param  list<CostLine>  $lines
     */
    public function __construct(
        public array $lines,
        public float $materialCost,
        public float $toolingCost,
        public float $machineCost,
        public float $labourCost,
        public float $energyCost,
        public float $directCost,
        public float $factoryOverhead,
        public float $adminOverhead,
        public float $subtotal,
        public float $minimumCharge,
        public float $totalCost,
        public float $unitCost,
        public float $ratePerM,
        public float $ratePerMInCurrency,
        public float $marginPct,
        public float $marginAmount,
        public float $sellingValue,
        public string $currency,
        public float $exchangeRate,
        public bool $belowMinimumOrderValue,
        public float $totalMachineHours,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (CostLine $line): array => $line->toArray(), $this->lines),
            'material_cost' => round($this->materialCost, 4),
            'tooling_cost' => round($this->toolingCost, 4),
            'machine_cost' => round($this->machineCost, 4),
            'labour_cost' => round($this->labourCost, 4),
            'energy_cost' => round($this->energyCost, 4),
            'direct_cost' => round($this->directCost, 4),
            'factory_overhead' => round($this->factoryOverhead, 4),
            'admin_overhead' => round($this->adminOverhead, 4),
            'subtotal' => round($this->subtotal, 4),
            'minimum_charge' => round($this->minimumCharge, 4),
            'total_cost' => round($this->totalCost, 4),
            'unit_cost' => round($this->unitCost, 6),
            'rate_per_m' => round($this->ratePerM, 4),
            'rate_per_m_in_currency' => round($this->ratePerMInCurrency, 4),
            'margin_pct' => round($this->marginPct, 4),
            'margin_amount' => round($this->marginAmount, 4),
            'selling_value' => round($this->sellingValue, 4),
            'currency' => $this->currency,
            'exchange_rate' => round($this->exchangeRate, 6),
            'below_minimum_order_value' => $this->belowMinimumOrderValue,
            'total_machine_hours' => round($this->totalMachineHours, 4),
        ];
    }
}
