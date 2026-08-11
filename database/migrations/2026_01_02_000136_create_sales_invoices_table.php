<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 13. RECEIVABLES / PAYABLES (SUBLEDGER)
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_invoices')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_invoices (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    customer_id         BIGINT UNSIGNED NOT NULL,
    sales_order_id      BIGINT UNSIGNED,
    delivery_challan_id BIGINT UNSIGNED,
    invoice_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    due_date            DATE,
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    subtotal            DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    received_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    lc_no               VARCHAR(60),
    mushak_no           VARCHAR(60),                       -- VAT challan (Bangladesh)
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY sales_invoices_number_uq (number),
    KEY sales_invoices_outstanding_idx (status, customer_id, due_date),
    KEY sales_invoices_order_idx (sales_order_id),
    KEY sales_invoices_challan_idx (delivery_challan_id),
    KEY sales_invoices_currency_idx (currency_id),
    KEY sales_invoices_creator_idx (created_by),
    CONSTRAINT sales_invoices_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT sales_invoices_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT sales_invoices_challan_fk  FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT sales_invoices_currency_fk FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT sales_invoices_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT sales_invoices_status_chk CHECK (status IN ('draft','issued','partially_paid','paid','overdue','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoices');
    }
};
