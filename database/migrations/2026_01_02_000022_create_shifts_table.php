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
        if (Schema::hasTable('shifts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE shifts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(80)  NOT NULL,
    starts_at       TIME NOT NULL,
    ends_at         TIME NOT NULL,
    break_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY shifts_uq (factory_unit_id, code),
    CONSTRAINT shifts_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
