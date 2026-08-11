<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3. PRODUCT, ARTWORK, BOM, ROUTING, TOOLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('routing_operations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE routing_operations (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    routing_id        BIGINT UNSIGNED NOT NULL,
    sequence_no       SMALLINT UNSIGNED NOT NULL,
    code              VARCHAR(30)  NOT NULL,
    name              VARCHAR(120) NOT NULL,
    machine_group_id  BIGINT UNSIGNED,
    department_id     BIGINT UNSIGNED,
    std_rate_per_hour DECIMAL(18,6),
    setup_minutes     DECIMAL(9,2)  NOT NULL DEFAULT 0,
    setup_qty         DECIMAL(18,6) NOT NULL DEFAULT 0,
    wastage_pct       DECIMAL(9,4)  NOT NULL DEFAULT 0,
    manning_level     DECIMAL(9,4)  NOT NULL DEFAULT 1,
    consumes_web      BOOLEAN NOT NULL DEFAULT TRUE,
    allow_parallel    BOOLEAN NOT NULL DEFAULT FALSE,
    requires_qc       BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY routing_operations_uq (routing_id, sequence_no),
    KEY routing_operations_group_idx (machine_group_id),
    KEY routing_operations_dept_idx (department_id),
    CONSTRAINT routing_operations_routing_fk FOREIGN KEY (routing_id)        REFERENCES routings(id) ON DELETE CASCADE,
    CONSTRAINT routing_operations_group_fk   FOREIGN KEY (machine_group_id)  REFERENCES machine_groups(id),
    CONSTRAINT routing_operations_dept_fk    FOREIGN KEY (department_id)     REFERENCES departments(id),
    CONSTRAINT routing_operations_seq_chk     CHECK (sequence_no > 0),
    CONSTRAINT routing_operations_wastage_chk CHECK (wastage_pct >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
    }
};
