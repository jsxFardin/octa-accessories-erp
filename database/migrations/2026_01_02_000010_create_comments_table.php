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
        if (Schema::hasTable('comments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE comments (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    commentable_type VARCHAR(120) NOT NULL,
    commentable_id   BIGINT UNSIGNED NOT NULL,
    parent_id        BIGINT UNSIGNED,
    body             TEXT NOT NULL,
    is_external      BOOLEAN NOT NULL DEFAULT FALSE,
    created_by       BIGINT UNSIGNED,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY comments_owner_idx (commentable_type, commentable_id),
    KEY comments_parent_idx (parent_id),
    CONSTRAINT comments_parent_fk FOREIGN KEY (parent_id)  REFERENCES comments(id) ON DELETE CASCADE,
    CONSTRAINT comments_user_fk   FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
