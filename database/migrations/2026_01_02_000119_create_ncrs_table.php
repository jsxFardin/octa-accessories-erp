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
        if (Schema::hasTable('ncrs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE ncrs (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    source           VARCHAR(30) NOT NULL,
    qc_inspection_id BIGINT UNSIGNED,
    job_card_id      BIGINT UNSIGNED,
    supplier_id      BIGINT UNSIGNED,
    customer_id      BIGINT UNSIGNED,
    raised_on        DATE NOT NULL DEFAULT (CURRENT_DATE),
    description      TEXT NOT NULL,
    severity         VARCHAR(10) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'open',
    closed_on        DATE,
    raised_by        BIGINT UNSIGNED,
    owner_id         BIGINT UNSIGNED,
    UNIQUE KEY ncrs_number_uq (number),
    KEY ncrs_status_idx (status, raised_on),
    KEY ncrs_insp_idx (qc_inspection_id),
    KEY ncrs_job_idx (job_card_id),
    KEY ncrs_supplier_idx (supplier_id),
    KEY ncrs_customer_idx (customer_id),
    KEY ncrs_raiser_idx (raised_by),
    KEY ncrs_owner_idx (owner_id),
    CONSTRAINT ncrs_insp_fk     FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id),
    CONSTRAINT ncrs_job_fk      FOREIGN KEY (job_card_id)      REFERENCES job_cards(id),
    CONSTRAINT ncrs_supplier_fk FOREIGN KEY (supplier_id)      REFERENCES suppliers(id),
    CONSTRAINT ncrs_customer_fk FOREIGN KEY (customer_id)      REFERENCES customers(id),
    CONSTRAINT ncrs_raiser_fk   FOREIGN KEY (raised_by)        REFERENCES users(id),
    CONSTRAINT ncrs_owner_fk    FOREIGN KEY (owner_id)         REFERENCES users(id),
    CONSTRAINT ncrs_source_chk   CHECK (source IN ('incoming','in_process','final','customer_complaint','audit','lab')),
    CONSTRAINT ncrs_severity_fk FOREIGN KEY (severity) REFERENCES defect_severities(code),
    CONSTRAINT ncrs_status_chk   CHECK (status IN ('open','investigating','action_taken','verified','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ncrs');
    }
};
