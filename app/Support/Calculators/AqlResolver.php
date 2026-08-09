<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use InvalidArgumentException;

/**
 * BR-30, BR-31 — ISO 2859-1 sampling and DHU.
 *
 * The plan table is seeded data (`aql_plans`), not code, so switching a demanding brand to
 * Level I or AQL 1.5 is a data change. This resolver holds the *rule*: pick the row whose lot
 * range contains the lot size, then compare defects against its reject number.
 *
 * The verdict is computed, never typed. An inspector chooses the disposition, not the result
 * (05-workflows §9).
 */
class AqlResolver
{
    /**
     * General Inspection Level II, AQL 2.5 — the house default for labels (BR-30).
     * Used when the database has no plan rows, so a fresh install still inspects correctly.
     *
     * @var list<array{min: int, max: int|null, sample: int, accept: int, reject: int}>
     */
    private const DEFAULT_PLAN = [
        ['min' => 51, 'max' => 90, 'sample' => 13, 'accept' => 1, 'reject' => 2],
        ['min' => 91, 'max' => 150, 'sample' => 20, 'accept' => 1, 'reject' => 2],
        ['min' => 151, 'max' => 280, 'sample' => 32, 'accept' => 2, 'reject' => 3],
        ['min' => 281, 'max' => 500, 'sample' => 50, 'accept' => 3, 'reject' => 4],
        ['min' => 501, 'max' => 1200, 'sample' => 80, 'accept' => 5, 'reject' => 6],
        ['min' => 1201, 'max' => 3200, 'sample' => 125, 'accept' => 7, 'reject' => 8],
        ['min' => 3201, 'max' => 10000, 'sample' => 200, 'accept' => 10, 'reject' => 11],
        ['min' => 10001, 'max' => 35000, 'sample' => 315, 'accept' => 14, 'reject' => 15],
        ['min' => 35001, 'max' => 150000, 'sample' => 500, 'accept' => 21, 'reject' => 22],
        ['min' => 150001, 'max' => 500000, 'sample' => 800, 'accept' => 21, 'reject' => 22],
        ['min' => 500001, 'max' => null, 'sample' => 1250, 'accept' => 21, 'reject' => 22],
    ];

    /**
     * @param  list<array{min: int, max: int|null, sample: int, accept: int, reject: int}>|null  $plan
     * @return array{sample_size: int, accept_number: int, reject_number: int}
     */
    public function resolve(int $lotSize, ?array $plan = null): array
    {
        if ($lotSize < 1) {
            throw new InvalidArgumentException('Lot size must be at least 1 (BR-30).');
        }

        foreach ($plan ?? self::DEFAULT_PLAN as $row) {
            if ($lotSize >= $row['min'] && ($row['max'] === null || $lotSize <= $row['max'])) {
                return [
                    'sample_size' => min($row['sample'], $lotSize),
                    'accept_number' => $row['accept'],
                    'reject_number' => $row['reject'],
                ];
            }
        }

        // Below the smallest band (lot < 51) ISO 2859-1 inspects the whole lot.
        return ['sample_size' => $lotSize, 'accept_number' => 0, 'reject_number' => 1];
    }

    /**
     * BR-30 — the verdict.
     *
     * A single critical defect rejects the lot regardless of the plan: a wrong care symbol on
     * a garment label is a recall, not a statistic.
     *
     * @param  array{sample_size: int, accept_number: int, reject_number: int}  $plan
     */
    public function verdict(array $plan, int $majorDefects, int $criticalDefects = 0): string
    {
        if ($criticalDefects >= 1) {
            return 'rejected';
        }

        return $majorDefects >= $plan['reject_number'] ? 'rejected' : 'accepted';
    }

    /** BR-31 — defects per hundred units. */
    public function dhu(int $totalDefects, int $unitsInspected): float
    {
        if ($unitsInspected < 1) {
            throw new InvalidArgumentException('DHU needs at least one inspected unit (BR-31).');
        }

        return round($totalDefects / $unitsInspected * 100, 4);
    }

    /**
     * Convenience for the inspection screen: plan and verdict in one call.
     *
     * @param  list<array{min: int, max: int|null, sample: int, accept: int, reject: int}>|null  $plan
     * @return array{sample_size: int, accept_number: int, reject_number: int, result: string, dhu: float}
     */
    public function inspect(int $lotSize, int $majorDefects, int $criticalDefects = 0, ?array $plan = null): array
    {
        $resolved = $this->resolve($lotSize, $plan);

        return [
            ...$resolved,
            'result' => $this->verdict($resolved, $majorDefects, $criticalDefects),
            'dhu' => $this->dhu($majorDefects + $criticalDefects, $resolved['sample_size']),
        ];
    }
}
