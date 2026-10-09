<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1a. VOCABULARIES
 *
 * The lifecycle of an item — bought or made. An item is born `draft`, becomes `active` only
 * through the activation gate (IM-1), and may be put on hold or discontinued afterwards. Only
 * a status that allows ordering may appear on a new order line; the others stay readable on
 * the documents that already used them.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_statuses')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE item_statuses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    allows_ordering BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY item_statuses_code_uq (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_statuses');
    }
};
