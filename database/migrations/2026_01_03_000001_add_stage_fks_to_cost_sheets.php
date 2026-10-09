<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pre-production and post-production stages (spec §4) hang a cost sheet off a sales
 * order line and a job card. Both tables are created after `cost_sheets`, so the two foreign
 * keys are added here, once they exist. The document (docs/02a-schema.sql) prints them inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = collect(DB::select(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['cost_sheets', 'FOREIGN KEY'],
        ))->pluck('name');

        if (! $existing->contains('cost_sheets_soline_fk')) {
            DB::unprepared('ALTER TABLE cost_sheets ADD CONSTRAINT cost_sheets_soline_fk FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id) ON DELETE CASCADE');
        }

        if (! $existing->contains('cost_sheets_job_fk')) {
            DB::unprepared('ALTER TABLE cost_sheets ADD CONSTRAINT cost_sheets_job_fk FOREIGN KEY (job_card_id) REFERENCES job_cards(id) ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cost_sheets')) {
            DB::unprepared('ALTER TABLE cost_sheets DROP FOREIGN KEY cost_sheets_soline_fk, DROP FOREIGN KEY cost_sheets_job_fk');
        }
    }
};
