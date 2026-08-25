<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BR-42 — reconcile against consumption, not receipts.
 *
 * The original view compared certified *input* (what a GRN brought in) with certified output
 * (what shipped). Those are different questions. Yarn received in August but still on the
 * shelf is not evidence for a label that left the gate, and the two sides were not even in
 * the same unit — kilograms against pieces — so the conversion factor was arithmetic without
 * a meaning.
 *
 * `conversion` rows, written when material is issued to a job, are the missing leg. The
 * balance an auditor tests is output against consumption; receipts stay in the view because
 * "did you ever own this much certified fibre" is the other half of the question.
 *
 * `certified_basis_qty` is consumption where consumption exists, and receipts otherwise, so
 * periods recorded before this leg existed still produce a figure rather than a NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_coc_reconciliation`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_coc_reconciliation AS
SELECT
    scheme,
    period_year,
    period_month,
    SUM(CASE WHEN direction = 'input'      THEN qty ELSE 0 END) AS certified_input_qty,
    SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END) AS certified_consumed_qty,
    SUM(CASE WHEN direction = 'output'     THEN qty ELSE 0 END) AS certified_output_qty,
    CASE WHEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END) > 0
         THEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END)
         ELSE SUM(CASE WHEN direction = 'input' THEN qty ELSE 0 END)
    END                                                        AS certified_basis_qty,
    CASE WHEN (CASE WHEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END) > 0
                    THEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END)
                    ELSE SUM(CASE WHEN direction = 'input' THEN qty ELSE 0 END) END) > 0
         THEN ROUND(SUM(CASE WHEN direction = 'output' THEN qty ELSE 0 END)
                  / (CASE WHEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END) > 0
                          THEN SUM(CASE WHEN direction = 'conversion' THEN qty ELSE 0 END)
                          ELSE SUM(CASE WHEN direction = 'input' THEN qty ELSE 0 END) END), 4)
         ELSE NULL END                                          AS conversion_factor
FROM coc_transactions
GROUP BY scheme, period_year, period_month
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_coc_reconciliation`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_coc_reconciliation AS
SELECT
    scheme,
    period_year,
    period_month,
    SUM(CASE WHEN direction = 'input'  THEN qty ELSE 0 END) AS certified_input_qty,
    SUM(CASE WHEN direction = 'output' THEN qty ELSE 0 END) AS certified_output_qty,
    CASE WHEN SUM(CASE WHEN direction = 'input' THEN qty ELSE 0 END) > 0
         THEN ROUND(SUM(CASE WHEN direction = 'output' THEN qty ELSE 0 END)
                  / SUM(CASE WHEN direction = 'input'  THEN qty ELSE 0 END), 4)
         ELSE NULL END                                      AS conversion_factor
FROM coc_transactions
GROUP BY scheme, period_year, period_month
SQL);
    }
};
