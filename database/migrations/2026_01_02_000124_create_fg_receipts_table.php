<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fg_receipts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE fg_receipts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    job_card_id      BIGINT UNSIGNED NOT NULL,
    warehouse_id     BIGINT UNSIGNED NOT NULL,
    lot_id           BIGINT UNSIGNED,
    received_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    qty              DECIMAL(18,6) NOT NULL,
    qc_inspection_id BIGINT UNSIGNED,
    grade            VARCHAR(10) NOT NULL DEFAULT 'A',
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    UNIQUE KEY fg_receipts_number_uq (number),
    KEY fg_receipts_job_idx (job_card_id),
    KEY fg_receipts_warehouse_idx (warehouse_id),
    KEY fg_receipts_lot_idx (lot_id),
    KEY fg_receipts_insp_idx (qc_inspection_id),
    KEY fg_receipts_creator_idx (created_by),
    CONSTRAINT fg_receipts_job_fk       FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT fg_receipts_warehouse_fk FOREIGN KEY (warehouse_id)     REFERENCES warehouses(id),
    CONSTRAINT fg_receipts_lot_fk       FOREIGN KEY (lot_id)           REFERENCES stock_lots(id),
    CONSTRAINT fg_receipts_insp_fk      FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id),
    CONSTRAINT fg_receipts_creator_fk   FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT fg_receipts_qty_chk    CHECK (qty > 0),
    CONSTRAINT fg_receipts_grade_chk  CHECK (grade IN ('A','B','reject')),
    CONSTRAINT fg_receipts_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fg_receipts');
    }
};
