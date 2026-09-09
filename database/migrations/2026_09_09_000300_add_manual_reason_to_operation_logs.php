<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a booking was keyed at a desk instead of recorded at the machine.
 *
 * Output, downtime and waste could only ever reach the system through the floor terminal's
 * device API — badge and PIN, no web session, no permission that substitutes for one. That is
 * the right default: output is the shop-floor truth, and a desk form invites yesterday's
 * figures being typed off paper, which is the habit G1 exists to end.
 *
 * It is not a workable *only* option. A kiosk that dies mid-shift strands that shift's output
 * with no route in, and the tablet is the one thing on a factory floor guaranteed to break on
 * the day it matters.
 *
 * So the desk route exists and is marked. A manual booking is an exception, and an exception
 * that cannot be told apart from the norm stops being one: with this column a supervisor can
 * see which figures came off the machine and which were typed, and an auditor asking "how do
 * you know this is what was made" gets a different answer for each.
 *
 * Null means the terminal recorded it, which is almost every row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operation_logs', 'manual_reason')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE operation_logs
    ADD COLUMN manual_reason VARCHAR(255) NULL AFTER reversal_reason
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('operation_logs', 'manual_reason')) {
            return;
        }

        DB::unprepared('ALTER TABLE operation_logs DROP COLUMN manual_reason');
    }
};
