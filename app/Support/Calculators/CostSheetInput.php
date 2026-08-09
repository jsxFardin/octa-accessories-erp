<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * Everything the cost sheet needs that is not derivable from the consumption plan.
 *
 * These are the values invariant Q1 requires to be snapshotted onto the sheet when a
 * quotation is sent: item rates, machine rates, overhead percentages and the exchange rate.
 * A sent quotation must never change because master data moved.
 */
final readonly class CostSheetInput
{
    /**
     * @param  array<string, float>  $materialRates  cost_type => rate per base UoM, in BDT
     * @param  array<int, float>  $colourWeights
     */
    public function __construct(
        public SpecInput $spec,
        public int $orderQtyPcs,
        /** @var list<RoutingStep> */
        public array $routing = [],
        public array $materialRates = [],
        public array $colourWeights = [],
        public float $labourRatePerHour = 0.0,
        public float $tariffPerKwh = 0.0,
        public float $toolingCost = 0.0,
        public bool $customerPaysTooling = false,
        public ?float $amortisationQty = null,
        public float $packingRatePerBundle = 0.0,
        public float $packingRatePerPolybag = 0.0,
        public float $packingRatePerCarton = 0.0,
        public float $outsourcingCost = 0.0,
        public float $freightCost = 0.0,
        public float $overheadPct = 12.0,
        public float $adminPct = 5.0,
        public float $marginPct = 20.0,
        public float $minOrderValue = 0.0,
        public float $exchangeRate = 1.0,
        public string $currency = 'BDT',
    ) {}
}
