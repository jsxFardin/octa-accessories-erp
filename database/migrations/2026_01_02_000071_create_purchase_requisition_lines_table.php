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
        if (Schema::hasTable('purchase_requisition_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_requisition_lines (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pr_id       BIGINT UNSIGNED NOT NULL,
    line_no     SMALLINT UNSIGNED NOT NULL,
    item_id     BIGINT UNSIGNED NOT NULL,
    uom_id      BIGINT UNSIGNED NOT NULL,
    qty         DECIMAL(18,6) NOT NULL,
    ordered_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
    required_by DATE,
    job_card_id BIGINT UNSIGNED,             -- FK added in §9 (circular)
    remarks     VARCHAR(255),
    UNIQUE KEY purchase_requisition_lines_uq (pr_id, line_no),
    KEY purchase_requisition_lines_item_idx (item_id),
    KEY purchase_requisition_lines_uom_idx (uom_id),
    KEY purchase_requisition_lines_job_idx (job_card_id),
    CONSTRAINT purchase_requisition_lines_pr_fk   FOREIGN KEY (pr_id)   REFERENCES purchase_requisitions(id) ON DELETE CASCADE,
    CONSTRAINT purchase_requisition_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT purchase_requisition_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT purchase_requisition_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_lines');
    }
};
