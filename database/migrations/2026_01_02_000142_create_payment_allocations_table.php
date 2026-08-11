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
        if (Schema::hasTable('payment_allocations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE payment_allocations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id       BIGINT UNSIGNED NOT NULL,
    supplier_bill_id BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    UNIQUE KEY payment_allocations_uq (payment_id, supplier_bill_id),
    KEY payment_allocations_bill_idx (supplier_bill_id),
    CONSTRAINT payment_allocations_payment_fk FOREIGN KEY (payment_id)       REFERENCES payments(id) ON DELETE CASCADE,
    CONSTRAINT payment_allocations_bill_fk    FOREIGN KEY (supplier_bill_id) REFERENCES supplier_bills(id),
    CONSTRAINT payment_allocations_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
