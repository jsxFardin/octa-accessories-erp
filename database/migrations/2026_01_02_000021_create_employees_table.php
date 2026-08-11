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
        if (Schema::hasTable('employees')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE employees (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED,
    factory_unit_id BIGINT UNSIGNED NOT NULL,
    department_id   BIGINT UNSIGNED,
    code            VARCHAR(30)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    designation     VARCHAR(120),
    phone           VARCHAR(30),
    card_no         VARCHAR(40),
    skill_grade     VARCHAR(10),
    joined_on       DATE,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY employees_user_uq (user_id),
    UNIQUE KEY employees_code_uq (code),
    UNIQUE KEY employees_card_uq (card_no),
    KEY employees_dept_idx (department_id),
    CONSTRAINT employees_user_fk FOREIGN KEY (user_id)         REFERENCES users(id),
    CONSTRAINT employees_unit_fk FOREIGN KEY (factory_unit_id) REFERENCES factory_units(id),
    CONSTRAINT employees_dept_fk FOREIGN KEY (department_id)   REFERENCES departments(id),
    CONSTRAINT employees_grade_chk CHECK (skill_grade IS NULL OR skill_grade IN ('A','B','C','trainee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
