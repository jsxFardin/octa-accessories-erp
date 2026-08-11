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
        if (Schema::hasTable('quotations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE quotations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    revision_no     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    inquiry_id      BIGINT UNSIGNED,
    customer_id     BIGINT UNSIGNED NOT NULL,
    quotation_date  DATE NOT NULL DEFAULT (CURRENT_DATE),
    valid_until     DATE,
    currency_id     BIGINT UNSIGNED NOT NULL,
    exchange_rate   DECIMAL(18,8) NOT NULL DEFAULT 1,
    payment_term_id BIGINT UNSIGNED,
    merchandiser_id BIGINT UNSIGNED,
    subtotal        DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_amount      DECIMAL(18,4) NOT NULL DEFAULT 0,
    total           DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    sent_at         DATETIME(3),
    decided_at      DATETIME(3),
    reject_reason   VARCHAR(500),
    terms           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY quotations_number_uq (number, revision_no),
    KEY quotations_open_idx (status, customer_id, quotation_date),
    KEY quotations_inquiry_idx (inquiry_id),
    KEY quotations_currency_idx (currency_id),
    KEY quotations_term_idx (payment_term_id),
    KEY quotations_merch_idx (merchandiser_id),
    KEY quotations_creator_idx (created_by),
    CONSTRAINT quotations_inquiry_fk  FOREIGN KEY (inquiry_id)      REFERENCES inquiries(id),
    CONSTRAINT quotations_customer_fk FOREIGN KEY (customer_id)     REFERENCES customers(id),
    CONSTRAINT quotations_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT quotations_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT quotations_merch_fk    FOREIGN KEY (merchandiser_id) REFERENCES employees(id),
    CONSTRAINT quotations_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT quotations_status_chk CHECK (status IN ('draft','sent','accepted','rejected','expired','revised','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
