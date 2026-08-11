<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7. INVENTORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_reservations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_reservations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_id       BIGINT UNSIGNED,
    item_id      BIGINT UNSIGNED NOT NULL,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    job_card_id  BIGINT UNSIGNED,                 -- FK added in §9 (circular)
    qty          DECIMAL(18,6) NOT NULL,
    reserved_on  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    released_on  DATETIME(3),
    status       VARCHAR(20) NOT NULL DEFAULT 'active',
    KEY stock_reservations_active_idx (item_id, warehouse_id, status),
    KEY stock_reservations_lot_idx (lot_id),
    KEY stock_reservations_job_idx (job_card_id),
    CONSTRAINT stock_reservations_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id),
    CONSTRAINT stock_reservations_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_reservations_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_reservations_qty_chk    CHECK (qty > 0),
    CONSTRAINT stock_reservations_status_chk CHECK (status IN ('active','consumed','released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
