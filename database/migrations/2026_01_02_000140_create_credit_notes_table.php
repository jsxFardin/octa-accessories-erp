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
        if (Schema::hasTable('credit_notes')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE credit_notes (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number           VARCHAR(30),
    customer_id      BIGINT UNSIGNED NOT NULL,
    sales_invoice_id BIGINT UNSIGNED,
    note_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason           VARCHAR(30) NOT NULL,
    ncr_id           BIGINT UNSIGNED,
    currency_id      BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    status           VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by      BIGINT UNSIGNED,
    remarks          VARCHAR(500),
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY credit_notes_number_uq (number),
    KEY credit_notes_customer_idx (customer_id, note_date),
    KEY credit_notes_invoice_idx (sales_invoice_id),
    KEY credit_notes_ncr_idx (ncr_id),
    KEY credit_notes_currency_idx (currency_id),
    KEY credit_notes_approver_idx (approved_by),
    CONSTRAINT credit_notes_customer_fk FOREIGN KEY (customer_id)      REFERENCES customers(id),
    CONSTRAINT credit_notes_invoice_fk  FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id),
    CONSTRAINT credit_notes_ncr_fk      FOREIGN KEY (ncr_id)           REFERENCES ncrs(id),
    CONSTRAINT credit_notes_currency_fk FOREIGN KEY (currency_id)      REFERENCES currencies(id),
    CONSTRAINT credit_notes_approver_fk FOREIGN KEY (approved_by)      REFERENCES users(id),
    CONSTRAINT credit_notes_reason_chk CHECK (reason IN ('quality_claim','short_delivery','rate_difference','return','discount','other')),
    CONSTRAINT credit_notes_status_chk CHECK (status IN ('draft','approved','applied','cancelled')),
    CONSTRAINT credit_notes_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
