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
        if (Schema::hasTable('permissions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE permissions (
    id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(120) NOT NULL,
    module VARCHAR(60)  NOT NULL,
    label  VARCHAR(150) NOT NULL,
    UNIQUE KEY permissions_name_uq (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
