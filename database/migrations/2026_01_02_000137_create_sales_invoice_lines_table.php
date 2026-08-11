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
        if (Schema::hasTable('sales_invoice_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_invoice_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_invoice_id    BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED,
    description         VARCHAR(255) NOT NULL,
    qty                 DECIMAL(18,6) NOT NULL,
    rate_per_m          DECIMAL(18,4) NOT NULL,
    tax_id              BIGINT UNSIGNED,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    amount              DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY sales_invoice_lines_uq (sales_invoice_id, line_no),
    KEY sales_invoice_lines_soline_idx (sales_order_line_id),
    KEY sales_invoice_lines_product_idx (product_id),
    KEY sales_invoice_lines_tax_idx (tax_id),
    CONSTRAINT sales_invoice_lines_invoice_fk FOREIGN KEY (sales_invoice_id)    REFERENCES sales_invoices(id) ON DELETE CASCADE,
    CONSTRAINT sales_invoice_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT sales_invoice_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT sales_invoice_lines_tax_fk     FOREIGN KEY (tax_id)              REFERENCES taxes(id),
    CONSTRAINT sales_invoice_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_lines');
    }
};
