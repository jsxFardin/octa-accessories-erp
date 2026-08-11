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
        if (Schema::hasTable('test_reports')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE test_reports (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number         VARCHAR(30),
    lot_id         BIGINT UNSIGNED,
    job_card_id    BIGINT UNSIGNED,
    product_id     BIGINT UNSIGNED,
    customer_id    BIGINT UNSIGNED,
    tested_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    technician_id  BIGINT UNSIGNED,
    overall_result VARCHAR(10) NOT NULL DEFAULT 'pending',
    status         VARCHAR(20) NOT NULL DEFAULT 'draft',
    issued_at      DATETIME(3),
    remarks        VARCHAR(500),
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by     BIGINT UNSIGNED,
    UNIQUE KEY test_reports_number_uq (number),
    KEY test_reports_lot_idx (lot_id),
    KEY test_reports_job_idx (job_card_id),
    KEY test_reports_product_idx (product_id),
    KEY test_reports_customer_idx (customer_id, tested_on),
    KEY test_reports_tech_idx (technician_id),
    KEY test_reports_creator_idx (created_by),
    CONSTRAINT test_reports_lot_fk      FOREIGN KEY (lot_id)        REFERENCES stock_lots(id),
    CONSTRAINT test_reports_job_fk      FOREIGN KEY (job_card_id)   REFERENCES job_cards(id),
    CONSTRAINT test_reports_product_fk  FOREIGN KEY (product_id)    REFERENCES products(id),
    CONSTRAINT test_reports_customer_fk FOREIGN KEY (customer_id)   REFERENCES customers(id),
    CONSTRAINT test_reports_tech_fk     FOREIGN KEY (technician_id) REFERENCES employees(id),
    CONSTRAINT test_reports_creator_fk  FOREIGN KEY (created_by)    REFERENCES users(id),
    CONSTRAINT test_reports_result_chk CHECK (overall_result IN ('pending','pass','fail')),
    CONSTRAINT test_reports_status_chk CHECK (status IN ('draft','issued','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('test_reports');
    }
};
