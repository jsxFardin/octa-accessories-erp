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
        if (Schema::hasTable('physical_count_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE physical_count_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    physical_count_id BIGINT UNSIGNED NOT NULL,
    lot_id            BIGINT UNSIGNED NOT NULL,
    system_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    counted_qty       DECIMAL(18,6),
    variance_qty      DECIMAL(18,6) GENERATED ALWAYS AS (IFNULL(counted_qty, 0) - system_qty) STORED,
    counted_by        BIGINT UNSIGNED,
    remarks           VARCHAR(255),
    UNIQUE KEY physical_count_lines_uq (physical_count_id, lot_id),
    KEY physical_count_lines_lot_idx (lot_id),
    KEY physical_count_lines_user_idx (counted_by),
    CONSTRAINT physical_count_lines_count_fk FOREIGN KEY (physical_count_id) REFERENCES physical_counts(id) ON DELETE CASCADE,
    CONSTRAINT physical_count_lines_lot_fk   FOREIGN KEY (lot_id)            REFERENCES stock_lots(id),
    CONSTRAINT physical_count_lines_user_fk  FOREIGN KEY (counted_by)        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_count_lines');
    }
};
