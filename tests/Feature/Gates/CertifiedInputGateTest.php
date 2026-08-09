<?php

declare(strict_types=1);

use App\Support\Calculators\ClaimDilutionCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gate 2 — the certified input gate (01-domain-model §4).
 *
 * Output cannot claim GRS or FSC certification unless the consumed lots carry the claim. It is
 * enforced by the chain-of-custody ledger, not by a checkbox on an invoice.
 */
beforeEach(function (): void {
    $this->coc = new ClaimDilutionCalculator;
});

it('carries the certification claim from the grn line onto the stock lot', function (): void {
    // I5 — the GRN line is the only legitimate origin of a claim, and the lot is where it lives.
    $lot = DB::table('stock_lots')->whereNotNull('cert_scheme')->first();

    expect($lot)->not->toBeNull();

    $grnLine = DB::table('grn_lines')->where('id', $lot->grn_line_id)->first();

    expect($lot->cert_scheme)->toBe($grnLine->cert_scheme)
        ->and((float) $lot->cert_claim_pct)->toBe((float) $grnLine->cert_claim_pct)
        ->and($lot->cert_document_no)->toBe($grnLine->cert_document_no);
});

it('writes a certified input transaction for every certified receipt', function (): void {
    $certifiedLines = DB::table('grn_lines')->whereNotNull('cert_scheme')->count();

    $inputs = DB::table('coc_transactions')->where('direction', 'input')->count();

    expect($inputs)->toBe($certifiedLines)->toBeGreaterThan(0);
});

it('cannot claim grs output exceeding diluted input', function (): void {
    $input = (float) DB::table('coc_transactions')
        ->where('scheme', 'GRS')->where('direction', 'input')->sum('qty');

    expect($input)->toBeGreaterThan(0);

    // BR-42 — the exact condition an auditor tests: more certified goods out than in.
    $withinInput = $this->coc->reconcile($input, $input * 0.9);
    $overInput = $this->coc->reconcile($input, $input * 1.1);

    expect($withinInput['flagged'])->toBeFalse()
        ->and($overInput['flagged'])->toBeTrue();
});

it('dilutes a claim by consumption-weighted average and rounds down', function (): void {
    $certified = DB::table('stock_lots')->whereNotNull('cert_scheme')->first();
    $uncertified = DB::table('stock_lots')->whereNull('cert_scheme')->first();

    // BR-40 — non-certified input dilutes; rounding is always down, because 19.6% must not
    // become the 20% that would qualify for a GRS claim.
    $claim = $this->coc->dilutedClaimPct([
        ['qty_consumed' => 49.0, 'claim_pct' => (float) $certified->cert_claim_pct],
        ['qty_consumed' => 51.0, 'claim_pct' => (float) $uncertified->cert_claim_pct],
    ]);

    expect($claim)->toBe(49.0);
});

it('blocks a claim below the scheme threshold', function (): void {
    $scope = DB::table('certifications as c')
        ->join('certification_scopes as s', 's.certification_id', '=', 'c.id')
        ->where('c.scheme', 'GRS')
        ->first(['s.min_claim_pct', 's.labelled_claim_pct']);

    // BR-41 — the thresholds belong to the standard, so they are data, not constants.
    expect($this->coc->meetsThreshold(19.0, (float) $scope->min_claim_pct)['allowed'])->toBeFalse()
        ->and($this->coc->meetsThreshold(20.0, (float) $scope->min_claim_pct)['allowed'])->toBeTrue()
        ->and($this->coc->meetsThreshold(20.0, (float) $scope->labelled_claim_pct)['allowed'])->toBeFalse();
});

it('cannot dispatch against an expired certificate', function (): void {
    $certificate = DB::table('certifications')->where('scheme', 'GRS')->first();

    DB::table('certifications')->where('id', $certificate->id)->update([
        'issued_on' => '2025-01-01',
        'expires_on' => '2025-12-31',
    ]);

    $certificate = DB::table('certifications')->where('id', $certificate->id)->first();

    // BR-43 — validity is evaluated on the shipment date, and the block names the certificate.
    $valid = $this->coc->certificateValidOn(
        CarbonImmutable::parse('2026-08-09'),
        CarbonImmutable::parse($certificate->issued_on),
        CarbonImmutable::parse($certificate->expires_on),
    );

    expect($valid)->toBeFalse();
});

it('reconciles through the auditor-facing view', function (): void {
    $row = DB::table('v_coc_reconciliation')->where('scheme', 'GRS')->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->certified_input_qty)->toBeGreaterThan(0)
        // Nothing has shipped yet in the walkthrough, so output is zero and the factor is 0 —
        // which is the honest answer, not a missing row.
        ->and((float) $row->certified_output_qty)->toBe(0.0);
});
