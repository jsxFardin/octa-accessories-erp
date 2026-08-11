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
        if (Schema::hasTable('buying_houses')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE buying_houses (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(150) NOT NULL,
    country   VARCHAR(60),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY buying_houses_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('buying_houses');
    }
};
