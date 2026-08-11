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
        if (Schema::hasTable('downtime_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE downtime_logs (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id            BIGINT UNSIGNED NOT NULL,
    job_card_operation_id BIGINT UNSIGNED,
    downtime_reason_id    BIGINT UNSIGNED NOT NULL,
    shift_id              BIGINT UNSIGNED,
    started_at            DATETIME(3) NOT NULL,
    ended_at              DATETIME(3),
    minutes               DECIMAL(9,2),
    reported_by           BIGINT UNSIGNED,
    remarks               VARCHAR(255),
    KEY downtime_logs_machine_idx (machine_id, started_at),
    KEY downtime_logs_op_idx (job_card_operation_id),
    KEY downtime_logs_reason_idx (downtime_reason_id),
    KEY downtime_logs_shift_idx (shift_id),
    KEY downtime_logs_reporter_idx (reported_by),
    CONSTRAINT downtime_logs_machine_fk  FOREIGN KEY (machine_id)            REFERENCES machines(id),
    CONSTRAINT downtime_logs_op_fk       FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT downtime_logs_reason_fk   FOREIGN KEY (downtime_reason_id)    REFERENCES downtime_reasons(id),
    CONSTRAINT downtime_logs_shift_fk    FOREIGN KEY (shift_id)              REFERENCES shifts(id),
    CONSTRAINT downtime_logs_reporter_fk FOREIGN KEY (reported_by)           REFERENCES employees(id),
    CONSTRAINT downtime_logs_time_chk CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_logs');
    }
};
