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
        if (Schema::hasTable('grn_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE grn_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grn_id            BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    po_line_id        BIGINT UNSIGNED,
    item_id           BIGINT UNSIGNED NOT NULL,
    uom_id            BIGINT UNSIGNED NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL,
    accepted_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    rejected_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate              DECIMAL(18,4) NOT NULL DEFAULT 0,
    landed_rate       DECIMAL(18,4) NOT NULL DEFAULT 0,
    supplier_batch_no VARCHAR(60),
    shade_code        VARCHAR(40),
    manufactured_on   DATE,
    expiry_date       DATE,
    cert_scheme       VARCHAR(20),
    cert_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 0,
    cert_document_no  VARCHAR(80),
    UNIQUE KEY grn_lines_uq (grn_id, line_no),
    KEY grn_lines_poline_idx (po_line_id),
    KEY grn_lines_item_idx (item_id),
    KEY grn_lines_uom_idx (uom_id),
    KEY grn_lines_cert_idx (cert_scheme),
    CONSTRAINT grn_lines_grn_fk    FOREIGN KEY (grn_id)     REFERENCES grns(id) ON DELETE CASCADE,
    CONSTRAINT grn_lines_poline_fk FOREIGN KEY (po_line_id) REFERENCES purchase_order_lines(id),
    CONSTRAINT grn_lines_item_fk   FOREIGN KEY (item_id)    REFERENCES items(id),
    CONSTRAINT grn_lines_uom_fk    FOREIGN KEY (uom_id)     REFERENCES uoms(id),
    CONSTRAINT grn_lines_qty_chk  CHECK (received_qty > 0),
    CONSTRAINT grn_lines_cert_chk CHECK (cert_claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_lines');
    }
};
