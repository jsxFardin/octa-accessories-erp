<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9. MANUFACTURING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('material_issue_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE material_issue_lines (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    material_issue_id    BIGINT UNSIGNED NOT NULL,
    line_no              SMALLINT UNSIGNED NOT NULL,
    item_id              BIGINT UNSIGNED NOT NULL,
    lot_id               BIGINT UNSIGNED NOT NULL,
    uom_id               BIGINT UNSIGNED NOT NULL,
    qty                  DECIMAL(18,6) NOT NULL,
    unit_cost            DECIMAL(18,4) NOT NULL DEFAULT 0,
    fifo_override_reason VARCHAR(255),                     -- BR-37
    UNIQUE KEY material_issue_lines_uq (material_issue_id, line_no),
    KEY material_issue_lines_item_idx (item_id),
    KEY material_issue_lines_lot_idx (lot_id),
    KEY material_issue_lines_uom_idx (uom_id),
    CONSTRAINT material_issue_lines_issue_fk FOREIGN KEY (material_issue_id) REFERENCES material_issues(id) ON DELETE CASCADE,
    CONSTRAINT material_issue_lines_item_fk  FOREIGN KEY (item_id)           REFERENCES items(id),
    CONSTRAINT material_issue_lines_lot_fk   FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT material_issue_lines_uom_fk   FOREIGN KEY (uom_id)            REFERENCES uoms(id),
    CONSTRAINT material_issue_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issue_lines');
    }
};
