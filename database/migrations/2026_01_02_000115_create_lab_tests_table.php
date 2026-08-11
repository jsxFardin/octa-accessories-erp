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
        if (Schema::hasTable('lab_tests')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE lab_tests (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(20)  NOT NULL,
    name               VARCHAR(150) NOT NULL,
    method             VARCHAR(60),
    scale              VARCHAR(20)  NOT NULL,
    default_pass_value VARCHAR(40),
    unit               VARCHAR(20),
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY lab_tests_code_uq (code),
    CONSTRAINT lab_tests_scale_chk CHECK (scale IN ('grey_1_5','percent','delta_e','pass_fail','numeric'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_tests');
    }
};
