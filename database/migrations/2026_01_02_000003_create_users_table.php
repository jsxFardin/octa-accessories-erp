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
        if (Schema::hasTable('users')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150) NOT NULL,
    email             VARCHAR(190) NOT NULL,
    password          VARCHAR(255) NOT NULL,
    remember_token    VARCHAR(100),
    email_verified_at DATETIME(3),
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    two_factor_secret VARCHAR(255),
    last_login_at     DATETIME(3),
    locale            VARCHAR(10) NOT NULL DEFAULT 'en',
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at        DATETIME(3),
    deleted_at        DATETIME(3),
    UNIQUE KEY users_email_uq (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
