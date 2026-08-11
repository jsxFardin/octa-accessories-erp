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
        if (Schema::hasTable('purchase_requisitions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_requisitions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    department_id   BIGINT UNSIGNED,
    requested_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by     DATE,
    origin          VARCHAR(20) NOT NULL DEFAULT 'manual',
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    approved_by     BIGINT UNSIGNED,
    approved_at     DATETIME(3),
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY purchase_requisitions_number_uq (number),
    KEY purchase_requisitions_status_idx (status, required_by),
    KEY purchase_requisitions_unit_idx (factory_unit_id),
    KEY purchase_requisitions_dept_idx (department_id),
    KEY purchase_requisitions_approver_idx (approved_by),
    KEY purchase_requisitions_creator_idx (created_by),
    CONSTRAINT purchase_requisitions_unit_fk     FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT purchase_requisitions_dept_fk     FOREIGN KEY (department_id)   REFERENCES departments(id),
    CONSTRAINT purchase_requisitions_approver_fk FOREIGN KEY (approved_by)     REFERENCES users(id),
    CONSTRAINT purchase_requisitions_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT purchase_requisitions_origin_chk CHECK (origin IN ('manual','mrp','reorder_level')),
    CONSTRAINT purchase_requisitions_status_chk CHECK (status IN ('draft','submitted','approved','partially_ordered','ordered','rejected','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requisitions');
    }
};
