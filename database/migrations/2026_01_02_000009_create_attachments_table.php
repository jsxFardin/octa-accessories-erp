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
        if (Schema::hasTable('attachments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE attachments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachable_type VARCHAR(120) NOT NULL,
    attachable_id   BIGINT UNSIGNED NOT NULL,
    collection      VARCHAR(60)  NOT NULL DEFAULT 'default',
    disk            VARCHAR(40)  NOT NULL DEFAULT 'local',
    path            VARCHAR(500) NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(120),
    size_bytes      BIGINT UNSIGNED,
    checksum_sha256 CHAR(64),
    uploaded_by     BIGINT UNSIGNED,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY attachments_owner_idx (attachable_type, attachable_id),
    CONSTRAINT attachments_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
