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
        if (Schema::hasTable('departments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE departments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            VARCHAR(30)  NOT NULL,
    UNIQUE KEY departments_uq (factory_unit_id, code),
    CONSTRAINT departments_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT departments_kind_chk CHECK (kind IN ('design','plate','screen','weaving','printing','cutting','folding','qc','lab','store','packing','dispatch','maintenance','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
