<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 6. PROCUREMENT
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_quotation_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_quotation_lines (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_quotation_id BIGINT UNSIGNED NOT NULL,
    line_no               SMALLINT UNSIGNED NOT NULL,
    item_id               BIGINT UNSIGNED NOT NULL,
    qty                   DECIMAL(18,6) NOT NULL,
    uom_id                BIGINT UNSIGNED NOT NULL,
    rate                  DECIMAL(18,4) NOT NULL,
    amount                DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY supplier_quotation_lines_uq (supplier_quotation_id, line_no),
    KEY supplier_quotation_lines_item_idx (item_id),
    KEY supplier_quotation_lines_uom_idx (uom_id),
    CONSTRAINT supplier_quotation_lines_sq_fk   FOREIGN KEY (supplier_quotation_id) REFERENCES supplier_quotations(id) ON DELETE CASCADE,
    CONSTRAINT supplier_quotation_lines_item_fk FOREIGN KEY (item_id)               REFERENCES items(id),
    CONSTRAINT supplier_quotation_lines_uom_fk  FOREIGN KEY (uom_id)                REFERENCES uoms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotation_lines');
    }
};
