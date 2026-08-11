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
 * Authoritative live balance from the append-only ledger (I3).
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_stock_balances`');

        DB::unprepared(<<<'SQL'
CREATE VIEW v_stock_balances AS
SELECT
    l.id                            AS lot_id,
    l.lot_no,
    l.item_id,
    l.product_id,
    l.warehouse_id,
    l.shade_code,
    l.cert_scheme,
    l.cert_claim_pct,
    COALESCE(SUM(sl.qty), 0)        AS balance_qty,
    l.received_on
FROM stock_lots l
LEFT JOIN stock_ledger sl ON sl.lot_id = l.id
GROUP BY l.id, l.lot_no, l.item_id, l.product_id, l.warehouse_id,
         l.shade_code, l.cert_scheme, l.cert_claim_pct, l.received_on
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_stock_balances`');
    }
};
