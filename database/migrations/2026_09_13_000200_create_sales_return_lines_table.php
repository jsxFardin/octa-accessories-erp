<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What came back, line by line, against the invoice line it was billed on.
 *
 * `sales_invoice_line_id` is the anchor rather than the product: the returnable quantity is a
 * property of *what was billed*, not of what the customer happens to own, and a product can
 * appear on several lines of one invoice at different rates. Guarding per line is what makes
 * partial returns and repeated returns arithmetically safe.
 *
 * `rate` is snapshotted from the invoice line at approval rather than read live, for the same
 * reason a quotation snapshots its rates (Q1): the credit that follows must be worth what was
 * charged, not what the price list says months later.
 *
 * `lot_id` is nullable. A return should name the lot it came from — that is what keeps
 * genealogy intact back through the FG receipt to the GRN — but goods sometimes come back
 * without their paperwork, and refusing the return would leave the stock off the books
 * entirely. Null means "not traced", which is a fact worth recording rather than a reason to
 * lose the movement.
 *
 * Mirrors `purchase_return_lines`: same column order, same `qty > 0` check, same absence of
 * timestamps on a child row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_return_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_return_lines (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_return_id       BIGINT UNSIGNED NOT NULL,
    line_no               SMALLINT UNSIGNED NOT NULL,
    sales_invoice_line_id BIGINT UNSIGNED NOT NULL,
    product_id            BIGINT UNSIGNED,
    lot_id                BIGINT UNSIGNED,
    qty                   DECIMAL(18,6) NOT NULL,
    rate_per_m            DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY sales_return_lines_uq (sales_return_id, line_no),
    KEY sales_return_lines_invline_idx (sales_invoice_line_id),
    KEY sales_return_lines_product_idx (product_id),
    KEY sales_return_lines_lot_idx (lot_id),
    CONSTRAINT sales_return_lines_return_fk  FOREIGN KEY (sales_return_id)       REFERENCES sales_returns(id) ON DELETE CASCADE,
    CONSTRAINT sales_return_lines_invline_fk FOREIGN KEY (sales_invoice_line_id) REFERENCES sales_invoice_lines(id),
    CONSTRAINT sales_return_lines_product_fk FOREIGN KEY (product_id)            REFERENCES products(id),
    CONSTRAINT sales_return_lines_lot_fk     FOREIGN KEY (lot_id)                REFERENCES stock_lots(id),
    CONSTRAINT sales_return_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_lines');
    }
};
