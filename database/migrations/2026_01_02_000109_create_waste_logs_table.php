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
        if (Schema::hasTable('waste_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE waste_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_id           BIGINT UNSIGNED,
    job_card_operation_id BIGINT UNSIGNED,
    item_id               BIGINT UNSIGNED,
    lot_id                BIGINT UNSIGNED,
    waste_type            VARCHAR(20) NOT NULL,
    qty                   DECIMAL(18,6) NOT NULL,
    uom_id                BIGINT UNSIGNED,
    value                 DECIMAL(18,4) NOT NULL DEFAULT 0,
    occurred_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reported_by           BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    KEY waste_logs_job_idx (job_card_id, occurred_at),
    KEY waste_logs_op_idx (job_card_operation_id),
    KEY waste_logs_item_idx (item_id),
    KEY waste_logs_lot_idx (lot_id),
    KEY waste_logs_uom_idx (uom_id),
    KEY waste_logs_reporter_idx (reported_by),
    CONSTRAINT waste_logs_job_fk      FOREIGN KEY (job_card_id)           REFERENCES job_cards(id),
    CONSTRAINT waste_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT waste_logs_item_fk     FOREIGN KEY (item_id)               REFERENCES items(id),
    CONSTRAINT waste_logs_lot_fk      FOREIGN KEY (lot_id)                REFERENCES stock_lots(id),
    CONSTRAINT waste_logs_uom_fk      FOREIGN KEY (uom_id)                REFERENCES uoms(id),
    CONSTRAINT waste_logs_reporter_fk FOREIGN KEY (reported_by)           REFERENCES employees(id),
    CONSTRAINT waste_logs_qty_chk  CHECK (qty > 0),
    CONSTRAINT waste_logs_type_chk CHECK (waste_type IN ('setup','shade','print_defect','weave_defect','cutting','edge_trim','damaged','expired','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('waste_logs');
    }
};
