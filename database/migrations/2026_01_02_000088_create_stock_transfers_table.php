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
        if (Schema::hasTable('stock_transfers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_transfers (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number            VARCHAR(30),
    from_warehouse_id BIGINT UNSIGNED NOT NULL,
    to_warehouse_id   BIGINT UNSIGNED NOT NULL,
    transfer_date     DATE NOT NULL DEFAULT (CURRENT_DATE),
    status            VARCHAR(20) NOT NULL DEFAULT 'draft',
    remarks           VARCHAR(255),
    created_by        BIGINT UNSIGNED,
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_transfers_number_uq (number),
    KEY stock_transfers_from_idx (from_warehouse_id),
    KEY stock_transfers_to_idx (to_warehouse_id),
    KEY stock_transfers_creator_idx (created_by),
    CONSTRAINT stock_transfers_from_fk    FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_transfers_to_fk      FOREIGN KEY (to_warehouse_id)   REFERENCES warehouses(id),
    CONSTRAINT stock_transfers_creator_fk FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT stock_transfers_status_chk CHECK (status IN ('draft','in_transit','received','cancelled')),
    CONSTRAINT stock_transfers_diff_chk   CHECK (from_warehouse_id <> to_warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
