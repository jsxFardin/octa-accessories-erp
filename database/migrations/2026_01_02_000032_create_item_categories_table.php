<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. ORGANISATION & MASTER DATA
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_categories')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE item_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id  BIGINT UNSIGNED,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    item_class VARCHAR(20)  NOT NULL,
    UNIQUE KEY item_categories_code_uq (code),
    KEY item_categories_parent_idx (parent_id),
    CONSTRAINT item_categories_parent_fk FOREIGN KEY (parent_id) REFERENCES item_categories(id),
    CONSTRAINT item_categories_class_chk CHECK (item_class IN ('yarn','ribbon','tape','ink','chemical','paper','film','adhesive','tool_stock','packing','spare','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
    }
};
