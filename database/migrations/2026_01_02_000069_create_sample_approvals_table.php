<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5. SAMPLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sample_approvals')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sample_approvals (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_line_id BIGINT UNSIGNED NOT NULL,
    decision               VARCHAR(30) NOT NULL,
    decided_on             DATE NOT NULL DEFAULT (CURRENT_DATE),
    customer_ref           VARCHAR(180),
    comments               TEXT,
    recorded_by            BIGINT UNSIGNED,
    created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY sample_approvals_line_idx (sample_request_line_id),
    KEY sample_approvals_user_idx (recorded_by),
    CONSTRAINT sample_approvals_line_fk FOREIGN KEY (sample_request_line_id) REFERENCES sample_request_lines(id) ON DELETE CASCADE,
    CONSTRAINT sample_approvals_user_fk FOREIGN KEY (recorded_by)            REFERENCES users(id),
    CONSTRAINT sample_approvals_decision_chk CHECK (decision IN ('approved','approved_with_comments','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_approvals');
    }
};
