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
        if (Schema::hasTable('taxes')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE taxes (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(80)  NOT NULL,
    rate_pct  DECIMAL(9,4) NOT NULL,
    kind      VARCHAR(20)  NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY taxes_code_uq (code),
    CONSTRAINT taxes_rate_chk CHECK (rate_pct >= 0),
    CONSTRAINT taxes_kind_chk CHECK (kind IN ('vat','ait','sd','withholding'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('taxes');
    }
};
