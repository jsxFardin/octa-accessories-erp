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
        if (Schema::hasTable('payment_terms')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE payment_terms (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20) NOT NULL,
    name       VARCHAR(80) NOT NULL,
    net_days   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_lc      BOOLEAN NOT NULL DEFAULT FALSE,
    is_advance BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY payment_terms_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
