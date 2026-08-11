<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4. CRM & SALES
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_orders')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    revision_no         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    quotation_id        BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    customer_po_no      VARCHAR(80),
    order_date          DATE NOT NULL DEFAULT (CURRENT_DATE),
    delivery_date       DATE,
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id     BIGINT UNSIGNED,
    billing_address_id  BIGINT UNSIGNED,
    delivery_address_id BIGINT UNSIGNED,
    merchandiser_id     BIGINT UNSIGNED,
    factory_unit_id     BIGINT UNSIGNED,
    subtotal            DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    priority            VARCHAR(10) NOT NULL DEFAULT 'normal',
    status              VARCHAR(25) NOT NULL DEFAULT 'draft',
    confirmed_at        DATETIME(3),
    closed_at           DATETIME(3),
    close_reason        VARCHAR(255),
    notes               TEXT,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY sales_orders_number_uq (number),
    KEY sales_orders_open_idx (status, customer_id, delivery_date),
    KEY sales_orders_quotation_idx (quotation_id),
    KEY sales_orders_currency_idx (currency_id),
    KEY sales_orders_term_idx (payment_term_id),
    KEY sales_orders_billto_idx (billing_address_id),
    KEY sales_orders_shipto_idx (delivery_address_id),
    KEY sales_orders_merch_idx (merchandiser_id),
    KEY sales_orders_unit_idx (factory_unit_id),
    KEY sales_orders_creator_idx (created_by),
    CONSTRAINT sales_orders_quotation_fk FOREIGN KEY (quotation_id)        REFERENCES quotations(id),
    CONSTRAINT sales_orders_customer_fk  FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT sales_orders_currency_fk  FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT sales_orders_term_fk      FOREIGN KEY (payment_term_id)     REFERENCES payment_terms(id),
    CONSTRAINT sales_orders_billto_fk    FOREIGN KEY (billing_address_id)  REFERENCES customer_addresses(id),
    CONSTRAINT sales_orders_shipto_fk    FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT sales_orders_merch_fk     FOREIGN KEY (merchandiser_id)     REFERENCES employees(id),
    CONSTRAINT sales_orders_unit_fk      FOREIGN KEY (factory_unit_id)     REFERENCES factory_units(id),
    CONSTRAINT sales_orders_creator_fk   FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT sales_orders_priority_fk FOREIGN KEY (priority) REFERENCES order_priorities(code),
    CONSTRAINT sales_orders_status_chk   CHECK (status IN ('draft','credit_hold','confirmed','in_production','partially_delivered','delivered','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
