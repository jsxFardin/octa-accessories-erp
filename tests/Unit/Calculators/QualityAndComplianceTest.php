<?php

declare(strict_types=1);

use App\Support\Calculators\AqlResolver;
use App\Support\Calculators\ClaimDilutionCalculator;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->aql = new AqlResolver;
    $this->coc = new ClaimDilutionCalculator;
});

it('br30: resolves the iso 2859-1 level ii sampling plan for a lot size', function (): void {
    expect($this->aql->resolve(50_000))->toBe(['sample_size' => 500, 'accept_number' => 21, 'reject_number' => 22])
        ->and($this->aql->resolve(1_000))->toBe(['sample_size' => 80, 'accept_number' => 5, 'reject_number' => 6])
        ->and($this->aql->resolve(600_000))->toBe(['sample_size' => 1250, 'accept_number' => 21, 'reject_number' => 22]);
});

it('br30: inspects the whole lot when it is smaller than the smallest band', function (): void {
    expect($this->aql->resolve(20))->toBe(['sample_size' => 20, 'accept_number' => 0, 'reject_number' => 1]);
});

it('br30: accepts below the reject number and rejects at it', function (): void {
    $plan = $this->aql->resolve(50_000);

    expect($this->aql->verdict($plan, 21))->toBe('accepted')
        ->and($this->aql->verdict($plan, 22))->toBe('rejected');
});

it('br30: a single critical defect rejects the lot regardless of the plan', function (): void {
    $plan = $this->aql->resolve(50_000);

    expect($this->aql->verdict($plan, majorDefects: 0, criticalDefects: 1))->toBe('rejected');
});

it('br30: the verdict is computed, not typed — one call gives plan and result', function (): void {
    $result = $this->aql->inspect(lotSize: 50_000, majorDefects: 25);

    expect($result['sample_size'])->toBe(500)
        ->and($result['result'])->toBe('rejected');
});

it('br30: an alternative seeded plan overrides the house default', function (): void {
    // A demanding brand on AQL 1.5 — a data change, not a code change.
    $stricter = [['min' => 1, 'max' => null, 'sample' => 500, 'accept' => 7, 'reject' => 8]];

    expect($this->aql->verdict($this->aql->resolve(50_000, $stricter), 8))->toBe('rejected')
        ->and($this->aql->verdict($this->aql->resolve(50_000), 8))->toBe('accepted');
});

it('br31: dhu is defects per hundred units inspected', function (): void {
    expect($this->aql->dhu(totalDefects: 25, unitsInspected: 500))->toBe(5.0);
});

it('br40: dilutes an output claim by the weighted average of its inputs', function (): void {
    $claim = $this->coc->dilutedClaimPct([
        ['qty_consumed' => 60.0, 'claim_pct' => 100.0],
        ['qty_consumed' => 40.0, 'claim_pct' => 0.0],
    ]);

    expect($claim)->toBe(60.0);
});

it('br40: rounds a diluted claim down, never up', function (): void {
    // 19.6% must not become 20% — that would manufacture a GRS claim from nothing.
    $claim = $this->coc->dilutedClaimPct([
        ['qty_consumed' => 19.6, 'claim_pct' => 100.0],
        ['qty_consumed' => 80.4, 'claim_pct' => 0.0],
    ]);

    expect($claim)->toBe(19.0);
});

it('br40: input with no certified content yields no claim', function (): void {
    expect($this->coc->dilutedClaimPct([['qty_consumed' => 100.0, 'claim_pct' => 0.0]]))->toBe(0.0)
        ->and($this->coc->dilutedClaimPct([]))->toBe(0.0);
});

it('br41: allows the claim only at or above the scheme threshold', function (): void {
    expect($this->coc->meetsThreshold(20.0, 20.0)['allowed'])->toBeTrue()
        ->and($this->coc->meetsThreshold(19.0, 20.0)['allowed'])->toBeFalse()
        ->and($this->coc->meetsThreshold(19.0, 20.0)['shortfall_pct'])->toBe(1.0);
});

it('br41: the labelled claim threshold is higher than the plain claim threshold', function (): void {
    // Both thresholds are data (certification_scopes), so the same rule serves both.
    expect($this->coc->meetsThreshold(35.0, 20.0)['allowed'])->toBeTrue()
        ->and($this->coc->meetsThreshold(35.0, 50.0)['allowed'])->toBeFalse();
});

it('br42: reconciles certified output against certified input per scheme and period', function (): void {
    $result = $this->coc->reconcile(certifiedInputQty: 1000, certifiedOutputQty: 900);

    expect($result['conversion_factor'])->toBe(0.9)
        ->and($result['flagged'])->toBeFalse();
});

it('br42: flags the exact condition an auditor tests — more certified out than in', function (): void {
    $result = $this->coc->reconcile(certifiedInputQty: 1000, certifiedOutputQty: 1100);

    expect($result['flagged'])->toBeTrue()
        ->and($result['conversion_factor'])->toBe(1.1);
});

it('br43: blocks a claim whose certificate has expired on the shipment date', function (): void {
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-06-30');

    expect($this->coc->certificateValidOn(CarbonImmutable::parse('2026-06-30'), $from, $to))->toBeTrue()
        ->and($this->coc->certificateValidOn(CarbonImmutable::parse('2026-07-01'), $from, $to))->toBeFalse();
});

it('computes the certified portion of an output quantity for the coc ledger', function (): void {
    expect($this->coc->certifiedQty(10_000, 35.0))->toBeQty(3_500.0)
        ->and(fn () => $this->coc->certifiedQty(10_000, 120.0))->toThrow(InvalidArgumentException::class);
});
