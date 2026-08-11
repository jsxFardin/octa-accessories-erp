<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10. QUALITY & LABORATORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qc_inspections')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE qc_inspections (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number                VARCHAR(30),
    stage                 VARCHAR(20) NOT NULL,
    job_card_id           BIGINT UNSIGNED,
    job_card_operation_id BIGINT UNSIGNED,
    grn_line_id           BIGINT UNSIGNED,
    lot_id                BIGINT UNSIGNED,
    aql_plan_id           BIGINT UNSIGNED,
    inspected_on          DATE NOT NULL DEFAULT (CURRENT_DATE),
    inspector_id          BIGINT UNSIGNED,
    lot_size              BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sample_size           INT UNSIGNED NOT NULL DEFAULT 0,
    critical_found        INT UNSIGNED NOT NULL DEFAULT 0,
    major_found           INT UNSIGNED NOT NULL DEFAULT 0,
    minor_found           INT UNSIGNED NOT NULL DEFAULT 0,
    accept_number         INT UNSIGNED,
    reject_number         INT UNSIGNED,
    dhu                   DECIMAL(9,4),
    result                VARCHAR(30) NOT NULL DEFAULT 'pending',
    disposition           VARCHAR(20),
    disposition_ref       VARCHAR(180),
    remarks               VARCHAR(500),
    created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by            BIGINT UNSIGNED,
    UNIQUE KEY qc_inspections_number_uq (number),
    KEY qc_inspections_job_idx (job_card_id, stage),
    KEY qc_inspections_op_idx (job_card_operation_id),
    KEY qc_inspections_grnline_idx (grn_line_id),
    KEY qc_inspections_lot_idx (lot_id),
    KEY qc_inspections_plan_idx (aql_plan_id),
    KEY qc_inspections_inspector_idx (inspector_id),
    KEY qc_inspections_creator_idx (created_by),
    CONSTRAINT qc_inspections_job_fk       FOREIGN KEY (job_card_id)           REFERENCES job_cards(id),
    CONSTRAINT qc_inspections_op_fk        FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id),
    CONSTRAINT qc_inspections_grnline_fk   FOREIGN KEY (grn_line_id)           REFERENCES grn_lines(id),
    CONSTRAINT qc_inspections_lot_fk       FOREIGN KEY (lot_id)                REFERENCES stock_lots(id),
    CONSTRAINT qc_inspections_plan_fk      FOREIGN KEY (aql_plan_id)           REFERENCES aql_plans(id),
    CONSTRAINT qc_inspections_inspector_fk FOREIGN KEY (inspector_id)          REFERENCES employees(id),
    CONSTRAINT qc_inspections_creator_fk   FOREIGN KEY (created_by)            REFERENCES users(id),
    CONSTRAINT qc_inspections_stage_chk  CHECK (stage IN ('incoming','in_process','final','pre_shipment','customer')),
    CONSTRAINT qc_inspections_result_chk CHECK (result IN ('pending','accepted','rejected','accepted_with_concession')),
    CONSTRAINT qc_inspections_disp_fk    FOREIGN KEY (disposition) REFERENCES qc_dispositions(code),
    CONSTRAINT qc_inspections_rejected_chk CHECK (result <> 'rejected' OR disposition IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspections');
    }
};
