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
        if (Schema::hasTable('receipts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE receipts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    customer_id      BIGINT UNSIGNED NOT NULL,
    receipt_date     DATE NOT NULL DEFAULT (CURRENT_DATE),
    method           VARCHAR(20) NOT NULL,
    reference_no     VARCHAR(80),
    bank_name        VARCHAR(120),
    currency_id      BIGINT UNSIGNED NOT NULL,
    exchange_rate    DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount           DECIMAL(18,4) NOT NULL,
    allocated_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    remarks          VARCHAR(500),
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by       BIGINT UNSIGNED,
    UNIQUE KEY receipts_number_uq (number),
    KEY receipts_customer_idx (customer_id, receipt_date),
    KEY receipts_currency_idx (currency_id),
    KEY receipts_creator_idx (created_by),
    CONSTRAINT receipts_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT receipts_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT receipts_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT receipts_method_chk CHECK (method IN ('cash','cheque','bank_transfer','lc','adjustment')),
    CONSTRAINT receipts_status_chk CHECK (status IN ('draft','posted','bounced','cancelled')),
    CONSTRAINT receipts_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
