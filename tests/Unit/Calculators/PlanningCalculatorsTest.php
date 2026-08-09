<?php

declare(strict_types=1);

use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\MrpCalculator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->mrp = new MrpCalculator;
    $this->capacity = new CapacityCalculator;
});

it('br24: nets gross requirement against available stock and open orders', function (): void {
    $result = $this->mrp->netRequirement(grossReq: 1000, onHandNettable: 400, onOrder: 200, reserved: 100);

    // available = 400 − 100 reserved = 300; 1000 − 300 − 200 = 500 short
    expect($result['available'])->toBeQty(300.0)
        ->and($result['net_req'])->toBeQty(500.0)
        ->and($result['has_shortage'])->toBeTrue();
});

it('br24: reports no shortage and never a negative requirement when stock covers demand', function (): void {
    $result = $this->mrp->netRequirement(grossReq: 100, onHandNettable: 500, onOrder: 0, reserved: 0);

    expect($result['has_shortage'])->toBeFalse()
        ->and($result['net_req'])->toBe(0.0);
});

it('br24: stock reserved for another job card is not available to this one', function (): void {
    $unreserved = $this->mrp->netRequirement(1000, 1000, 0, 0);
    $reserved = $this->mrp->netRequirement(1000, 1000, 0, 600);

    expect($unreserved['has_shortage'])->toBeFalse()
        ->and($reserved['net_req'])->toBeQty(600.0);
});

it('br25: raises a purchase quantity to the minimum and rounds to the pack multiple', function (): void {
    expect($this->mrp->suggestedPurchaseQty(netReq: 12.0, minOrderQty: 50.0, orderMultiple: 25.0))->toBeQty(50.0)
        ->and($this->mrp->suggestedPurchaseQty(netReq: 51.0, minOrderQty: 50.0, orderMultiple: 25.0))->toBeQty(75.0)
        ->and($this->mrp->suggestedPurchaseQty(netReq: 51.0))->toBeQty(51.0);
});

it('br26: backs the material need date off by the safety days', function (): void {
    $start = CarbonImmutable::parse('2026-09-01');

    expect($this->mrp->materialNeedDate($start, 3)->toDateString())->toBe('2026-08-29');
});

it('br26: backs the place-by date off by the supplier lead time', function (): void {
    // Yarn from the UK: 45 days on the water before it is needed on the loom.
    $need = CarbonImmutable::parse('2026-08-29');

    expect($this->mrp->placeByDate($need, 45)->toDateString())->toBe('2026-07-15');
});

it('br26: calls a requirement late once its place-by date has passed', function (): void {
    $today = CarbonImmutable::parse('2026-08-09');

    expect($this->mrp->isLate(CarbonImmutable::parse('2026-07-15'), $today))->toBeTrue()
        ->and($this->mrp->isLate(CarbonImmutable::parse('2026-09-15'), $today))->toBeFalse();
});

it('br29: promises a date allowing for qc, packing and transit', function (): void {
    $finish = CarbonImmutable::parse('2026-09-10');

    expect($this->mrp->promisedDate($finish, transitDays: 2)->toDateString())->toBe('2026-09-14');
});

it('br28: splits one job card per dated shipment', function (): void {
    $cards = $this->mrp->splitIntoJobCards(50_000, deliverySchedules: [
        ['qty' => 30_000.0, 'date' => '2026-09-15'],
        ['qty' => 20_000.0, 'date' => '2026-10-15'],
    ]);

    expect($cards)->toHaveCount(2)
        ->and($cards[0]['qty'])->toBeQty(30_000.0)
        ->and($cards[1]['due_date'])->toBe('2026-10-15')
        ->and($cards[0]['reason'])->toContain('delivery schedule');
});

it('br28: splits one job card per colourway, because a loom runs one colourway at a time', function (): void {
    $cards = $this->mrp->splitIntoJobCards(20_000, colourways: ['navy', 'white']);

    expect($cards)->toHaveCount(2)
        ->and($cards[0]['colourway'])->toBe('navy')
        ->and($cards[0]['qty'])->toBeQty(10_000.0);
});

it('br28: splits a quantity that exceeds the routing max lot size', function (): void {
    $cards = $this->mrp->splitIntoJobCards(50_000, maxLotSize: 20_000);

    expect($cards)->toHaveCount(3)
        ->and(array_sum(array_column($cards, 'qty')))->toBeQty(50_000.0)
        ->and($cards[0]['qty'])->toBeQty(20_000.0)
        ->and($cards[2]['qty'])->toBeQty(10_000.0);
});

it('br28: leaves a single ordinary line as one job card', function (): void {
    $cards = $this->mrp->splitIntoJobCards(5_000);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['reason'])->toBe('single run');
});

it('br27: discounts shift minutes by planned downtime and machine efficiency', function (): void {
    // An 8-hour shift, 10% planned downtime, 85% efficiency → 480 × 0.9 × 0.85 = 367.2
    expect($this->capacity->availableMinutes(480, 10, 85))->toBe(367.2);
});

it('br27: load minutes are run time plus setup', function (): void {
    expect($this->capacity->loadMinutes(outputUnits: 600, stdRatePerHour: 120, setupMinutes: 60))->toBe(360.0);
});

it('br27: flags a machine scheduled past its available minutes', function (): void {
    $under = $this->capacity->utilisation(300, 367.2);
    $over = $this->capacity->utilisation(400, 367.2);

    expect($under['over_capacity'])->toBeFalse()
        ->and($under['utilisation_pct'])->toBe(81.7)
        ->and($over['over_capacity'])->toBeTrue()
        ->and($over['spare_minutes'])->toBeLessThan(0);
});

it('br27: a machine with no available minutes is not silently 0% utilised', function (): void {
    $result = $this->capacity->utilisation(120, 0);

    expect($result['over_capacity'])->toBeTrue();
});
