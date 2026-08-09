<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * BR-40 … BR-43 — chain of custody arithmetic (Gate 2).
 *
 * GRS and FSC both require certified input to be reconciled against certified output. Most
 * competing systems cannot produce that reconciliation; here it is a report rather than a
 * month of spreadsheet archaeology (README §3).
 *
 * The direction of rounding matters legally: a diluted claim always rounds **down**. Rounding
 * 19.6% up to 20% would manufacture a GRS claim the material does not support.
 */
class ClaimDilutionCalculator
{
    /**
     * BR-40 — an output lot's claim is the consumption-weighted average of its inputs.
     * Non-certified input dilutes; it does not simply reduce the denominator.
     *
     * @param  list<array{qty_consumed: float, claim_pct: float}>  $inputs
     */
    public function dilutedClaimPct(array $inputs): float
    {
        $totalQty = array_sum(array_map(fn (array $in): float => $in['qty_consumed'], $inputs));

        if ($totalQty <= 0) {
            return 0.0;
        }

        $weighted = array_sum(array_map(
            fn (array $in): float => $in['qty_consumed'] * $in['claim_pct'],
            $inputs,
        ));

        // Round down to the nearest whole percent, never up (BR-40).
        return floor($weighted / $totalQty);
    }

    /**
     * BR-41 — may this output be sold under the scheme's claim?
     *
     * The threshold is per scheme and lives in `certification_scopes`, not in code: GRS uses
     * 20% for the "GRS" claim and 50% for the labelled claim, and those numbers belong to the
     * standard, which revises on its own schedule.
     *
     * @return array{allowed: bool, claim_pct: float, threshold_pct: float, shortfall_pct: float}
     */
    public function meetsThreshold(float $claimPct, float $thresholdPct): array
    {
        return [
            'allowed' => $claimPct >= $thresholdPct,
            'claim_pct' => $claimPct,
            'threshold_pct' => $thresholdPct,
            'shortfall_pct' => max(0.0, round($thresholdPct - $claimPct, 4)),
        ];
    }

    /**
     * BR-42 — the figure a GRS or FSC auditor asks for: certified output over certified input
     * for the period, and whether it exceeds the scheme's maximum conversion factor.
     *
     * @return array{certified_input_qty: float, certified_output_qty: float, conversion_factor: float, max_conversion_factor: float, flagged: bool}
     */
    public function reconcile(
        float $certifiedInputQty,
        float $certifiedOutputQty,
        float $maxConversionFactor = 1.0,
    ): array {
        $factor = $certifiedInputQty > 0 ? $certifiedOutputQty / $certifiedInputQty : 0.0;

        return [
            'certified_input_qty' => round($certifiedInputQty, 6),
            'certified_output_qty' => round($certifiedOutputQty, 6),
            'conversion_factor' => round($factor, 6),
            'max_conversion_factor' => $maxConversionFactor,
            // Exactly the condition the auditor tests: more certified goods left than came in.
            'flagged' => $certifiedOutputQty > $certifiedInputQty * $maxConversionFactor,
        ];
    }

    /**
     * BR-43 — a shipment cannot claim a scheme whose certificate has expired on the shipment
     * date. Blocked, with the expired certificate named — "compliance error" helps nobody.
     */
    public function certificateValidOn(
        DateTimeInterface $shipmentDate,
        DateTimeInterface $validFrom,
        DateTimeInterface $validTo,
    ): bool {
        $date = $shipmentDate->format('Y-m-d');

        return $date >= $validFrom->format('Y-m-d') && $date <= $validTo->format('Y-m-d');
    }

    /**
     * The certified portion of an output quantity, for writing the CoC `output` transaction.
     */
    public function certifiedQty(float $outputQty, float $claimPct): float
    {
        if ($claimPct < 0 || $claimPct > 100) {
            throw new InvalidArgumentException('A certification claim is a percentage between 0 and 100.');
        }

        return round($outputQty * $claimPct / 100, 6);
    }
}
