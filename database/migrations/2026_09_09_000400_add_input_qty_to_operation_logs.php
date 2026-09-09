<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much input a booking brought with it.
 *
 * `operation_logs` deliberately had no such column: per-shift input accumulated on the
 * operation row and J3 was checked against that running total. That held for as long as a
 * booking could never be undone — nothing needed to know which shift contributed which part.
 *
 * Reversals broke it. A correction takes back the good and the waste it can read off the row
 * being reversed, and it cannot take back an input figure nobody wrote down. So a reversed
 * booking left its input behind: an operation showing 6,500 handed over and nothing produced,
 * and the next legitimate booking of the same 6,500 refused with "13,000 exceeds its 6,695
 * plan". The supervisor undid a mistake and the system kept half of it.
 *
 * Rows written before this migration carry 0, because for them the figure genuinely was never
 * captured; reversing one of those still leaves its input on the operation, and that is a
 * statement about missing history rather than something to invent a number for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operation_logs', 'input_qty')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE operation_logs
    ADD COLUMN input_qty DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER job_card_operation_id
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operation_logs', 'input_qty')) {
            return;
        }

        DB::unprepared('ALTER TABLE operation_logs DROP COLUMN input_qty');
    }
};
