<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1a. VOCABULARIES
 *
 * The dropdowns that used to be enums in PHP. Each one is a table an
 * administrator can add to, rename and retire, and the behaviour that used
 * to live in a `match` expression lives here as columns: whether a product
 * type consumes yarn (BR-9) or sheets (BR-11), the ink lay it defaults to
 * (BR-10), the tools a colour costs (BR-13), the cut gap a cut type adds
 * (BR-4), what a QC disposition does to the lot (BR-33).
 *
 * The columns that carry these values are VARCHAR codes with a foreign key
 * to `code` rather than a CHECK constraint: same refusal of a value that
 * does not exist, without a schema change to add one.
 *
 * A row added through Setup gets neutral behaviour — no yarn, no ink, no
 * sheets, no tool — which is the conservative default for a type the
 * costing rules have never seen. Change the flags and the calculators
 * follow immediately.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qc_dispositions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE qc_dispositions (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                      VARCHAR(20)  NOT NULL,
    name                      VARCHAR(120) NOT NULL,
    returns_to_operation      BOOLEAN NOT NULL DEFAULT FALSE,
    requires_customer_evidence BOOLEAN NOT NULL DEFAULT FALSE,
    regrades_stock            BOOLEAN NOT NULL DEFAULT FALSE,
    writes_off_stock          BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order                SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active                 BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY qc_dispositions_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_dispositions');
    }
};
