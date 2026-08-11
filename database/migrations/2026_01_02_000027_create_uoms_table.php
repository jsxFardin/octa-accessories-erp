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
        if (Schema::hasTable('uoms')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE uoms (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20) NOT NULL,
    name      VARCHAR(60) NOT NULL,
    dimension VARCHAR(20) NOT NULL,
    UNIQUE KEY uoms_code_uq (code),
    CONSTRAINT uoms_dimension_chk CHECK (dimension IN ('length','mass','area','volume','count','time'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('uoms');
    }
};
