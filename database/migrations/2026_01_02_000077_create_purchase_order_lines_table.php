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
        if (Schema::hasTable('purchase_order_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_order_lines (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    po_id         BIGINT UNSIGNED NOT NULL,
    line_no       SMALLINT UNSIGNED NOT NULL,
    item_id       BIGINT UNSIGNED NOT NULL,
    pr_line_id    BIGINT UNSIGNED,
    description   VARCHAR(255),
    qty           DECIMAL(18,6) NOT NULL,
    uom_id        BIGINT UNSIGNED NOT NULL,
    rate          DECIMAL(18,4) NOT NULL,
    tax_id        BIGINT UNSIGNED,
    amount        DECIMAL(18,4) NOT NULL DEFAULT 0,
    received_qty  DECIMAL(18,6) NOT NULL DEFAULT 0,
    billed_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    expected_date DATE,
    cert_claim    VARCHAR(20),
    UNIQUE KEY purchase_order_lines_uq (po_id, line_no),
    KEY purchase_order_lines_item_idx (item_id),
    KEY purchase_order_lines_prline_idx (pr_line_id),
    KEY purchase_order_lines_uom_idx (uom_id),
    KEY purchase_order_lines_tax_idx (tax_id),
    CONSTRAINT purchase_order_lines_po_fk     FOREIGN KEY (po_id)      REFERENCES purchase_orders(id) ON DELETE CASCADE,
    CONSTRAINT purchase_order_lines_item_fk   FOREIGN KEY (item_id)    REFERENCES items(id),
    CONSTRAINT purchase_order_lines_prline_fk FOREIGN KEY (pr_line_id) REFERENCES purchase_requisition_lines(id),
    CONSTRAINT purchase_order_lines_uom_fk    FOREIGN KEY (uom_id)     REFERENCES uoms(id),
    CONSTRAINT purchase_order_lines_tax_fk    FOREIGN KEY (tax_id)     REFERENCES taxes(id),
    CONSTRAINT purchase_order_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT purchase_order_lines_rate_chk CHECK (rate >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
