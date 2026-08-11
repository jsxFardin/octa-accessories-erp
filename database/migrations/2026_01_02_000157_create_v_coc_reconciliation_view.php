<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 14. DERIVED OBJECTS
 *
 * MySQL has no materialised views. `stock_balances` is a summary TABLE
 * maintained by the application (refreshed after posting batches and on a
 * schedule); `v_stock_balances` recomputes the same figures live from the
 * ledger and is the reconciliation reference. See 02-database-schema §4.
 *
 * Chain-of-custody reconciliation (BR-42).
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
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

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_coc_reconciliation`');
    }
};
