<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Somewhere for a draft goods receipt to keep a roll's length.
 *
 * A receipt used to be posted in the same step it was typed in, so the roll length went from
 * the form straight onto the stock lot and never needed a home of its own. A receipt can now
 * be saved as a draft and posted later (UX audit H-18); until it is posted there is no lot,
 * and the length would be lost between the two steps. Nullable: only rolled goods have one,
 * and every receipt posted before this has it on its lot already.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE grn_lines ADD COLUMN roll_length_m DECIMAL(18,6) NULL AFTER expiry_date');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE grn_lines DROP COLUMN roll_length_m');
    }
};
