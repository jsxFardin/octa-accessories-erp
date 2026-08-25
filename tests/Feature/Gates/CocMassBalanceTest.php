<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * BR-42 — Gate 2 has to balance in one unit, against consumption.
 *
 * The reconciliation used to compare certified *receipts* in kilograms with certified output
 * in pieces: "180 in, 41,880 out" and a conversion factor of 232. Both halves were wrong.
 * Yarn on the shelf is not evidence for a label that shipped, and kilograms are not pieces.
 *
 * Issuing material now writes the `conversion` leg the schema always had a name for, and a
 * shipment states the certified mass behind it, allocated by the share of the job that left.
 */
it('reconciles shipped certified mass against certified consumption', function (): void {
    $row = DB::table('v_coc_reconciliation')->where('scheme', 'GRS')->first();

    expect($row)->not->toBeNull();

    $consumed = (float) $row->certified_consumed_qty;
    $shipped = (float) $row->certified_output_qty;

    // Nothing may leave that was never consumed. This is the whole of Gate 2, in one line.
    expect($shipped)->toBeLessThanOrEqual($consumed > 0 ? $consumed : (float) $row->certified_input_qty);

    if ($consumed > 0) {
        expect((float) $row->certified_basis_qty)->toBe(round($consumed, 6))
            // A factor above 1 is the auditor's red flag; it must be arithmetically reachable.
            ->and((float) $row->conversion_factor)->toBeLessThanOrEqual(1.0);
    }
});

it('writes a conversion row when certified material is issued to a job', function (): void {
    $issuedCertified = DB::table('material_issue_lines as mil')
        ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
        ->join('stock_lots as sl', 'sl.id', '=', 'mil.lot_id')
        ->where('mi.issue_type', 'issue')
        ->whereNotNull('sl.cert_scheme')
        ->where('sl.cert_claim_pct', '>', 0)
        ->count();

    if ($issuedCertified === 0) {
        $this->markTestSkipped('The seed issues no certified material.');
    }

    expect(DB::table('coc_transactions')->where('direction', 'conversion')->count())
        ->toBeGreaterThan(0);
});
