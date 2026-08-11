<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 8. PLANNING & MRP
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_plans')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE production_plans (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    plan_from       DATE NOT NULL,
    plan_to         DATE NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by      BIGINT UNSIGNED,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY production_plans_number_uq (number),
    KEY production_plans_unit_idx (factory_unit_id, plan_from),
    KEY production_plans_creator_idx (created_by),
    CONSTRAINT production_plans_unit_fk    FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT production_plans_creator_fk FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT production_plans_status_chk CHECK (status IN ('draft','frozen','released','closed')),
    CONSTRAINT production_plans_range_chk  CHECK (plan_to >= plan_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
