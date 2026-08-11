<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. PLATFORM
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE audit_logs (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED,
    auditable_type VARCHAR(120) NOT NULL,
    auditable_id   BIGINT UNSIGNED NOT NULL,
    event          VARCHAR(30)  NOT NULL,
    old_values     JSON,
    new_values     JSON,
    ip_address     VARCHAR(45),
    user_agent     VARCHAR(255),
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY audit_logs_auditable_idx (auditable_type, auditable_id, created_at),
    KEY audit_logs_user_idx (user_id, created_at),
    CONSTRAINT audit_logs_user_fk  FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT audit_logs_event_chk CHECK (event IN ('created','updated','deleted','restored','status_changed','printed','exported','imported'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
