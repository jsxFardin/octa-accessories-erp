<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * The other-cost components of a sheet, priced (spec §4). A lump sum or an amount per unit
 * is a cost like any other; a percentage of the price is resolved once the price is known:
 *
 *   price = c / (1 − margin − Σpct)
 *
 * where c is every cost before the percentage ones. The margin stays a share of the price
 * (BR-20) and each percentage component takes its own share, so total cost + margin = price.
 */
final class ExtraCosts
{
    /**
     * @param  list<array{key: string, label: string, cost_type: string, basis: string, value: float}>  $components
     * @return array{fixed: list<CostLine>, fixedTotal: float, pctSum: float, pct: list<array{key: string, label: string, cost_type: string, value: float}>}
     */
    public static function split(array $components, float $orderQty, int $seq): array
    {
        $fixed = [];
        $fixedTotal = 0.0;
        $pct = [];
        $pctSum = 0.0;

        foreach ($components as $component) {
            $value = (float) $component['value'];

            switch ($component['basis']) {
                case 'pct_of_price':
                    $pct[] = $component;
                    $pctSum += $value;
                    break;
                case 'per_unit':
                    $amount = round($value * $orderQty, 4);
                    $fixed[] = new CostLine($seq++, $component['cost_type'], 'unit', $orderQty, $value, $amount, 'CS-O', $component['label']);
                    $fixedTotal += $amount;
                    break;
                default:
                    $fixed[] = new CostLine($seq++, $component['cost_type'], 'job', 1, $value, round($value, 4), 'CS-O', $component['label']);
                    $fixedTotal += $value;
            }
        }

        return ['fixed' => $fixed, 'fixedTotal' => $fixedTotal, 'pctSum' => $pctSum, 'pct' => $pct];
    }

    /**
     * The percentage components as lines, once the price is known.
     *
     * @param  list<array{key: string, label: string, cost_type: string, value: float}>  $pct
     * @return array{lines: list<CostLine>, total: float}
     */
    public static function priced(array $pct, float $sellingValue, int $seq): array
    {
        $lines = [];
        $total = 0.0;

        foreach ($pct as $component) {
            $amount = round($sellingValue * (float) $component['value'] / 100, 4);
            $lines[] = new CostLine($seq++, $component['cost_type'], '%', (float) $component['value'], round($sellingValue, 4), $amount, 'CS-O', $component['label']);
            $total += $amount;
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /** price = c / (1 − margin − Σpct); refused when nothing would be left. */
    public static function price(float $costBeforePct, float $marginPct, float $pctSum): float
    {
        $share = 1 - ($marginPct + $pctSum) / 100;

        if ($share <= 0) {
            throw new \InvalidArgumentException('Margin and percentage components add up to 100% or more of the price; no price can carry them (BR-20).');
        }

        return $costBeforePct / $share;
    }
}
