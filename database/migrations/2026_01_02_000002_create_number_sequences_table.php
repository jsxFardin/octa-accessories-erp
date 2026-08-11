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
        if (Schema::hasTable('number_sequences')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE number_sequences (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_type  VARCHAR(60) NOT NULL,
    series_key     VARCHAR(20) NOT NULL,
    prefix         VARCHAR(20) NOT NULL,
    next_number    BIGINT UNSIGNED NOT NULL DEFAULT 1,
    padding        TINYINT UNSIGNED NOT NULL DEFAULT 5,
    UNIQUE KEY number_sequences_uq (document_type, series_key),
    CONSTRAINT number_sequences_next_chk    CHECK (next_number > 0),
    CONSTRAINT number_sequences_padding_chk CHECK (padding BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
