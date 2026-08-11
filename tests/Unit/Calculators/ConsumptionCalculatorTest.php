<?php

declare(strict_types=1);

use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\RoutingStep;
use App\Support\Calculators\SpecInput;

/*
 * One test per business rule ID (08-architecture §9). When someone disputes a consumption
 * figure, the argument is settled by opening the test named after the rule.
 *
 * The worked example throughout is the domain walkthrough from the specification README:
 * 50,000 centre-fold satin woven care labels.
 */

beforeEach(function (): void {
    $this->calc = new ConsumptionCalculator;
});

/** A 40 × 20 mm satin woven care label on a 220 mm loom. */
function wovenSpec(array $overrides = []): SpecInput
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
        'warp_ratio' => 0.60,
        'colours' => 2,
    ], $overrides));
}

/** The weaving routing from the BR-8 seed table. */
function wovenRouting(): array
{
    return [
        new RoutingStep(1, 'warp', 'Warping', wastagePct: 1.5, setupQty: 30, stdRatePerHour: 400, machineHourlyRate: 120, machineKwRating: 5, manningLevel: 0.5),
        new RoutingStep(2, 'weave', 'Weaving', wastagePct: 3.0, setupQty: 50, stdRatePerHour: 120, machineHourlyRate: 180, machineKwRating: 3.5, manningLevel: 0.25),
        new RoutingStep(3, 'cut', 'Cutting / folding', wastagePct: 2.0, setupQty: 10, stdRatePerHour: 300, machineHourlyRate: 90, machineKwRating: 1.5, manningLevel: 1.0),
        new RoutingStep(4, 'pack', 'Packing', wastagePct: 0, setupQty: 0, consumesWeb: false, stdRatePerHour: 5000, machineHourlyRate: 20, manningLevel: 2.0),
    ];
}

it('br4: computes pitch and labels per metre from label height plus cut gap', function (): void {
    $spec = wovenSpec();

    // 20 mm label + 2.0 mm ultrasonic cut gap = 22 mm pitch
    expect($this->calc->pitchMm($spec))->toBe(22.0)
        ->and($this->calc->labelsPerMetre($spec))->toBeQty(1000 / 22);
});

it('br4: applies the default cut gap for every cut type', function (): void {
    // The gaps are rows in `cut_types` now; VocabularySeedTest holds the database to these
    // same figures.
    expect(cutTypeRule('hot_cut')->defaultCutGapMm())->toBe(2.0)
        ->and(cutTypeRule('ultrasonic')->defaultCutGapMm())->toBe(2.0)
        ->and(cutTypeRule('laser')->defaultCutGapMm())->toBe(1.5)
        ->and(cutTypeRule('die_cut')->defaultCutGapMm())->toBe(3.0)
        ->and(cutTypeRule('straight_cut')->defaultCutGapMm())->toBe(1.0);
});

it('br4: a spec-level cut gap overrides the cut type default', function (): void {
    $spec = wovenSpec(['cut_gap_mm' => 3.5]);

    expect($this->calc->pitchMm($spec))->toBe(23.5);
});

it('br5: suggests ends from the usable width, discounting both selvedges', function (): void {
    $spec = wovenSpec();

    // (220 − 2×5) / (40 + 2) = 210 / 42 = 5 exactly
    expect($this->calc->suggestedEnds($spec))->toBe(5);
});

it('br5: floors a partial end rather than rounding it up', function (): void {
    // 210 / 41 = 5.12 → 5 ends. Half a label column does not weave.
    expect($this->calc->suggestedEnds(wovenSpec(['lane_gap_mm' => 1])))->toBe(5);
});

it('br5: rejects a spec whose web is too narrow for one label', function (): void {
    $spec = wovenSpec(['web_width_mm' => 30, 'ends' => null]);

    expect(fn () => $this->calc->plan($spec, 50_000))
        ->toThrow(InvalidArgumentException::class, 'Ends must be at least 1');
});

it('br5: the engineering-decided ends on the spec wins over the suggestion', function (): void {
    $plan = $this->calc->plan(wovenSpec(['ends' => 4]), 50_000);

    expect($plan->ends)->toBe(4)
        ->and($plan->suggestedEnds)->toBe(5);
});

it('br6: labels per web metre is labels per metre times ends', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000);

    expect($plan->labelsPerWebMetre)->toBeQty(1000 / 22 * 5);
});

it('br7: gross metres adds additive wastage and absolute make-ready to net metres', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting());

    $expectedNet = 50_000 / (1000 / 22 * 5);
    $expectedGross = $expectedNet * (1 + 6.5 / 100) + 90;

    expect($plan->netMetres)->toBeQty($expectedNet)
        ->and($plan->grossMetres)->toBeQty($expectedGross);
});

it('br8: wastage is additive across web-consuming operations only', function (): void {
    // 1.5 warping + 3.0 weaving + 2.0 cutting = 6.5; packing consumes no web.
    expect($this->calc->totalWastagePct(wovenRouting()))->toBe(6.5);
});

it('br8: make-ready is absolute and also excludes non-web operations', function (): void {
    expect($this->calc->setupMetres(wovenRouting()))->toBe(90.0);
});

it('br9: derives yarn from web width and fabric gsm, split warp and weft', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting());

    $gramsPerMetre = (220 / 1000) * 120;              // 26.4 g per linear metre
    $expectedYarn = $plan->grossMetres * $gramsPerMetre / 1000;

    expect($plan->yarnKg)->toBeQty($expectedYarn)
        ->and($plan->warpKg)->toBeQty($expectedYarn * 0.60)
        ->and($plan->weftKg)->toBeQty($expectedYarn * 0.40)
        ->and($plan->warpKg + $plan->weftKg)->toBeQty($plan->yarnKg);
});

it('br9: splits weft evenly across colours when the spec carries no weighting', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting());

    expect($plan->weftKgByColour)->toHaveCount(2)
        ->and($plan->weftKgByColour['1'])->toBeQty($plan->weftKg / 2);
});

it('br9: splits weft by the colour list weighting when present', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting(), [1 => 75.0, 2 => 25.0]);

    expect($plan->weftKgByColour['1'])->toBeQty($plan->weftKg * 0.75)
        ->and($plan->weftKgByColour['2'])->toBeQty($plan->weftKg * 0.25);
});

it('br9: a non-woven product consumes no yarn', function (): void {
    $plan = $this->calc->plan(wovenSpec(['product_type' => 'flexo', 'coverage_pct' => 30]), 50_000);

    expect($plan->yarnKg)->toBe(0.0);
});

it('br10: derives ink from coverage, printed area, ink lay and colour count', function (): void {
    $spec = wovenSpec(['product_type' => 'flexo', 'coverage_pct' => 35, 'colours' => 3, 'fabric_gsm' => 0]);
    $plan = $this->calc->plan($spec, 50_000, wovenRouting());

    $expectedArea = $plan->grossMetres * (220 / 1000);
    $expectedInk = 0.35 * $expectedArea * 1.6 * 3 / 1000;

    expect($plan->printedAreaM2)->toBeQty($expectedArea)
        ->and($plan->inkKg)->toBeQty($expectedInk);
});

it('br10: uses the process default ink lay unless the item master overrides it', function (): void {
    expect(productTypeRule('flexo')->defaultInkLayGsm())->toBe(1.6)
        ->and(productTypeRule('screen')->defaultInkLayGsm())->toBe(8.0)
        ->and(productTypeRule('offset_tag')->defaultInkLayGsm())->toBe(1.1)
        ->and(productTypeRule('heat_transfer')->defaultInkLayGsm())->toBe(12.0)
        ->and(productTypeRule('woven')->defaultInkLayGsm())->toBeNull();

    $override = wovenSpec(['product_type' => 'flexo', 'coverage_pct' => 35, 'ink_lay_gsm' => 2.4]);

    expect($override->effectiveInkLayGsm())->toBe(2.4);
});

it('br11: imposes tags on a sheet and rounds sheets up', function (): void {
    $spec = fixtureSpec([
        'product_type' => 'offset_tag',
        'cut_type' => 'die_cut',
        'label_width_mm' => 50,
        'label_height_mm' => 90,
        'web_width_mm' => 640,
        'sheet_length_mm' => 900,
        'sheet_width_mm' => 640,
        'bleed_mm' => 3,
        'ends' => 1,
    ]);

    $routing = [new RoutingStep(1, 'offset', 'Offset printing', wastagePct: 3.5, setupQty: 200)];
    $plan = $this->calc->plan($spec, 50_000, $routing);

    // floor(900 / 93) = 9 down, floor(640 / 53) = 12 across
    expect($plan->tagsPerSheet)->toBe(108)
        ->and($plan->grossSheets)->toBe((int) ceil(50_000 / 108 * 1.035) + 200);
});

it('br11: rejects a tag that does not fit the declared sheet', function (): void {
    $spec = fixtureSpec([
        'product_type' => 'offset_tag',
        'cut_type' => 'die_cut',
        'label_width_mm' => 700,
        'label_height_mm' => 1000,
        'web_width_mm' => 640,
        'sheet_length_mm' => 900,
        'sheet_width_mm' => 640,
        'ends' => 1,
    ]);

    expect(fn () => $this->calc->plan($spec, 1000))
        ->toThrow(InvalidArgumentException::class, 'No tag fits');
});

it('br12: rounds bundles and cartons up, one polybag per bundle', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting());

    // 50,000 / 500 = 100 bundles; 100 / 20 = 5 cartons
    expect($plan->bundles)->toBe(100)
        ->and($plan->polybags)->toBe(100)
        ->and($plan->cartons)->toBe(5);
});

it('br12: a part bundle still needs a whole polybag and a whole carton', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_001, wovenRouting());

    expect($plan->bundles)->toBe(101)
        ->and($plan->cartons)->toBe(6);
});

it('br13: a flexo product needs one tool per colour', function (): void {
    $spec = wovenSpec(['product_type' => 'flexo', 'colours' => 4, 'coverage_pct' => 20]);
    $plan = $this->calc->plan($spec, 50_000, wovenRouting());

    expect($this->calc->toolRequirement($spec, $plan)['required'])->toBe(4);
});

it('br13: a die cut adds one tool on top of the print tools', function (): void {
    $spec = wovenSpec(['product_type' => 'flexo', 'cut_type' => 'die_cut', 'colours' => 2, 'coverage_pct' => 20]);
    $plan = $this->calc->plan($spec, 50_000, wovenRouting());

    expect($this->calc->toolRequirement($spec, $plan)['required'])->toBe(3);
});

it('br13: a woven hot-cut label needs no tool at all', function (): void {
    $spec = wovenSpec();
    $plan = $this->calc->plan($spec, 50_000, wovenRouting());

    expect($this->calc->toolRequirement($spec, $plan)['required'])->toBe(0);
});

it('br13: reuses a tool with enough remaining life, and buys only the shortfall', function (): void {
    $spec = wovenSpec(['product_type' => 'flexo', 'colours' => 3, 'coverage_pct' => 20]);
    $plan = $this->calc->plan($spec, 50_000, wovenRouting());

    $tools = [
        ['colour_index' => 1, 'remaining_life_impressions' => 5_000_000.0],
        ['colour_index' => 2, 'remaining_life_impressions' => 5_000_000.0],
        ['colour_index' => 3, 'remaining_life_impressions' => 10.0],   // worn out
    ];

    $requirement = $this->calc->toolRequirement($spec, $plan, $tools);

    expect($requirement['required'])->toBe(3)
        ->and($requirement['reusable'])->toBe(2)
        ->and($requirement['to_purchase'])->toBe(1);
});

it('br13: required impressions are gross metres times labels per metre', function (): void {
    $plan = $this->calc->plan(wovenSpec(), 50_000, wovenRouting());

    expect($plan->requiredImpressions)->toBeQty($plan->grossMetres * $plan->labelsPerMetre);
});

it('rejects a zero or negative order quantity', function (): void {
    expect(fn () => $this->calc->plan(wovenSpec(), 0))
        ->toThrow(InvalidArgumentException::class);
});
