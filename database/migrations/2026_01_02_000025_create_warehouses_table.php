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
        if (Schema::hasTable('warehouses')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE warehouses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    kind            VARCHAR(30)  NOT NULL,
    is_nettable     BOOLEAN NOT NULL DEFAULT TRUE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY warehouses_code_uq (code),
    KEY warehouses_unit_idx (factory_unit_id),
    CONSTRAINT warehouses_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT warehouses_kind_chk CHECK (kind IN ('raw_material','ink_chemical','tool','wip','finished_goods','packing','scrap','transit'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
