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
 * Machine utilisation input (BR-27).
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_machine_load`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_machine_load AS
SELECT
    jco.machine_id,
    m.code                       AS machine_code,
    DATE(jco.scheduled_start)    AS load_date,
    SUM(jco.planned_minutes)     AS load_minutes,
    COUNT(*)                     AS operation_count
FROM job_card_operations jco
JOIN machines m ON m.id = jco.machine_id
WHERE jco.status IN ('pending','ready','in_progress')
  AND jco.scheduled_start IS NOT NULL
GROUP BY jco.machine_id, m.code, DATE(jco.scheduled_start)
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_machine_load`');
    }
};
