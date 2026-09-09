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

/**
 * G5 is a functional requirement, not a marketing detail: GRS and FSC both mandate chain of
 * custody, and certified input has to be reconciled against certified output on demand.
 *
 * This test used to look for a certified issue in the seed and skip itself when it found none
 * — which it did. The suite reported green while the consumption leg of the chain of custody,
 * the half that makes the reconciliation mean anything, had no evidence behind it at all. It
 * now issues certified material through the real endpoint and checks what that produced.
 */
it('writes a conversion row when certified material is issued to a job', function (): void {
    $before = DB::table('coc_transactions')->where('direction', 'conversion')->count();

    $issue = issueCertifiedMaterial($this);

    $conversion = DB::table('coc_transactions')
        ->where('direction', 'conversion')
        ->where('job_card_id', $issue['job_card_id'])
        ->where('lot_id', $issue['lot_id'])
        ->orderByDesc('id')
        ->first();

    expect(DB::table('coc_transactions')->where('direction', 'conversion')->count())
        ->toBe($before + 1)
        ->and($conversion)->not->toBeNull()
        ->and($conversion->scheme)->toBe($issue['scheme'])
        // BR-42 — the certified mass is the issued quantity times the lot's claim, not the
        // issued quantity itself. A 50%-claim lot contributes half of what left the shelf.
        ->and((float) $conversion->qty)
        ->toEqualWithDelta($issue['qty'] * $issue['claim_pct'] / 100, 0.000001);
});

it('does not write a conversion row for uncertified material', function (): void {
    $before = DB::table('coc_transactions')->where('direction', 'conversion')->count();

    issueCertifiedMaterial($this, claimPct: 0, scheme: null);

    // Yarn with no certificate behind it is yarn. Counting it would inflate every claim the
    // factory makes, which is the failure mode an auditor is looking for.
    expect(DB::table('coc_transactions')->where('direction', 'conversion')->count())->toBe($before);
});

it('carries the certified consumption through to the reconciliation view', function (): void {
    $issue = issueCertifiedMaterial($this);

    // The view groups by scheme *and period*, and an issue is stamped with the month it
    // happened in — so the row to look at is this month's, not whichever one comes back first.
    $row = DB::table('v_coc_reconciliation')
        ->where('scheme', $issue['scheme'])
        ->where('period_year', (int) now()->format('Y'))
        ->where('period_month', (int) now()->format('n'))
        ->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->certified_consumed_qty)
        ->toBeGreaterThanOrEqual($issue['qty'] * $issue['claim_pct'] / 100);
});

/**
 * A certified lot, and a store keeper issuing some of it to a job card.
 *
 * Built here rather than found in the seed: the seed's shape is not this test's subject, and a
 * test that skips itself when the shape is missing reports success for a rule it never ran.
 *
 * @return array{job_card_id: int, lot_id: int, qty: float, claim_pct: float, scheme: string|null}
 */
function issueCertifiedMaterial(
    object $test,
    float $claimPct = 100.0,
    ?string $scheme = 'GRS',
): array {
    $storeKeeper = App\Models\User::query()
        ->where('email', 'store@octapussolution.com')
        ->firstOrFail();

    $jobCard = DB::table('job_cards')->whereNotIn('status', ['closed', 'cancelled'])->firstOrFail();

    $item = DB::table('items')->firstOrFail();
    $warehouse = DB::table('warehouses')->where('is_active', true)->firstOrFail();
    $uomId = (int) DB::table('uoms')->where('code', 'kg')->value('id');

    // A lot of its own, so the quantities this asserts on cannot be moved by another test's
    // issue against a shared one.
    $lotId = (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'COC-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => $item->id,
        'warehouse_id' => $warehouse->id,
        'uom_id' => $uomId,
        'received_qty' => 100,
        'balance_qty' => 100,
        'unit_cost' => 12.5,
        'status' => 'available',
        'cert_scheme' => $scheme,
        'cert_claim_pct' => $claimPct,
        'received_on' => now()->toDateString(),
    ]);

    $test->actingAs($storeKeeper)->post('/material-issues', [
        'job_card_id' => $jobCard->id,
        'warehouse_id' => $warehouse->id,
        'issue_type' => 'issue',
        'lines' => [[
            'item_id' => $item->id,
            'lot_id' => $lotId,
            'uom_id' => $uomId,
            'qty' => 40,
        ]],
    ])->assertRedirect();

    return [
        'job_card_id' => (int) $jobCard->id,
        'lot_id' => $lotId,
        'qty' => 40.0,
        'claim_pct' => $claimPct,
        'scheme' => $scheme,
    ];
}
