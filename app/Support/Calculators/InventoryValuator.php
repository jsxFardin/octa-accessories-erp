<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * BR-36 … BR-39 — valuation, landed cost, lot selection and ageing.
 */
class InventoryValuator
{
    /**
     * BR-36 — weighted average per item per warehouse, recomputed on every receipt.
     */
    public function newAverage(float $qtyOnHand, float $oldAverage, float $receivedQty, float $receivedRate): float
    {
        $totalQty = $qtyOnHand + $receivedQty;

        if ($totalQty <= 0) {
            return round($receivedRate, 6);
        }

        return round(
            ($qtyOnHand * $oldAverage + $receivedQty * $receivedRate) / $totalQty,
            6,
        );
    }

    /**
     * BR-36 — landed cost apportioned to GRN lines **by value**, before the average moves.
     *
     * Not by weight and not by quantity: a kilo of imported UK ink and a kilo of local carton
     * board do not carry the same share of the duty bill.
     *
     * @param  list<array{line_value: float}>  $lines
     * @return list<float> the addition per line, in line order
     */
    public function apportionLandedCost(array $lines, float $freight, float $duty, float $clearing): array
    {
        $landed = $freight + $duty + $clearing;
        $totalValue = array_sum(array_map(fn (array $l): float => $l['line_value'], $lines));

        if ($landed <= 0 || $totalValue <= 0) {
            return array_fill(0, count($lines), 0.0);
        }

        return array_map(
            fn (array $l): float => round($landed * ($l['line_value'] / $totalValue), 4),
            $lines,
        );
    }

    /**
     * BR-37 — lot selection on issue.
     *
     * FIFO by receipt date, with two overrides that exist because shade variation inside one
     * customer's order is a rejection: a shade-critical item prefers lots of the same shade
     * batch even when that breaks FIFO, and certified production may only draw certified lots.
     * Breaking FIFO is allowed; breaking it silently is not — the caller records the reason.
     *
     * @param  list<array{id: int, received_at: string, balance_qty: float, shade_code: ?string, claim_pct: float}>  $lots
     * @return list<array{id: int, qty: float, breaks_fifo: bool}>
     */
    public function suggestLots(
        array $lots,
        float $requiredQty,
        bool $isShadeCritical = false,
        ?string $preferredShade = null,
        ?float $requiredClaimPct = null,
    ): array {
        $eligible = array_values(array_filter($lots, function (array $lot) use ($requiredClaimPct): bool {
            if ($lot['balance_qty'] <= 0) {
                return false;
            }

            return $requiredClaimPct === null || $lot['claim_pct'] >= $requiredClaimPct;
        }));

        usort($eligible, fn (array $a, array $b): int => strcmp($a['received_at'], $b['received_at']));

        $fifoOrder = array_column($eligible, 'id');

        if ($isShadeCritical && $preferredShade !== null) {
            usort($eligible, function (array $a, array $b) use ($preferredShade): int {
                $aMatch = $a['shade_code'] === $preferredShade ? 0 : 1;
                $bMatch = $b['shade_code'] === $preferredShade ? 0 : 1;

                return $aMatch <=> $bMatch ?: strcmp($a['received_at'], $b['received_at']);
            });
        }

        $picked = [];
        $remaining = $requiredQty;

        foreach ($eligible as $index => $lot) {
            if ($remaining <= 0.000001) {
                break;
            }

            $take = min($remaining, $lot['balance_qty']);

            $picked[] = [
                'id' => $lot['id'],
                'qty' => round($take, 6),
                'breaks_fifo' => ($fifoOrder[$index] ?? null) !== $lot['id'],
            ];

            $remaining -= $take;
        }

        return $picked;
    }

    /**
     * BR-38 — negative stock is prohibited. There is no "allow negative" setting, so this
     * returns a decision, not a warning.
     */
    public function wouldGoNegative(float $balanceQty, float $issueQty): bool
    {
        return $issueQty > $balanceQty + 0.000001;
    }

    /**
     * BR-39 — stock ageing bucket from the lot's receipt date.
     */
    public function ageingBucket(DateTimeInterface $receivedAt, ?DateTimeInterface $asOf = null): string
    {
        $days = CarbonImmutable::instance($receivedAt)
            ->startOfDay()
            ->diffInDays(CarbonImmutable::instance($asOf ?? CarbonImmutable::now())->startOfDay());

        return match (true) {
            $days <= 30 => '0-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            $days <= 180 => '91-180',
            $days <= 365 => '181-365',
            default => '365+',
        };
    }

    /**
     * BR-39 — ink and chemicals flag 30 days before expiry, not on the day they expire.
     */
    public function expiryAlert(?DateTimeInterface $expiryDate, ?DateTimeInterface $asOf = null): ?string
    {
        if ($expiryDate === null) {
            return null;
        }

        $today = CarbonImmutable::instance($asOf ?? CarbonImmutable::now())->startOfDay();
        $expiry = CarbonImmutable::instance($expiryDate)->startOfDay();

        if ($expiry->lessThan($today)) {
            return 'expired';
        }

        return $expiry->lessThanOrEqualTo($today->addDays(30)) ? 'expiring_soon' : null;
    }

    /**
     * BR-3 — UoM conversion resolution order: lot attribute, then item conversion, then a
     * global conversion. There is no fourth step: an unresolvable conversion fails loudly
     * rather than silently assuming 1:1, which is how 2,000 metres becomes 2,000 kilograms.
     */
    public function convert(
        float $qty,
        ?float $lotFactor,
        ?float $itemFactor,
        ?float $globalFactor,
        string $from,
        string $to,
    ): float {
        $factor = $lotFactor ?? $itemFactor ?? $globalFactor;

        if ($factor === null || $factor <= 0) {
            throw new InvalidArgumentException(
                "No conversion from [{$from}] to [{$to}] (BR-3). Add a uom_conversions row; "
                .'the system will not assume 1:1.',
            );
        }

        return round($qty * $factor, 6);
    }
}
