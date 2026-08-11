<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5. SAMPLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sample_requests')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sample_requests (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    customer_id     BIGINT UNSIGNED NOT NULL,
    inquiry_id      BIGINT UNSIGNED,
    sales_order_id  BIGINT UNSIGNED,
    sample_type     VARCHAR(20) NOT NULL,
    requested_on    DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by     DATE,
    is_chargeable   BOOLEAN NOT NULL DEFAULT FALSE,
    charge_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'requested',
    merchandiser_id BIGINT UNSIGNED,
    notes           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY sample_requests_number_uq (number),
    KEY sample_requests_customer_idx (customer_id, status),
    KEY sample_requests_inquiry_idx (inquiry_id),
    KEY sample_requests_order_idx (sales_order_id),
    KEY sample_requests_merch_idx (merchandiser_id),
    KEY sample_requests_creator_idx (created_by),
    CONSTRAINT sample_requests_customer_fk FOREIGN KEY (customer_id)     REFERENCES customers(id),
    CONSTRAINT sample_requests_inquiry_fk  FOREIGN KEY (inquiry_id)      REFERENCES inquiries(id),
    CONSTRAINT sample_requests_order_fk    FOREIGN KEY (sales_order_id)  REFERENCES sales_orders(id),
    CONSTRAINT sample_requests_merch_fk    FOREIGN KEY (merchandiser_id) REFERENCES employees(id),
    CONSTRAINT sample_requests_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT sample_requests_type_chk   CHECK (sample_type IN ('proto','approval','colour','size_set','pre_production','shipment','counter')),
    CONSTRAINT sample_requests_status_chk CHECK (status IN ('requested','in_development','in_production','ready','dispatched','approved','rejected','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_requests');
    }
};
