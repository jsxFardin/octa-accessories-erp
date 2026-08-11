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
        if (Schema::hasTable('purchase_orders')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_orders (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    revision_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    supplier_id     BIGINT UNSIGNED NOT NULL,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    order_date      DATE NOT NULL DEFAULT (CURRENT_DATE),
    expected_date   DATE,
    currency_id     BIGINT UNSIGNED NOT NULL,
    exchange_rate   DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id BIGINT UNSIGNED,
    incoterm        VARCHAR(20),
    subtotal        DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(18,4) NOT NULL DEFAULT 0,
    freight_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    total           DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    approved_by     BIGINT UNSIGNED,
    approved_at     DATETIME(3),
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY purchase_orders_number_uq (number),
    KEY purchase_orders_open_idx (status, supplier_id, expected_date),
    KEY purchase_orders_unit_idx (factory_unit_id),
    KEY purchase_orders_currency_idx (currency_id),
    KEY purchase_orders_term_idx (payment_term_id),
    KEY purchase_orders_approver_idx (approved_by),
    KEY purchase_orders_creator_idx (created_by),
    CONSTRAINT purchase_orders_supplier_fk FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    CONSTRAINT purchase_orders_unit_fk     FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT purchase_orders_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT purchase_orders_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT purchase_orders_approver_fk FOREIGN KEY (approved_by)     REFERENCES users(id),
    CONSTRAINT purchase_orders_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT purchase_orders_status_chk CHECK (status IN ('draft','pending_approval','approved','sent','partially_received','received','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
