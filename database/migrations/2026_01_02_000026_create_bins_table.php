<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. ORGANISATION & MASTER DATA
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bins')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE bins (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT UNSIGNED NOT NULL,
    code         VARCHAR(30) NOT NULL,
    description  VARCHAR(150),
    UNIQUE KEY bins_uq (warehouse_id, code),
    CONSTRAINT bins_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bins');
    }
};
