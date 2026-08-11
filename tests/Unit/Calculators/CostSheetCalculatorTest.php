<?php

declare(strict_types=1);

use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Calculators\CostSheetInput;
use App\Support\Calculators\RoutingStep;
use App\Support\Calculators\SpecInput;

beforeEach(function (): void {
    $this->calc = new CostSheetCalculator(new ConsumptionCalculator);
});

function costSpec(array $overrides = []): SpecInput
{
    return fixtureSpec(array_merge([
        'product_type' => 'woven',
        'cut_type' => 'ultrasonic',
        'label_width_mm' => 40,
        'label_height_mm' => 20,
        'web_width_mm' => 220,
        'selvedge_mm' => 5,
        'lane_gap_mm' => 2,
        'fabric_gsm' => 120,
        'colours' => 2,
    ], $overrides));
}

function costRouting(): array
{
    return [
        new RoutingStep(1, 'warp', 'Warping', wastagePct: 1.5, setupQty: 30, stdRatePerHour: 400, setupMinutes: 45, manningLevel: 0.5, machineHourlyRate: 120, machineKwRating: 5, labourRatePerHour: 80),
        new RoutingStep(2, 'weave', 'Weaving', wastagePct: 3.0, setupQty: 50, stdRatePerHour: 120, setupMinutes: 60, manningLevel: 0.25, machineHourlyRate: 180, machineKwRating: 3.5, labourRatePerHour: 80),
        new RoutingStep(3, 'cut', 'Cutting / folding', wastagePct: 2.0, setupQty: 10, stdRatePerHour: 300, setupMinutes: 20, manningLevel: 1.0, machineHourlyRate: 90, machineKwRating: 1.5, labourRatePerHour: 70),
    ];
}

function costInput(array $overrides = []): CostSheetInput
{
    return new CostSheetInput(
        spec: $overrides['spec'] ?? costSpec(),
        orderQtyPcs: $overrides['orderQtyPcs'] ?? 50_000,
        routing: $overrides['routing'] ?? costRouting(),
        materialRates: $overrides['materialRates'] ?? ['material_yarn' => 1450.0],
        labourRatePerHour: 80.0,
        tariffPerKwh: $overrides['tariffPerKwh'] ?? 12.0,
        toolingCost: $overrides['toolingCost'] ?? 0.0,
        customerPaysTooling: $overrides['customerPaysTooling'] ?? false,
        amortisationQty: $overrides['amortisationQty'] ?? null,
        packingRatePerBundle: 1.5,
        packingRatePerPolybag: 2.0,
        packingRatePerCarton: 45.0,
        overheadPct: $overrides['overheadPct'] ?? 12.0,
        adminPct: $overrides['adminPct'] ?? 5.0,
        marginPct: $overrides['marginPct'] ?? 20.0,
        minOrderValue: $overrides['minOrderValue'] ?? 0.0,
        exchangeRate: $overrides['exchangeRate'] ?? 1.0,
        currency: $overrides['currency'] ?? 'BDT',
    );
}

it('br14: orders cost lines by type and stamps each with the rule that produced it', function (): void {
    $sheet = $this->calc->build(costInput());
    $types = array_map(fn ($line): string => $line->costType, $sheet->lines);

    expect($types)->toContain('material_yarn', 'machine', 'labour', 'energy', 'packing', 'overhead', 'admin_overhead', 'margin');

    $yarn = collect($sheet->lines)->firstWhere('costType', 'material_yarn');
    expect($yarn->formulaRef)->toBe('BR-9');

    $seqs = array_map(fn ($line): int => $line->seq, $sheet->lines);
    expect($seqs)->toBe(array_values(collect($seqs)->sort()->values()->all()));
});

it('br15: a customer-funded tool never enters the /M rate', function (): void {
    $with = $this->calc->build(costInput(['toolingCost' => 40_000.0, 'customerPaysTooling' => true]));
    $without = $this->calc->build(costInput(['toolingCost' => 0.0]));

    expect($with->toolingCost)->toBe(0.0)
        ->and($with->ratePerM)->toBeMoney($without->ratePerM);
});

it('br15: tooling amortises over the order quantity by default', function (): void {
    $sheet = $this->calc->build(costInput(['toolingCost' => 40_000.0]));

    expect($sheet->toolingCost)->toBeMoney(40_000.0);
});

it('br15: a running programme amortises tooling over the annual forecast, not this order', function (): void {
    $sheet = $this->calc->build(costInput(['toolingCost' => 40_000.0, 'amortisationQty' => 500_000.0]));

    // 40,000 spread over 500,000 pieces, of which this order takes 50,000 → 4,000
    expect($sheet->toolingCost)->toBeMoney(4_000.0);
});

it('br16: machine cost is run hours plus setup, at the machine hourly rate', function (): void {
    $sheet = $this->calc->build(costInput());
    $plan = (new ConsumptionCalculator)->plan(costSpec(), 50_000, costRouting());

    $expected = 0.0;
    $expectedHours = 0.0;

    foreach (costRouting() as $step) {
        $hours = $plan->grossMetres / $step->stdRatePerHour + $step->setupMinutes / 60;
        $expectedHours += $hours;
        $expected += $hours * $step->machineHourlyRate;
    }

    expect($sheet->machineCost)->toBeMoney($expected)
        ->and($sheet->totalMachineHours)->toBeQty($expectedHours);
});

it('br17: labour cost scales with manning level, so one operator on four looms costs a quarter', function (): void {
    $sheet = $this->calc->build(costInput());

    $manned = array_map(
        fn (RoutingStep $s): RoutingStep => new RoutingStep(
            $s->sequenceNo, $s->code, $s->name, $s->wastagePct, $s->setupQty, $s->consumesWeb,
            $s->stdRatePerHour, $s->setupMinutes, manningLevel: 1.0,
            machineHourlyRate: $s->machineHourlyRate, machineKwRating: $s->machineKwRating,
            labourRatePerHour: $s->labourRatePerHour,
        ),
        costRouting(),
    );

    $fullyManned = $this->calc->build(costInput(['routing' => $manned]));

    expect($sheet->labourCost)->toBeLessThan($fullyManned->labourCost);
});

it('br18: energy cost is machine hours times kW rating times tariff', function (): void {
    $sheet = $this->calc->build(costInput());
    $plan = (new ConsumptionCalculator)->plan(costSpec(), 50_000, costRouting());

    $expected = 0.0;

    foreach (costRouting() as $step) {
        $hours = $plan->grossMetres / $step->stdRatePerHour + $step->setupMinutes / 60;
        $expected += $hours * $step->machineKwRating * 12.0;
    }

    expect($sheet->energyCost)->toBeMoney($expected);
});

it('br19: factory overhead applies to direct cost, admin overhead to the subtotal', function (): void {
    $sheet = $this->calc->build(costInput());

    expect($sheet->factoryOverhead)->toBeMoney($sheet->directCost * 0.12)
        ->and($sheet->directCost)->toBeMoney(
            $sheet->materialCost + $sheet->toolingCost + $sheet->machineCost + $sheet->labourCost + $sheet->energyCost,
        );

    $adminLine = collect($sheet->lines)->firstWhere('costType', 'admin_overhead');
    expect($adminLine->amount)->toBeMoney($adminLine->rate * 0.05);
});

it('br20: margin is applied on price, not on cost', function (): void {
    // The whole rule in one assertion. 100 unit cost at 20% margin sells for 125 per 1000,
    // not 120: margin is the share of the *price*.
    expect($this->calc->ratePerM(0.100, 20.0))->toBeMoney(125.0)
        ->and($this->calc->ratePerM(0.100, 20.0))->not->toBeMoney(120.0);
});

it('br20: the realised margin equals the quoted margin percentage of the selling value', function (): void {
    $sheet = $this->calc->build(costInput(['marginPct' => 25.0]));

    expect($sheet->marginAmount / $sheet->sellingValue * 100)->toBeMoney(25.0);
});

it('br20: margin on cost would understate the price — the error this rule exists to prevent', function (): void {
    $sheet = $this->calc->build(costInput(['marginPct' => 30.0]));

    $marginOnCost = $sheet->unitCost * 1000 * 1.30;

    expect($sheet->ratePerM)->toBeGreaterThan($marginOnCost);
});

it('br20: refuses a margin of 100% or more, which has no finite price', function (): void {
    expect(fn () => $this->calc->ratePerM(1.0, 100.0))->toThrow(InvalidArgumentException::class);
});

it('br21: flags and charges a shortfall against the customer minimum order value', function (): void {
    $small = $this->calc->build(costInput(['orderQtyPcs' => 500, 'minOrderValue' => 25_000.0]));

    expect($small->belowMinimumOrderValue)->toBeTrue()
        ->and($small->totalCost)->toBeMoney(25_000.0)
        ->and(collect($small->lines)->firstWhere('costType', 'minimum_charge'))->not->toBeNull();
});

it('br21: a large enough order carries no minimum charge', function (): void {
    $sheet = $this->calc->build(costInput(['minOrderValue' => 1_000.0]));

    expect($sheet->belowMinimumOrderValue)->toBeFalse()
        ->and($sheet->minimumCharge)->toBe(0.0);
});

it('br22: converts the BDT rate into the quotation currency at the snapshotted rate', function (): void {
    $sheet = $this->calc->build(costInput(['exchangeRate' => 120.0, 'currency' => 'USD']));

    expect($sheet->ratePerMInCurrency)->toBeMoney($sheet->ratePerM / 120.0)
        ->and($sheet->currency)->toBe('USD');
});

it('br22: refuses a zero exchange rate rather than dividing by it', function (): void {
    expect(fn () => $this->calc->convert(100.0, 0.0))->toThrow(InvalidArgumentException::class);
});

it('br1: a line value is quantity over 1000 times the rate per M', function (): void {
    expect($this->calc->lineValue(50_000, 3.25))->toBeMoney(162.5);
});

it('br23: computes actual unit cost and variance against the quote', function (): void {
    $variance = $this->calc->variance(
        actualMaterialCost: 120_000,
        actualMachineCost: 30_000,
        actualLabourCost: 15_000,
        actualEnergyCost: 5_000,
        goodQtyProduced: 50_000,
        quotedUnitCost: 3.20,
    );

    expect($variance['actual_unit_cost'])->toBe(3.4)
        ->and($variance['variance_pct'])->toBe(6.25)
        ->and($variance['variance_amount'])->toBeMoney(10_000.0);
});

it('br23: needs a produced quantity before it can compute a variance', function (): void {
    expect(fn () => $this->calc->variance(1, 1, 1, 1, 0, 1))->toThrow(InvalidArgumentException::class);
});

it('br47: document totals are the sum of rounded line values, so the sheet foots', function (): void {
    $sheet = $this->calc->build(costInput());

    $nonMargin = collect($sheet->lines)
        ->reject(fn ($line): bool => $line->costType === 'margin')
        ->sum(fn ($line): float => $line->amount);

    expect(round($nonMargin, 4))->toBe(round($sheet->totalCost, 4));
});
