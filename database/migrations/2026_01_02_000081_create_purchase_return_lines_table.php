<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 6. PROCUREMENT
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_return_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_return_lines (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id BIGINT UNSIGNED NOT NULL,
    line_no            SMALLINT UNSIGNED NOT NULL,
    item_id            BIGINT UNSIGNED NOT NULL,
    lot_id             BIGINT UNSIGNED,          -- FK added in §7 (circular)
    qty                DECIMAL(18,6) NOT NULL,
    rate               DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY purchase_return_lines_uq (purchase_return_id, line_no),
    KEY purchase_return_lines_item_idx (item_id),
    KEY purchase_return_lines_lot_idx (lot_id),
    CONSTRAINT purchase_return_lines_ret_fk  FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
    CONSTRAINT purchase_return_lines_item_fk FOREIGN KEY (item_id)            REFERENCES items(id),
    CONSTRAINT purchase_return_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
    }
};
