<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7. INVENTORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_lots')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_lots (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_no            VARCHAR(40) NOT NULL,
    item_id           BIGINT UNSIGNED,
    product_id        BIGINT UNSIGNED,
    kind              VARCHAR(20) NOT NULL,
    warehouse_id      BIGINT UNSIGNED NOT NULL,
    bin_id            BIGINT UNSIGNED,
    uom_id            BIGINT UNSIGNED NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    balance_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,   -- derived cache, I3
    unit_cost         DECIMAL(18,4) NOT NULL DEFAULT 0,
    grn_line_id       BIGINT UNSIGNED,
    job_card_id       BIGINT UNSIGNED,                    -- FK added in §9 (circular)
    parent_lot_id     BIGINT UNSIGNED,
    supplier_batch_no VARCHAR(60),
    shade_code        VARCHAR(40),
    roll_length_m     DECIMAL(18,6),                      -- lot-level UoM override, BR-3
    received_on       DATE NOT NULL DEFAULT (CURRENT_DATE),
    expiry_date       DATE,
    cert_scheme       VARCHAR(20),                        -- claim carried by the lot, I5
    cert_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 0,
    cert_document_no  VARCHAR(80),
    status            VARCHAR(20) NOT NULL DEFAULT 'available',
    barcode           VARCHAR(64),
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_lots_lot_no_uq (lot_no),
    UNIQUE KEY stock_lots_barcode_uq (barcode),
    KEY stock_lots_item_wh_idx (item_id, warehouse_id, status),
    KEY stock_lots_product_idx (product_id, status),
    KEY stock_lots_shade_idx (item_id, shade_code, status),
    KEY stock_lots_bin_idx (bin_id),
    KEY stock_lots_uom_idx (uom_id),
    KEY stock_lots_grnline_idx (grn_line_id),
    KEY stock_lots_parent_idx (parent_lot_id),
    KEY stock_lots_job_idx (job_card_id),
    KEY stock_lots_expiry_idx (expiry_date),
    CONSTRAINT stock_lots_item_fk      FOREIGN KEY (item_id)       REFERENCES items(id),
    CONSTRAINT stock_lots_product_fk   FOREIGN KEY (product_id)    REFERENCES products(id),
    CONSTRAINT stock_lots_warehouse_fk FOREIGN KEY (warehouse_id)  REFERENCES warehouses(id),
    CONSTRAINT stock_lots_bin_fk       FOREIGN KEY (bin_id)        REFERENCES bins(id),
    CONSTRAINT stock_lots_uom_fk       FOREIGN KEY (uom_id)        REFERENCES uoms(id),
    CONSTRAINT stock_lots_grnline_fk   FOREIGN KEY (grn_line_id)   REFERENCES grn_lines(id),
    CONSTRAINT stock_lots_parent_fk    FOREIGN KEY (parent_lot_id) REFERENCES stock_lots(id),
    CONSTRAINT stock_lots_kind_chk    CHECK (kind IN ('raw_material','wip','finished_goods','sample','scrap','second_quality')),
    CONSTRAINT stock_lots_status_chk  CHECK (status IN ('quarantine','available','reserved','consumed','blocked','expired','scrapped')),
    CONSTRAINT stock_lots_owner_chk   CHECK (item_id IS NOT NULL OR product_id IS NOT NULL),
    CONSTRAINT stock_lots_balance_chk CHECK (balance_qty >= 0),          -- BR-38 / I4
    CONSTRAINT stock_lots_cert_chk    CHECK (cert_claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
