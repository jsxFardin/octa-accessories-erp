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
        if (Schema::hasTable('machines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE machines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    factory_unit_id   BIGINT UNSIGNED NOT NULL,
    machine_group_id  BIGINT UNSIGNED NOT NULL,
    department_id     BIGINT UNSIGNED,
    code              VARCHAR(30)  NOT NULL,
    name              VARCHAR(120) NOT NULL,
    make              VARCHAR(80),
    model             VARCHAR(80),
    serial_no         VARCHAR(80),
    commissioned_on   DATE,
    web_width_mm      DECIMAL(9,2),
    max_colours       SMALLINT UNSIGNED,
    std_rate_per_hour DECIMAL(18,6),
    hourly_rate       DECIMAL(18,4) NOT NULL DEFAULT 0,
    kw_rating         DECIMAL(9,3),
    efficiency_pct    DECIMAL(9,4)  NOT NULL DEFAULT 85,
    status            VARCHAR(20)   NOT NULL DEFAULT 'available',
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY machines_code_uq (code),
    KEY machines_group_idx (machine_group_id, is_active),
    KEY machines_unit_idx (factory_unit_id),
    KEY machines_dept_idx (department_id),
    CONSTRAINT machines_unit_fk  FOREIGN KEY (factory_unit_id)  REFERENCES factory_units(id),
    CONSTRAINT machines_group_fk FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT machines_dept_fk  FOREIGN KEY (department_id)    REFERENCES departments(id),
    CONSTRAINT machines_status_chk CHECK (status IN ('available','running','maintenance','breakdown','retired')),
    CONSTRAINT machines_eff_chk    CHECK (efficiency_pct > 0 AND efficiency_pct <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
