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
        if (Schema::hasTable('supplier_quotations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_quotations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rfq_id         BIGINT UNSIGNED,
    supplier_id    BIGINT UNSIGNED NOT NULL,
    quoted_on      DATE NOT NULL DEFAULT (CURRENT_DATE),
    valid_until    DATE,
    currency_id    BIGINT UNSIGNED NOT NULL,
    total          DECIMAL(18,4) NOT NULL DEFAULT 0,
    lead_time_days SMALLINT UNSIGNED,
    is_selected    BOOLEAN NOT NULL DEFAULT FALSE,
    remarks        VARCHAR(500),
    KEY supplier_quotations_rfq_idx (rfq_id),
    KEY supplier_quotations_supplier_idx (supplier_id),
    KEY supplier_quotations_currency_idx (currency_id),
    CONSTRAINT supplier_quotations_rfq_fk      FOREIGN KEY (rfq_id)      REFERENCES supplier_rfqs(id),
    CONSTRAINT supplier_quotations_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT supplier_quotations_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotations');
    }
};
