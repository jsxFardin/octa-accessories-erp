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
        if (Schema::hasTable('job_card_operations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE job_card_operations (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_card_id          BIGINT UNSIGNED NOT NULL,
    routing_operation_id BIGINT UNSIGNED,
    sequence_no          SMALLINT UNSIGNED NOT NULL,
    code                 VARCHAR(30)  NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    machine_group_id     BIGINT UNSIGNED,
    machine_id           BIGINT UNSIGNED,
    tool_id              BIGINT UNSIGNED,
    planned_qty          DECIMAL(18,6) NOT NULL DEFAULT 0,
    input_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    good_qty             DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    planned_minutes      DECIMAL(9,2) NOT NULL DEFAULT 0,
    actual_minutes       DECIMAL(9,2) NOT NULL DEFAULT 0,
    scheduled_start      DATETIME(3),
    scheduled_finish     DATETIME(3),
    started_at           DATETIME(3),
    finished_at          DATETIME(3),
    requires_qc          BOOLEAN NOT NULL DEFAULT FALSE,
    status               VARCHAR(20) NOT NULL DEFAULT 'pending',
    UNIQUE KEY job_card_operations_uq (job_card_id, sequence_no),
    KEY job_card_operations_machine_idx (machine_id, status, scheduled_start),
    KEY job_card_operations_routingop_idx (routing_operation_id),
    KEY job_card_operations_group_idx (machine_group_id),
    KEY job_card_operations_tool_idx (tool_id),
    CONSTRAINT job_card_operations_job_fk       FOREIGN KEY (job_card_id)          REFERENCES job_cards(id) ON DELETE CASCADE,
    CONSTRAINT job_card_operations_routingop_fk FOREIGN KEY (routing_operation_id) REFERENCES routing_operations(id),
    CONSTRAINT job_card_operations_group_fk     FOREIGN KEY (machine_group_id)     REFERENCES machine_groups(id),
    CONSTRAINT job_card_operations_machine_fk   FOREIGN KEY (machine_id)           REFERENCES machines(id),
    CONSTRAINT job_card_operations_tool_fk      FOREIGN KEY (tool_id)              REFERENCES tools(id),
    CONSTRAINT job_card_operations_status_chk CHECK (status IN ('pending','ready','in_progress','paused','completed','skipped','cancelled')),
    CONSTRAINT job_card_operations_output_chk CHECK (good_qty + waste_qty <= input_qty + 0.000001)   -- J3
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('job_card_operations');
    }
};
