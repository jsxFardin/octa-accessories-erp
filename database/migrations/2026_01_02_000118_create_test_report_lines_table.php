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
        if (Schema::hasTable('test_report_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE test_report_lines (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    test_report_id BIGINT UNSIGNED NOT NULL,
    lab_test_id    BIGINT UNSIGNED NOT NULL,
    result_value   VARCHAR(40) NOT NULL,
    pass_value     VARCHAR(40),
    result         VARCHAR(10) NOT NULL,
    remarks        VARCHAR(255),
    UNIQUE KEY test_report_lines_uq (test_report_id, lab_test_id),
    KEY test_report_lines_test_idx (lab_test_id),
    CONSTRAINT test_report_lines_report_fk FOREIGN KEY (test_report_id) REFERENCES test_reports(id) ON DELETE CASCADE,
    CONSTRAINT test_report_lines_test_fk   FOREIGN KEY (lab_test_id)    REFERENCES lab_tests(id),
    CONSTRAINT test_report_lines_result_chk CHECK (result IN ('pass','fail','na'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('test_report_lines');
    }
};
