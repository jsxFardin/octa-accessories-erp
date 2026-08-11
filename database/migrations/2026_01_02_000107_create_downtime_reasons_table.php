<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9. MANUFACTURING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('downtime_reasons')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE downtime_reasons (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    category   VARCHAR(20)  NOT NULL,
    is_planned BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY downtime_reasons_code_uq (code),
    CONSTRAINT downtime_reasons_category_chk CHECK (category IN ('mechanical','electrical','material','quality','changeover','power','manpower','planned','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_reasons');
    }
};
