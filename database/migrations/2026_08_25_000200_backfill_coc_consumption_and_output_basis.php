<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the chain-of-custody rows written before the mass balance existed.
 *
 * Two legs were missing or wrong. There was no `conversion` leg at all, so consumption was
 * invisible; and `output` rows were counted in pieces while `input` was in kilograms, which
 * produced conversion factors in the hundreds. Both are derivable from records the system
 * already holds — material issues and FG receipts — so history is rebuilt rather than left
 * for an auditor to disbelieve.
 *
 * Conversion rows are rebuilt from scratch rather than topped up: the issues and returns they
 * derive from are the authority, and a partial top-up would double-count a re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('coc_transactions')->where('direction', 'conversion')->delete();

            $this->rebuildConversions();
            $this->restateOutputs();
        });
    }

    /**
     * Certified material issued to a job, net of what came back (IN-3 returns), one row per
     * job card and lot.
     */
    private function rebuildConversions(): void
    {
        $lines = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->join('stock_lots as sl', 'sl.id', '=', 'mil.lot_id')
            ->where('mi.status', 'posted')
            ->whereNotNull('sl.cert_scheme')
            ->where('sl.cert_claim_pct', '>', 0)
            ->selectRaw("
                mi.job_card_id,
                mil.lot_id,
                mil.item_id,
                mil.uom_id,
                sl.cert_scheme,
                sl.cert_claim_pct,
                MIN(mi.issued_on) as first_issued_on,
                SUM(CASE WHEN mi.issue_type = 'return' THEN -mil.qty ELSE mil.qty END) as net_qty
            ")
            ->groupBy('mi.job_card_id', 'mil.lot_id', 'mil.item_id', 'mil.uom_id', 'sl.cert_scheme', 'sl.cert_claim_pct')
            ->get();

        foreach ($lines as $line) {
            $certified = round((float) $line->net_qty * (float) $line->cert_claim_pct / 100, 6);

            // `coc_qty_chk` admits positive quantities only; a lot fully returned leaves no
            // consumption to record.
            if ($certified <= 0) {
                continue;
            }

            DB::table('coc_transactions')->insert([
                'scheme' => $line->cert_scheme,
                'direction' => 'conversion',
                'lot_id' => $line->lot_id,
                'job_card_id' => $line->job_card_id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'qty' => $certified,
                'claim_pct' => $line->cert_claim_pct,
                'period_year' => (int) date('Y', strtotime((string) $line->first_issued_on)),
                'period_month' => (int) date('n', strtotime((string) $line->first_issued_on)),
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Restate shipped output as the certified mass behind it: the job's certified consumption,
     * allocated by the share of the job's output that left. The piece count is recovered from
     * the row itself — it was stored as pieces × claim.
     */
    private function restateOutputs(): void
    {
        $outputs = DB::table('coc_transactions')
            ->where('direction', 'output')
            ->whereNotNull('job_card_id')
            ->get(['id', 'scheme', 'job_card_id', 'qty', 'claim_pct']);

        foreach ($outputs as $output) {
            if ((float) $output->claim_pct <= 0) {
                continue;
            }

            $consumed = DB::table('coc_transactions')
                ->where('direction', 'conversion')
                ->where('scheme', $output->scheme)
                ->where('job_card_id', $output->job_card_id)
                ->selectRaw('SUM(qty) as qty, MIN(uom_id) as uom_id')
                ->first();

            $produced = (float) DB::table('fg_receipts')
                ->where('job_card_id', $output->job_card_id)
                ->where('status', 'posted')
                ->sum('qty');

            if ($consumed === null || (float) $consumed->qty <= 0 || $produced <= 0) {
                continue;
            }

            $shippedPieces = (float) $output->qty / ((float) $output->claim_pct / 100);
            $mass = floor((float) $consumed->qty * $shippedPieces / $produced * 1_000_000) / 1_000_000;

            if ($mass <= 0) {
                continue;
            }

            DB::table('coc_transactions')->where('id', $output->id)->update([
                'qty' => $mass,
                'uom_id' => $consumed->uom_id,
            ]);
        }
    }

    public function down(): void
    {
        // Rebuilt from source records; there is no earlier state worth restoring, and the
        // piece-basis figures it replaced were the defect.
    }
};
