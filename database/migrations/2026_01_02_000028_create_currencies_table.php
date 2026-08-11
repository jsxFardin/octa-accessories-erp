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
        if (Schema::hasTable('currencies')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE currencies (
    id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code     CHAR(3)     NOT NULL,
    name     VARCHAR(60) NOT NULL,
    symbol   VARCHAR(10),
    is_base  BOOLEAN NOT NULL DEFAULT FALSE,
    base_key TINYINT UNSIGNED GENERATED ALWAYS AS (IF(is_base, 1, NULL)) STORED,
    UNIQUE KEY currencies_code_uq (code),
    UNIQUE KEY currencies_one_base_uq (base_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
