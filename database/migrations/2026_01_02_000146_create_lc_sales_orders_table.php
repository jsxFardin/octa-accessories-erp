<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 16. TRADE — export side
 *
 * One export credit commonly covers several sales orders from the same customer; the twin of
 * `lc_purchase_orders` (BR-56 — same currency as the credit).
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lc_sales_orders')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE lc_sales_orders (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lc_id          BIGINT UNSIGNED NOT NULL,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    covered_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY lc_sales_orders_uq (lc_id, sales_order_id),
    KEY lc_sales_orders_order_idx (sales_order_id),
    CONSTRAINT lc_sales_orders_lc_fk    FOREIGN KEY (lc_id)          REFERENCES letters_of_credit(id) ON DELETE CASCADE,
    CONSTRAINT lc_sales_orders_order_fk FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_sales_orders');
    }
};
