<?php

declare(strict_types=1);

namespace App\Support\Calculators;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * BR-24 … BR-26 — gross-to-net requirement, order rounding, need and placement dates.
 *
 * On-hand counts only nettable warehouses: scrap and transit stock exists but cannot be
 * planned against (02-database-schema §3.2).
 */
class MrpCalculator
{
    /**
     * BR-24 — net requirement for one item.
     *
     * @return array{gross_req: float, available: float, net_req: float, has_shortage: bool}
     */
    public function netRequirement(
        float $grossReq,
        float $onHandNettable,
        float $onOrder,
        float $reserved,
    ): array {
        $available = $onHandNettable - $reserved;
        $netReq = $grossReq - $available - $onOrder;

        return [
            'gross_req' => round($grossReq, 6),
            'available' => round($available, 6),
            'net_req' => round(max(0.0, $netReq), 6),
            'has_shortage' => $netReq > 0,
        ];
    }

    /**
     * BR-25 — a purchase quantity respects the supplier's minimum and pack multiple.
     * Ordering 1.3 cartons of yarn is not a thing.
     */
    public function suggestedPurchaseQty(float $netReq, float $minOrderQty = 0.0, float $orderMultiple = 0.0): float
    {
        $qty = max($netReq, $minOrderQty);

        if ($orderMultiple > 0) {
            $qty = ceil($qty / $orderMultiple) * $orderMultiple;
        }

        return round($qty, 6);
    }

    /**
     * BR-26 — when the material is needed on the floor.
     */
    public function materialNeedDate(DateTimeInterface $operationStartDate, int $safetyDays = 0): CarbonImmutable
    {
        return CarbonImmutable::instance($operationStartDate)->subDays($safetyDays);
    }

    /**
     * BR-26 — the last day a PO can be placed and still arrive in time.
     *
     * Lead time is per supplier-item, not global: yarn from the UK and ribbon from Dhaka do
     * not share a calendar.
     */
    public function placeByDate(DateTimeInterface $materialNeedDate, int $supplierLeadTimeDays): CarbonImmutable
    {
        return CarbonImmutable::instance($materialNeedDate)->subDays($supplierLeadTimeDays);
    }

    /**
     * BR-26 — a requirement whose place-by date has passed is already late; the planner needs
     * that stated, not implied by a date in the past.
     */
    public function isLate(DateTimeInterface $placeByDate, ?DateTimeInterface $today = null): bool
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::now())->startOfDay();

        return CarbonImmutable::instance($placeByDate)->startOfDay()->lessThan($today);
    }

    /**
     * BR-29 — promised delivery date.
     *
     * Every open order shows a system-computed ETA (goal G3), and this is where it comes from.
     */
    public function promisedDate(
        DateTimeInterface $lastOperationFinishDate,
        int $transitDays,
        int $qcDays = 1,
        int $packingDays = 1,
    ): CarbonImmutable {
        return CarbonImmutable::instance($lastOperationFinishDate)
            ->addDays($qcDays)
            ->addDays($packingDays)
            ->addDays($transitDays);
    }

    /**
     * BR-28 — how many job cards a sales order line becomes.
     *
     * @param  list<array{qty: float, date: string|null}>  $deliverySchedules
     * @param  list<string>  $colourways
     * @return list<array{qty: float, due_date: string|null, colourway: string|null, reason: string}>
     */
    public function splitIntoJobCards(
        float $orderedQty,
        ?float $maxLotSize = null,
        array $deliverySchedules = [],
        array $colourways = [],
    ): array {
        // One job card per dated shipment, else a single delivery of the whole quantity.
        $shipments = $deliverySchedules !== []
            ? $deliverySchedules
            : [['qty' => $orderedQty, 'date' => null]];

        // One job card per colourway — a loom cannot weave two colourways in one run.
        $ways = $colourways !== [] ? $colourways : [null];

        $cards = [];

        foreach ($shipments as $shipment) {
            $perWay = $shipment['qty'] / count($ways);

            foreach ($ways as $way) {
                $remaining = $perWay;

                do {
                    $qty = $maxLotSize !== null && $maxLotSize > 0
                        ? min($remaining, $maxLotSize)
                        : $remaining;

                    $cards[] = [
                        'qty' => round($qty, 6),
                        'due_date' => $shipment['date'],
                        'colourway' => $way,
                        'reason' => $this->splitReason($deliverySchedules, $colourways, $maxLotSize, $perWay),
                    ];

                    $remaining -= $qty;
                } while ($remaining > 0.000001);
            }
        }

        return $cards;
    }

    /**
     * @param  list<array{qty: float, date: string|null}>  $deliverySchedules
     * @param  list<string>  $colourways
     */
    private function splitReason(array $deliverySchedules, array $colourways, ?float $maxLotSize, float $qty): string
    {
        $reasons = [];

        if (count($deliverySchedules) > 1) {
            $reasons[] = 'delivery schedule';
        }

        if (count($colourways) > 1) {
            $reasons[] = 'colourway';
        }

        if ($maxLotSize !== null && $maxLotSize > 0 && $qty > $maxLotSize) {
            $reasons[] = 'max lot size';
        }

        return $reasons === [] ? 'single run' : implode(' + ', $reasons);
    }
}
