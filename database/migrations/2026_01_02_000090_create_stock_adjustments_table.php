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
        if (Schema::hasTable('stock_adjustments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_adjustments (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    warehouse_id BIGINT UNSIGNED NOT NULL,
    adjusted_on  DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason       VARCHAR(500) NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'draft',
    approved_by  BIGINT UNSIGNED,
    created_by   BIGINT UNSIGNED,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY stock_adjustments_number_uq (number),
    KEY stock_adjustments_warehouse_idx (warehouse_id),
    KEY stock_adjustments_approver_idx (approved_by),
    KEY stock_adjustments_creator_idx (created_by),
    CONSTRAINT stock_adjustments_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_adjustments_approver_fk  FOREIGN KEY (approved_by)  REFERENCES users(id),
    CONSTRAINT stock_adjustments_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT stock_adjustments_status_chk CHECK (status IN ('draft','pending_approval','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
