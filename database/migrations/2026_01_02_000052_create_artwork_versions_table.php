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
        if (Schema::hasTable('artwork_versions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE artwork_versions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artwork_id       BIGINT UNSIGNED NOT NULL,
    version_no       SMALLINT UNSIGNED NOT NULL,
    status           VARCHAR(20)  NOT NULL DEFAULT 'draft',
    file_path        VARCHAR(500) NOT NULL,
    file_format      VARCHAR(10),
    preview_path     VARCHAR(500),
    checksum_sha256  CHAR(64),
    submitted_at     DATETIME(3),
    approved_at      DATETIME(3),
    approved_by      BIGINT UNSIGNED,
    customer_ref     VARCHAR(180),
    rejection_reason VARCHAR(500),
    created_by       BIGINT UNSIGNED,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    approved_key     BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'approved', artwork_id, NULL)) STORED,
    UNIQUE KEY artwork_versions_uq (artwork_id, version_no),
    UNIQUE KEY artwork_versions_one_approved_uq (approved_key),
    KEY artwork_versions_approver_idx (approved_by),
    KEY artwork_versions_creator_idx (created_by),
    CONSTRAINT artwork_versions_artwork_fk  FOREIGN KEY (artwork_id)  REFERENCES artworks(id),   -- no CASCADE: artwork_id feeds approved_key (§15)
    CONSTRAINT artwork_versions_approver_fk FOREIGN KEY (approved_by) REFERENCES users(id),
    CONSTRAINT artwork_versions_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT artwork_versions_version_chk CHECK (version_no > 0),
    CONSTRAINT artwork_versions_status_chk  CHECK (status IN ('draft','submitted','approved','rejected','superseded')),
    CONSTRAINT artwork_versions_format_chk  CHECK (file_format IS NULL OR file_format IN ('ai','eps','pdf','cdr','psd','png','jpg','svg'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_versions');
    }
};
