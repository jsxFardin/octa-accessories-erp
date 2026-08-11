<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 14. DERIVED OBJECTS
 *
 * MySQL has no materialised views. `stock_balances` is a summary TABLE
 * maintained by the application (refreshed after posting batches and on a
 * schedule); `v_stock_balances` recomputes the same figures live from the
 * ledger and is the reconciliation reference. See 02-database-schema §4.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_balances')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_balances (
    lot_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    item_id        BIGINT UNSIGNED,
    product_id     BIGINT UNSIGNED,
    warehouse_id   BIGINT UNSIGNED NOT NULL,
    lot_no         VARCHAR(40) NOT NULL,
    shade_code     VARCHAR(40),
    cert_scheme    VARCHAR(20),
    cert_claim_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    balance_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    received_on    DATE,
    refreshed_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY stock_balances_item_idx (item_id, warehouse_id),
    KEY stock_balances_product_idx (product_id, warehouse_id),
    KEY stock_balances_shade_idx (item_id, shade_code),
    KEY stock_balances_cert_idx (cert_scheme),
    CONSTRAINT stock_balances_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id) ON DELETE CASCADE,
    CONSTRAINT stock_balances_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_balances_product_fk   FOREIGN KEY (product_id)   REFERENCES products(id),
    CONSTRAINT stock_balances_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
