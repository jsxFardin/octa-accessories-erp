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
        if (Schema::hasTable('defects')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE defects (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20)  NOT NULL,
    name      VARCHAR(120) NOT NULL,
    process   VARCHAR(20),
    severity  VARCHAR(10)  NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY defects_code_uq (code),
    CONSTRAINT defects_process_chk  CHECK (process IS NULL OR process IN ('weaving','printing','cutting','folding','packing','material','general')),
    CONSTRAINT defects_severity_fk FOREIGN KEY (severity) REFERENCES defect_severities(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('defects');
    }
};
