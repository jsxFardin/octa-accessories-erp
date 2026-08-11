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
        if (Schema::hasTable('supplier_bills')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_bills (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number        VARCHAR(30),
    supplier_id   BIGINT UNSIGNED NOT NULL,
    po_id         BIGINT UNSIGNED,
    grn_id        BIGINT UNSIGNED,
    bill_no       VARCHAR(60) NOT NULL,
    bill_date     DATE NOT NULL,
    due_date      DATE,
    currency_id   BIGINT UNSIGNED NOT NULL,
    exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
    subtotal      DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount    DECIMAL(18,4) NOT NULL DEFAULT 0,
    total         DECIMAL(18,4) NOT NULL DEFAULT 0,
    paid_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    status        VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    UNIQUE KEY supplier_bills_number_uq (number),
    KEY supplier_bills_supplier_idx (supplier_id, status, due_date),
    KEY supplier_bills_po_idx (po_id),
    KEY supplier_bills_grn_idx (grn_id),
    KEY supplier_bills_currency_idx (currency_id),
    KEY supplier_bills_creator_idx (created_by),
    CONSTRAINT supplier_bills_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT supplier_bills_po_fk       FOREIGN KEY (po_id)       REFERENCES purchase_orders(id),
    CONSTRAINT supplier_bills_grn_fk      FOREIGN KEY (grn_id)      REFERENCES grns(id),
    CONSTRAINT supplier_bills_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT supplier_bills_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT supplier_bills_status_chk CHECK (status IN ('draft','approved','partially_paid','paid','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bills');
    }
};
