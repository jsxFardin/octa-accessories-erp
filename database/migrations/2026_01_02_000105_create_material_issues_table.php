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
        if (Schema::hasTable('material_issues')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE material_issues (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    job_card_id  BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    issued_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    issue_type   VARCHAR(20) NOT NULL DEFAULT 'issue',
    status       VARCHAR(20) NOT NULL DEFAULT 'draft',
    issued_by    BIGINT UNSIGNED,
    received_by  BIGINT UNSIGNED,
    remarks      VARCHAR(255),
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY material_issues_number_uq (number),
    KEY material_issues_job_idx (job_card_id),
    KEY material_issues_warehouse_idx (warehouse_id),
    KEY material_issues_issuer_idx (issued_by),
    KEY material_issues_receiver_idx (received_by),
    CONSTRAINT material_issues_job_fk       FOREIGN KEY (job_card_id)  REFERENCES job_cards(id),
    CONSTRAINT material_issues_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT material_issues_issuer_fk    FOREIGN KEY (issued_by)    REFERENCES users(id),
    CONSTRAINT material_issues_receiver_fk  FOREIGN KEY (received_by)  REFERENCES employees(id),
    CONSTRAINT material_issues_type_chk   CHECK (issue_type IN ('issue','return')),
    CONSTRAINT material_issues_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issues');
    }
};
