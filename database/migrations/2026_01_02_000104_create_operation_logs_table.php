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
        if (Schema::hasTable('operation_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE operation_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_operation_id BIGINT UNSIGNED NOT NULL,
    machine_id            BIGINT UNSIGNED,
    operator_id           BIGINT UNSIGNED,
    shift_id              BIGINT UNSIGNED,
    started_at            DATETIME(3) NOT NULL,
    ended_at              DATETIME(3),
    good_qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty             DECIMAL(18,6) NOT NULL DEFAULT 0,
    input_lot_id          BIGINT UNSIGNED,
    output_lot_id         BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by            BIGINT UNSIGNED,
    KEY operation_logs_op_idx (job_card_operation_id, started_at),
    KEY operation_logs_machine_idx (machine_id, started_at),
    KEY operation_logs_operator_idx (operator_id, started_at),
    KEY operation_logs_shift_idx (shift_id),
    KEY operation_logs_inlot_idx (input_lot_id),
    KEY operation_logs_outlot_idx (output_lot_id),
    KEY operation_logs_creator_idx (created_by),
    CONSTRAINT operation_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id) ON DELETE CASCADE,
    CONSTRAINT operation_logs_machine_fk  FOREIGN KEY (machine_id)            REFERENCES machines(id),
    CONSTRAINT operation_logs_operator_fk FOREIGN KEY (operator_id)           REFERENCES employees(id),
    CONSTRAINT operation_logs_shift_fk    FOREIGN KEY (shift_id)              REFERENCES shifts(id),
    CONSTRAINT operation_logs_inlot_fk    FOREIGN KEY (input_lot_id)          REFERENCES stock_lots(id),
    CONSTRAINT operation_logs_outlot_fk   FOREIGN KEY (output_lot_id)         REFERENCES stock_lots(id),
    CONSTRAINT operation_logs_creator_fk  FOREIGN KEY (created_by)            REFERENCES users(id),
    CONSTRAINT operation_logs_time_chk CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
};
