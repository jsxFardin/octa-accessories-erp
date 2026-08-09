<?php

declare(strict_types=1);

namespace App\Support\Calculators;

/**
 * BR-44 … BR-46 — delivery tolerance, order closure and credit control.
 */
class SalesToleranceCalculator
{
    /**
     * BR-44 — the acceptable delivery band around the ordered quantity.
     *
     * @return array{min: float, max: float, under_pct: float, over_pct: float}
     */
    public function band(float $orderedQty, float $underTolerancePct = 5.0, float $overTolerancePct = 5.0): array
    {
        return [
            'min' => round($orderedQty * (1 - $underTolerancePct / 100), 6),
            'max' => round($orderedQty * (1 + $overTolerancePct / 100), 6),
            'under_pct' => $underTolerancePct,
            'over_pct' => $overTolerancePct,
        ];
    }

    /**
     * BR-44 — shipping outside the band is possible, but only with an override and a reason.
     *
     * @return array{within: bool, direction: 'under'|'over'|'within', variance_pct: float}
     */
    public function check(
        float $deliveredQty,
        float $orderedQty,
        float $underTolerancePct = 5.0,
        float $overTolerancePct = 5.0,
    ): array {
        $band = $this->band($orderedQty, $underTolerancePct, $overTolerancePct);

        $direction = match (true) {
            $deliveredQty < $band['min'] => 'under',
            $deliveredQty > $band['max'] => 'over',
            default => 'within',
        };

        return [
            'within' => $direction === 'within',
            'direction' => $direction,
            'variance_pct' => $orderedQty > 0
                ? round(($deliveredQty - $orderedQty) / $orderedQty * 100, 4)
                : 0.0,
        ];
    }

    /**
     * BR-45 — a line closes once cumulative delivery reaches the bottom of the band. Waiting
     * for the exact ordered quantity would leave every order permanently open by 1%.
     */
    public function isClosable(float $deliveredQty, float $orderedQty, float $underTolerancePct = 5.0): bool
    {
        return $deliveredQty >= $this->band($orderedQty, $underTolerancePct)['min'];
    }

    /**
     * BR-46 — credit control on confirmation.
     *
     * A zero credit limit means "no limit set", not "no credit": treating an unconfigured
     * customer as instantly over-limit would hold every new account's first order.
     *
     * @return array{on_hold: bool, exposure: float, credit_limit: float, excess: float}
     */
    public function creditCheck(float $outstanding, float $orderValue, float $creditLimit): array
    {
        $exposure = $outstanding + $orderValue;
        $limitSet = $creditLimit > 0;

        return [
            'on_hold' => $limitSet && $exposure > $creditLimit,
            'exposure' => round($exposure, 4),
            'credit_limit' => round($creditLimit, 4),
            'excess' => $limitSet ? round(max(0.0, $exposure - $creditLimit), 4) : 0.0,
        ];
    }
}
