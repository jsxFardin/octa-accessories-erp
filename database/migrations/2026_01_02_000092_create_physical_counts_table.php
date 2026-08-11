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
        if (Schema::hasTable('physical_counts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE physical_counts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number       VARCHAR(30),
    warehouse_id BIGINT UNSIGNED NOT NULL,
    counted_on   DATE NOT NULL DEFAULT (CURRENT_DATE),
    status       VARCHAR(20) NOT NULL DEFAULT 'open',
    created_by   BIGINT UNSIGNED,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY physical_counts_number_uq (number),
    KEY physical_counts_warehouse_idx (warehouse_id),
    KEY physical_counts_creator_idx (created_by),
    CONSTRAINT physical_counts_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT physical_counts_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT physical_counts_status_chk CHECK (status IN ('open','counting','reconciled','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_counts');
    }
};
