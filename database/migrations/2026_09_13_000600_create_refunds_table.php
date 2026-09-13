<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money going back to a customer.
 *
 * The application has had no such document. `receipts` is customer money coming **in** and
 * `payments` is supplier money going **out**; neither can express paying a customer, and
 * `receipts_amount_chk` requires a positive amount, so a negative receipt is not available
 * either. Adding a direction column to `receipts` was the other option and was rejected: every
 * existing sum over receipts and `receipt_allocations` — `allocated_amount`, BR-46 exposure,
 * the receivables reports — would silently include money flowing the wrong way unless each was
 * found and filtered. A new table has no such surface.
 *
 * Shaped after `payments`, the existing money-out document: same status vocabulary, same
 * `amount > 0`, same single `created_at`. A refund answers a credit note rather than a bill,
 * so `credit_note_id` takes the place of `supplier_id` and carries the customer with it — but
 * `customer_id` is stored too, because a refund is a customer-facing document that has to be
 * listable and reconcilable per customer without joining through the note every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refunds')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE refunds (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number         VARCHAR(30),
    credit_note_id BIGINT UNSIGNED NOT NULL,
    customer_id    BIGINT UNSIGNED NOT NULL,
    currency_id    BIGINT UNSIGNED NOT NULL,
    refund_date    DATE NOT NULL DEFAULT (CURRENT_DATE),
    method         VARCHAR(20) NOT NULL,
    reference_no   VARCHAR(80),
    amount         DECIMAL(18,4) NOT NULL,
    reason         VARCHAR(500),
    status         VARCHAR(20) NOT NULL DEFAULT 'posted',
    created_by     BIGINT UNSIGNED,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY refunds_number_uq (number),
    KEY refunds_note_idx (credit_note_id),
    KEY refunds_customer_idx (customer_id, refund_date),
    KEY refunds_currency_idx (currency_id),
    KEY refunds_creator_idx (created_by),
    CONSTRAINT refunds_note_fk     FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id),
    CONSTRAINT refunds_customer_fk FOREIGN KEY (customer_id)    REFERENCES customers(id),
    CONSTRAINT refunds_currency_fk FOREIGN KEY (currency_id)    REFERENCES currencies(id),
    CONSTRAINT refunds_creator_fk  FOREIGN KEY (created_by)     REFERENCES users(id),
    CONSTRAINT refunds_method_chk CHECK (method IN ('cash','cheque','bank_transfer','adjustment')),
    CONSTRAINT refunds_status_chk CHECK (status IN ('draft','posted','cancelled')),
    CONSTRAINT refunds_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
