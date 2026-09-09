<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shift booking a waste record came from.
 *
 * Waste is now written by the terminal in the same transaction as the booking that produced it
 * (G4), which makes it derived data rather than an independent observation — and derived data
 * has to come back out when the booking it came from is reversed. Without this column there is
 * no way to say which waste rows belong to which booking, and reversing a mis-keyed 5,000
 * would leave 5,000 standing in the waste report for good.
 *
 * `waste_logs_qty_chk` requires `qty > 0`, so a reversing entry in the inventory sense is not
 * possible on this table; the linked rows are removed instead. That is the reason the link
 * exists rather than a status column: there is nothing to mark, only something to find.
 * The reversal itself stays permanently on `operation_logs` and on the audit trail.
 *
 * Nullable, because a waste record raised by hand later — a store keeper writing off a damaged
 * roll — belongs to no booking and is nobody's to remove.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('waste_logs', 'operation_log_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE waste_logs
    ADD COLUMN operation_log_id BIGINT UNSIGNED NULL AFTER job_card_operation_id,
    ADD KEY waste_logs_oplog_idx (operation_log_id),
    ADD CONSTRAINT waste_logs_oplog_fk
        FOREIGN KEY (operation_log_id) REFERENCES operation_logs(id) ON DELETE CASCADE
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('waste_logs', 'operation_log_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE waste_logs
    DROP FOREIGN KEY waste_logs_oplog_fk,
    DROP KEY waste_logs_oplog_idx,
    DROP COLUMN operation_log_id
SQL);
    }
};
