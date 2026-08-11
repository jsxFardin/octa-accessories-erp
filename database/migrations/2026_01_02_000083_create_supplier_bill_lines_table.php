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
        if (Schema::hasTable('supplier_bill_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_bill_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_bill_id BIGINT UNSIGNED NOT NULL,
    line_no          SMALLINT UNSIGNED NOT NULL,
    item_id          BIGINT UNSIGNED,
    description      VARCHAR(255),
    qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate             DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id           BIGINT UNSIGNED,
    amount           DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY supplier_bill_lines_uq (supplier_bill_id, line_no),
    KEY supplier_bill_lines_item_idx (item_id),
    KEY supplier_bill_lines_tax_idx (tax_id),
    CONSTRAINT supplier_bill_lines_bill_fk FOREIGN KEY (supplier_bill_id) REFERENCES supplier_bills(id) ON DELETE CASCADE,
    CONSTRAINT supplier_bill_lines_item_fk FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT supplier_bill_lines_tax_fk  FOREIGN KEY (tax_id)           REFERENCES taxes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_lines');
    }
};
