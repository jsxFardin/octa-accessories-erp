<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11. COMPLIANCE & CHAIN OF CUSTODY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coc_transactions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE coc_transactions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certification_id BIGINT UNSIGNED,
    scheme           VARCHAR(20) NOT NULL,
    direction        VARCHAR(20) NOT NULL,
    period_year      SMALLINT UNSIGNED NOT NULL,
    period_month     TINYINT UNSIGNED NOT NULL,
    grn_line_id      BIGINT UNSIGNED,
    lot_id           BIGINT UNSIGNED,
    job_card_id      BIGINT UNSIGNED,
    packing_list_id  BIGINT UNSIGNED,                 -- FK added in §12 (circular)
    item_id          BIGINT UNSIGNED,
    product_id       BIGINT UNSIGNED,
    qty              DECIMAL(18,6) NOT NULL,
    uom_id           BIGINT UNSIGNED,
    claim_pct        DECIMAL(9,4) NOT NULL DEFAULT 0,
    document_no      VARCHAR(80),
    is_locked        BOOLEAN NOT NULL DEFAULT FALSE,  -- C3
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    KEY coc_period_idx (scheme, period_year, period_month, direction),
    KEY coc_cert_idx (certification_id),
    KEY coc_grnline_idx (grn_line_id),
    KEY coc_lot_idx (lot_id),
    KEY coc_job_idx (job_card_id),
    KEY coc_packing_idx (packing_list_id),
    KEY coc_item_idx (item_id),
    KEY coc_product_idx (product_id),
    KEY coc_uom_idx (uom_id),
    KEY coc_creator_idx (created_by),
    CONSTRAINT coc_cert_fk    FOREIGN KEY (certification_id) REFERENCES certifications(id),
    CONSTRAINT coc_grnline_fk FOREIGN KEY (grn_line_id)      REFERENCES grn_lines(id),
    CONSTRAINT coc_lot_fk     FOREIGN KEY (lot_id)           REFERENCES stock_lots(id),
    CONSTRAINT coc_job_fk     FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT coc_item_fk    FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT coc_product_fk FOREIGN KEY (product_id)       REFERENCES products(id),
    CONSTRAINT coc_uom_fk     FOREIGN KEY (uom_id)           REFERENCES uoms(id),
    CONSTRAINT coc_creator_fk FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT coc_direction_chk CHECK (direction IN ('input','conversion','output')),
    CONSTRAINT coc_month_chk     CHECK (period_month BETWEEN 1 AND 12),
    CONSTRAINT coc_qty_chk       CHECK (qty > 0),
    CONSTRAINT coc_claim_chk     CHECK (claim_pct BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('coc_transactions');
    }
};
