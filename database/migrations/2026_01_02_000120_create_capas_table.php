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
        if (Schema::hasTable('capas')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE capas (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ncr_id         BIGINT UNSIGNED NOT NULL,
    kind           VARCHAR(20) NOT NULL,
    root_cause     TEXT,
    action         TEXT NOT NULL,
    responsible_id BIGINT UNSIGNED,
    due_date       DATE,
    completed_on   DATE,
    effectiveness  VARCHAR(20),
    status         VARCHAR(20) NOT NULL DEFAULT 'open',
    KEY capas_ncr_idx (ncr_id),
    KEY capas_owner_idx (responsible_id),
    CONSTRAINT capas_ncr_fk   FOREIGN KEY (ncr_id)         REFERENCES ncrs(id) ON DELETE CASCADE,
    CONSTRAINT capas_owner_fk FOREIGN KEY (responsible_id) REFERENCES users(id),
    CONSTRAINT capas_kind_chk   CHECK (kind IN ('corrective','preventive')),
    CONSTRAINT capas_eff_chk    CHECK (effectiveness IS NULL OR effectiveness IN ('effective','not_effective','pending_review')),
    CONSTRAINT capas_status_chk CHECK (status IN ('open','in_progress','completed','verified'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('capas');
    }
};
