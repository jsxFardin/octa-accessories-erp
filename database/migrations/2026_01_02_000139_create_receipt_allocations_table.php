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
        if (Schema::hasTable('receipt_allocations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE receipt_allocations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_id       BIGINT UNSIGNED NOT NULL,
    sales_invoice_id BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    UNIQUE KEY receipt_allocations_uq (receipt_id, sales_invoice_id),
    KEY receipt_allocations_invoice_idx (sales_invoice_id),
    CONSTRAINT receipt_allocations_receipt_fk FOREIGN KEY (receipt_id)       REFERENCES receipts(id) ON DELETE CASCADE,
    CONSTRAINT receipt_allocations_invoice_fk FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id),
    CONSTRAINT receipt_allocations_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');
    }
};
