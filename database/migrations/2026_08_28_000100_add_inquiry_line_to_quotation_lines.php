<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F-05 — records which inquiry line a quotation line answers.
 *
 * `quotations.inquiry_id` tied the two documents together, but nothing tied their lines, so
 * an inquiry for 1,000 pcs at a target of 100.0000/M becoming a quotation for 100,000 pcs at
 * 270.2066/M was unexplainable from either screen: the numbers simply differed and no query
 * could say which line had become which.
 *
 * Quoting a quantity other than the one inquired is **legitimate** and stays legitimate. A
 * customer asks for a sample volume and is quoted at the minimum order quantity, or at the
 * annual programme volume the tooling is amortised over; forcing the two to agree would break
 * ordinary merchandising and lose the customer's actual request. What was missing was not a
 * constraint but the ability to see the change, so this column is nullable and carries no
 * check — it records the answer, it does not police it.
 *
 * Nullable also because history cannot be reconstructed. A quotation raised before this column
 * existed has no recorded line pairing, and inventing one by matching positions would be a
 * guess written into the database as a fact. Those quotations keep their document-level link
 * and the screens compare them at document level, which is honest about what is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quotation_lines', 'inquiry_line_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE quotation_lines
    ADD COLUMN inquiry_line_id BIGINT UNSIGNED NULL AFTER quotation_id,
    ADD KEY quotation_lines_inquiry_line_idx (inquiry_line_id),
    ADD CONSTRAINT quotation_lines_inquiry_line_fk
        FOREIGN KEY (inquiry_line_id) REFERENCES inquiry_lines(id) ON DELETE SET NULL
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quotation_lines', 'inquiry_line_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE quotation_lines
    DROP FOREIGN KEY quotation_lines_inquiry_line_fk,
    DROP KEY quotation_lines_inquiry_line_idx,
    DROP COLUMN inquiry_line_id
SQL);
    }
};
