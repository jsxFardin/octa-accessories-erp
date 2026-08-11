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
        if (Schema::hasTable('stock_transfer_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_transfer_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stock_transfer_id BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    lot_id            BIGINT UNSIGNED NOT NULL,
    qty               DECIMAL(18,6) NOT NULL,
    received_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY stock_transfer_lines_uq (stock_transfer_id, line_no),
    KEY stock_transfer_lines_lot_idx (lot_id),
    CONSTRAINT stock_transfer_lines_transfer_fk FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT stock_transfer_lines_lot_fk      FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT stock_transfer_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
    }
};
