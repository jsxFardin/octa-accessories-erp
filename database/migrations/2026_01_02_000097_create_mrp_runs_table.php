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
        if (Schema::hasTable('mrp_runs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE mrp_runs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    horizon_from    DATE NOT NULL,
    horizon_to      DATE NOT NULL,
    run_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    run_by          BIGINT UNSIGNED,
    status          VARCHAR(20) NOT NULL DEFAULT 'running',
    shortage_count  INT UNSIGNED NOT NULL DEFAULT 0,
    notes           VARCHAR(500),
    KEY mrp_runs_unit_idx (factory_unit_id, run_at),
    KEY mrp_runs_user_idx (run_by),
    CONSTRAINT mrp_runs_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT mrp_runs_user_fk FOREIGN KEY (run_by)          REFERENCES users(id),
    CONSTRAINT mrp_runs_status_chk CHECK (status IN ('running','completed','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_runs');
    }
};
