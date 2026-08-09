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
 * Assert a decimal to the precision the schema actually stores (AD-7).
 */
expect()->extend('toBeMoney', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 4))->toBe(round($expected, 4));
});

expect()->extend('toBeQty', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 6))->toBe(round($expected, 6));
});
