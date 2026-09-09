<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which shift booking a correction reverses.
 *
 * Production had no correction path at all. `operation_logs` rows were written once by the
 * floor terminal and never listed, edited or undone; `job_card_operations.good_qty` only ever
 * accumulated; and `sales_order_lines.produced_qty` was `increment()`-ed and never decremented
 * by anything. An operator who typed 5,000 where they meant 500 left a figure that no screen
 * in the application could correct, and that the order line's fulfilment position then carried
 * for the life of the order.
 *
 * Inventory solved the same problem years of ledger design ago and this follows it (I1): a
 * correction is a reversing entry, never an edit. The original row stays exactly as booked —
 * it is what the operator recorded, and rewriting it would destroy the evidence that the
 * mistake happened. The reversal is a second row with negated quantities pointing back at it.
 *
 * Nullable because almost every row is an ordinary booking. Non-null means "this row exists to
 * cancel that one", which is also what stops a booking being reversed twice: the guard looks
 * for an existing reversal against the same log rather than trusting a status column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operation_logs', 'reverses_log_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE operation_logs
    ADD COLUMN reverses_log_id BIGINT UNSIGNED NULL AFTER job_card_operation_id,
    ADD COLUMN reversal_reason VARCHAR(255) NULL AFTER remarks,
    ADD UNIQUE KEY operation_logs_reverses_uq (reverses_log_id),
    ADD CONSTRAINT operation_logs_reverses_fk
        FOREIGN KEY (reverses_log_id) REFERENCES operation_logs(id)
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operation_logs', 'reverses_log_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE operation_logs
    DROP FOREIGN KEY operation_logs_reverses_fk,
    DROP KEY operation_logs_reverses_uq,
    DROP COLUMN reversal_reason,
    DROP COLUMN reverses_log_id
SQL);
    }
};
