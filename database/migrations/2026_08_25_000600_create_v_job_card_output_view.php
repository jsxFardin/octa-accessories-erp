<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 14. DERIVED OBJECTS
 *
 * P0-2 / J6 — what a job card actually made, in one place.
 *
 * `job_cards.good_qty` is a running total across every operation, and operations do not
 * share a unit: weaving books metres, packing books pieces. On a card that wove 407 m and
 * packed 30,000 labels it read 60,457 — against a plan of 30,000. Only the last operation
 * states the job's output, and that definition had been written out by hand in the job card
 * screen, the FG receipt ceiling, the order-line rollup and the production report, while the
 * list, the export, the sales order screen and the AQL lot size still read the column.
 *
 * A view rather than a fifth copy of the SQL: `Schema` sees its columns, so the export
 * registry can join it like a table, and there is exactly one place left to change if the
 * rule ever does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_job_card_output`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_job_card_output AS
SELECT
    jco.job_card_id,
    jco.sequence_no  AS final_sequence_no,
    jco.good_qty     AS good_qty,
    jco.waste_qty    AS waste_qty,
    jco.good_qty + jco.waste_qty AS produced_qty,
    jco.machine_id   AS final_machine_id,
    jco.status       AS final_status
FROM job_card_operations jco
JOIN (
    SELECT job_card_id, MAX(sequence_no) AS sequence_no
    FROM job_card_operations
    GROUP BY job_card_id
) last_op
  ON last_op.job_card_id = jco.job_card_id
 AND last_op.sequence_no = jco.sequence_no
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_job_card_output`');
    }
};
