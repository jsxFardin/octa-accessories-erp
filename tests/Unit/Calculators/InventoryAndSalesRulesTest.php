<?php

declare(strict_types=1);

use App\Support\Calculators\InventoryValuator;
use App\Support\Calculators\SalesToleranceCalculator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->inventory = new InventoryValuator;
    $this->sales = new SalesToleranceCalculator;
});

it('br3: converts using the lot attribute first, then the item row, then the global factor', function (): void {
    // This specific ribbon roll is 2,000 m; the item's generic roll is 1,000 m.
    expect($this->inventory->convert(1, 2000.0, 1000.0, 1.0, 'roll', 'metre'))->toBeQty(2000.0)
        ->and($this->inventory->convert(1, null, 1000.0, 1.0, 'roll', 'metre'))->toBeQty(1000.0)
        ->and($this->inventory->convert(1, null, null, 1000.0, 'kg', 'g'))->toBeQty(1000.0);
});

it('br3: fails loudly rather than assuming 1:1', function (): void {
    expect(fn () => $this->inventory->convert(1, null, null, null, 'cone', 'kg'))
        ->toThrow(InvalidArgumentException::class, 'No conversion from [cone] to [kg]');
});

it('br36: recomputes the weighted average on receipt', function (): void {
    // 100 kg at 1,400 plus 100 kg at 1,600 averages 1,500.
    expect($this->inventory->newAverage(100, 1400, 100, 1600))->toBeQty(1500.0);
});

it('br36: the first receipt of an item takes the received rate as its average', function (): void {
    expect($this->inventory->newAverage(0, 0, 250, 1450))->toBeQty(1450.0);
});

it('br36: apportions landed cost by value, not by quantity', function (): void {
    $additions = $this->inventory->apportionLandedCost(
        [['line_value' => 75_000.0], ['line_value' => 25_000.0]],
        freight: 8_000, duty: 1_500, clearing: 500,
    );

    expect($additions[0])->toBeMoney(7_500.0)
        ->and($additions[1])->toBeMoney(2_500.0)
        ->and(array_sum($additions))->toBeMoney(10_000.0);
});

it('br37: suggests lots fifo by receipt date', function (): void {
    $lots = [
        ['id' => 2, 'received_at' => '2026-05-01', 'balance_qty' => 100.0, 'shade_code' => 'B', 'claim_pct' => 0.0],
        ['id' => 1, 'received_at' => '2026-01-01', 'balance_qty' => 100.0, 'shade_code' => 'A', 'claim_pct' => 0.0],
    ];

    $picked = $this->inventory->suggestLots($lots, 150);

    expect($picked[0]['id'])->toBe(1)
        ->and($picked[0]['qty'])->toBeQty(100.0)
        ->and($picked[1]['qty'])->toBeQty(50.0);
});

it('br37: a shade-critical item prefers the matching shade batch and records that it broke fifo', function (): void {
    $lots = [
        ['id' => 1, 'received_at' => '2026-01-01', 'balance_qty' => 100.0, 'shade_code' => 'A', 'claim_pct' => 0.0],
        ['id' => 2, 'received_at' => '2026-05-01', 'balance_qty' => 100.0, 'shade_code' => 'B', 'claim_pct' => 0.0],
    ];

    $picked = $this->inventory->suggestLots($lots, 50, isShadeCritical: true, preferredShade: 'B');

    expect($picked[0]['id'])->toBe(2)
        ->and($picked[0]['breaks_fifo'])->toBeTrue();
});

it('br37: certified production may only draw lots carrying the claim', function (): void {
    $lots = [
        ['id' => 1, 'received_at' => '2026-01-01', 'balance_qty' => 100.0, 'shade_code' => 'A', 'claim_pct' => 0.0],
        ['id' => 2, 'received_at' => '2026-05-01', 'balance_qty' => 100.0, 'shade_code' => 'A', 'claim_pct' => 100.0],
    ];

    $picked = $this->inventory->suggestLots($lots, 50, requiredClaimPct: 100.0);

    expect($picked)->toHaveCount(1)
        ->and($picked[0]['id'])->toBe(2);
});

it('br38: rejects an issue that would drive a lot balance negative', function (): void {
    expect($this->inventory->wouldGoNegative(balanceQty: 100.0, issueQty: 100.000001))->toBeFalse()
        ->and($this->inventory->wouldGoNegative(balanceQty: 100.0, issueQty: 100.1))->toBeTrue();
});

it('br39: buckets stock by age from the receipt date', function (): void {
    $asOf = CarbonImmutable::parse('2026-08-09');

    expect($this->inventory->ageingBucket(CarbonImmutable::parse('2026-08-01'), $asOf))->toBe('0-30')
        ->and($this->inventory->ageingBucket(CarbonImmutable::parse('2026-06-01'), $asOf))->toBe('61-90')
        ->and($this->inventory->ageingBucket(CarbonImmutable::parse('2024-01-01'), $asOf))->toBe('365+');
});

it('br39: warns thirty days before an ink or chemical expires, not on the day', function (): void {
    $asOf = CarbonImmutable::parse('2026-08-09');

    expect($this->inventory->expiryAlert(CarbonImmutable::parse('2026-08-20'), $asOf))->toBe('expiring_soon')
        ->and($this->inventory->expiryAlert(CarbonImmutable::parse('2026-08-01'), $asOf))->toBe('expired')
        ->and($this->inventory->expiryAlert(CarbonImmutable::parse('2026-12-01'), $asOf))->toBeNull()
        ->and($this->inventory->expiryAlert(null, $asOf))->toBeNull();
});

it('br44: computes the acceptable delivery band around the ordered quantity', function (): void {
    $band = $this->sales->band(50_000);

    expect($band['min'])->toBeQty(47_500.0)
        ->and($band['max'])->toBeQty(52_500.0);
});

it('br44: names the direction when a shipment falls outside the band', function (): void {
    expect($this->sales->check(46_000, 50_000)['direction'])->toBe('under')
        ->and($this->sales->check(53_000, 50_000)['direction'])->toBe('over')
        ->and($this->sales->check(49_000, 50_000)['within'])->toBeTrue();
});

it('br45: closes a line once delivery reaches the bottom of the tolerance band', function (): void {
    expect($this->sales->isClosable(47_500, 50_000))->toBeTrue()
        ->and($this->sales->isClosable(47_499, 50_000))->toBeFalse();
});

it('br46: holds an order that would take the customer past its credit limit', function (): void {
    $check = $this->sales->creditCheck(outstanding: 800_000, orderValue: 300_000, creditLimit: 1_000_000);

    expect($check['on_hold'])->toBeTrue()
        ->and($check['excess'])->toBeMoney(100_000.0);
});

it('br46: a customer with no credit limit set is not treated as instantly over limit', function (): void {
    expect($this->sales->creditCheck(800_000, 300_000, 0.0)['on_hold'])->toBeFalse();
});
