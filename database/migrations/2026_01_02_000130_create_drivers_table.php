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
        if (Schema::hasTable('drivers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE drivers (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employee_id    BIGINT UNSIGNED,
    name           VARCHAR(150) NOT NULL,
    licence_no     VARCHAR(60),
    licence_expiry DATE,
    phone          VARCHAR(30),
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    KEY drivers_employee_idx (employee_id),
    CONSTRAINT drivers_employee_fk FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
