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
        if (Schema::hasTable('supplier_rfq_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_rfq_lines (
    id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rfq_id  BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    qty     DECIMAL(18,6) NOT NULL,
    uom_id  BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY supplier_rfq_lines_uq (rfq_id, line_no),
    KEY supplier_rfq_lines_item_idx (item_id),
    KEY supplier_rfq_lines_uom_idx (uom_id),
    CONSTRAINT supplier_rfq_lines_rfq_fk  FOREIGN KEY (rfq_id)  REFERENCES supplier_rfqs(id) ON DELETE CASCADE,
    CONSTRAINT supplier_rfq_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT supplier_rfq_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT supplier_rfq_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_rfq_lines');
    }
};
