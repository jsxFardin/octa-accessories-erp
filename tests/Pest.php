<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Unit tests are pure calculators — no database, no framework. Feature tests get the real
 * MySQL schema, because the invariants they assert are enforced by the database.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * The vocabulary rows as `ReferenceDataSeeder` writes them, without a database.
 *
 * Product type and cut type behaviour is data now (docs/02a-schema.sql §1a), so a unit test of
 * a calculator has to state which behaviour it is testing. These are the seeded figures;
 * `VocabularySeedTest` asserts the rows in the database still match them, which is what fails
 * if someone edits the seeder and not the rules it encodes.
 *
 * @var array<string, array{0: bool, 1: bool, 2: float|null, 3: bool}>
 */
const PRODUCT_TYPE_FIXTURES = [
    //                      yarn,  sheets, ink g/m², tool per colour
    'woven' => [true, false, null, false],
    'flexo' => [false, false, 1.6, true],
    'screen' => [false, false, 8.0, true],
    'heat_transfer' => [false, false, 12.0, true],
    'offset_tag' => [false, true, 1.1, true],
    'thermal' => [false, false, null, false],
    'ribbon' => [false, false, null, false],
    'tape' => [false, false, null, false],
    'other' => [false, false, null, false],
];

/** @var array<string, array{0: float, 1: bool}> gap mm, needs a die */
const CUT_TYPE_FIXTURES = [
    'hot_cut' => [2.0, false],
    'ultrasonic' => [2.0, false],
    'laser' => [1.5, false],
    'die_cut' => [3.0, true],
    'straight_cut' => [1.0, false],
];

function productTypeRule(string $code): App\Support\Calculators\ProductTypeRule
{
    [$yarn, $sheets, $ink, $tool] = PRODUCT_TYPE_FIXTURES[$code];

    return new App\Support\Calculators\ProductTypeRule($code, $code, $yarn, $sheets, $ink, $tool);
}

function cutTypeRule(string $code): App\Support\Calculators\CutTypeRule
{
    [$gap, $tool] = CUT_TYPE_FIXTURES[$code];

    return new App\Support\Calculators\CutTypeRule($code, $code, $gap, $tool);
}

/**
 * `SpecInput::fromArray` with the two rules resolved from the fixtures above — what the
 * application does with the vocabulary tables, done from constants instead.
 *
 * @param  array<string, mixed>  $spec
 */
function fixtureSpec(array $spec): App\Support\Calculators\SpecInput
{
    return App\Support\Calculators\SpecInput::fromArray(
        $spec,
        productTypeRule((string) ($spec['product_type'] ?? 'woven')),
        cutTypeRule((string) ($spec['cut_type'] ?? 'hot_cut')),
    );
}

/**
 * Assert a decimal to the precision the schema actually stores (AD-7).
 */
expect()->extend('toBeMoney', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 4))->toBe(round($expected, 4));
});

expect()->extend('toBeQty', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 6))->toBe(round($expected, 6));
});
