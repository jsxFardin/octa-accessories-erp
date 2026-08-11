<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE vehicles (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(40) NOT NULL,
    kind            VARCHAR(20) NOT NULL,
    capacity_kg     DECIMAL(12,3),
    is_owned        BOOLEAN NOT NULL DEFAULT TRUE,
    fitness_expiry  DATE,
    tax_expiry      DATE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY vehicles_reg_uq (registration_no),
    CONSTRAINT vehicles_kind_chk CHECK (kind IN ('van','pickup','truck','motorcycle','covered_van'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
