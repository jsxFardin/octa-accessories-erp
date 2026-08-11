<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 14. DERIVED OBJECTS
 *
 * MySQL has no materialised views. `stock_balances` is a summary TABLE
 * maintained by the application (refreshed after posting batches and on a
 * schedule); `v_stock_balances` recomputes the same figures live from the
 * ledger and is the reconciliation reference. See 02-database-schema §4.
 *
 * Open order book with production progress.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_order_book`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_order_book AS
SELECT
    so.id          AS sales_order_id,
    so.number      AS so_number,
    so.customer_id,
    c.name         AS customer_name,
    sol.id         AS sales_order_line_id,
    sol.product_id,
    p.code         AS product_code,
    sol.ordered_qty,
    sol.produced_qty,
    sol.delivered_qty,
    sol.promised_date,
    so.status      AS order_status,
    sol.status     AS line_status,
    CASE WHEN sol.ordered_qty > 0
         THEN ROUND(sol.delivered_qty / sol.ordered_qty * 100, 2)
         ELSE 0 END AS delivered_pct
FROM sales_orders so
JOIN sales_order_lines sol ON sol.sales_order_id = so.id
JOIN customers c ON c.id = so.customer_id
JOIN products  p ON p.id = sol.product_id
WHERE so.status IN ('confirmed','in_production','partially_delivered')
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_order_book`');
    }
};
