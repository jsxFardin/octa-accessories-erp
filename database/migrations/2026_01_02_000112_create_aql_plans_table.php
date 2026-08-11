<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10. QUALITY & LABORATORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('aql_plans')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE aql_plans (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    standard         VARCHAR(30) NOT NULL DEFAULT 'ISO 2859-1',
    inspection_level VARCHAR(10) NOT NULL DEFAULT 'II',
    aql_value        DECIMAL(5,2) NOT NULL DEFAULT 2.5,
    lot_size_from    BIGINT UNSIGNED NOT NULL,
    lot_size_to      BIGINT UNSIGNED NOT NULL,
    sample_size      INT UNSIGNED NOT NULL,
    accept_number    INT UNSIGNED NOT NULL,
    reject_number    INT UNSIGNED NOT NULL,
    UNIQUE KEY aql_plans_uq (standard, inspection_level, aql_value, lot_size_from),
    CONSTRAINT aql_plans_range_chk  CHECK (lot_size_to >= lot_size_from),
    CONSTRAINT aql_plans_sample_chk CHECK (sample_size > 0),
    CONSTRAINT aql_plans_number_chk CHECK (reject_number > accept_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('aql_plans');
    }
};
