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
        if (Schema::hasTable('stock_adjustment_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_adjustment_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stock_adjustment_id BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED NOT NULL,
    qty_delta           DECIMAL(18,6) NOT NULL,
    remarks             VARCHAR(255),
    UNIQUE KEY stock_adjustment_lines_uq (stock_adjustment_id, line_no),
    KEY stock_adjustment_lines_lot_idx (lot_id),
    CONSTRAINT stock_adjustment_lines_adj_fk FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments(id) ON DELETE CASCADE,
    CONSTRAINT stock_adjustment_lines_lot_fk FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT stock_adjustment_lines_qty_chk CHECK (qty_delta <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
};
