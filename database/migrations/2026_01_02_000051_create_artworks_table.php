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
        if (Schema::hasTable('artworks')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE artworks (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id  BIGINT UNSIGNED NOT NULL,
    code        VARCHAR(40)  NOT NULL,
    title       VARCHAR(180) NOT NULL,
    designer_id BIGINT UNSIGNED,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY artworks_code_uq (code),
    KEY artworks_product_idx (product_id),
    KEY artworks_designer_idx (designer_id),
    CONSTRAINT artworks_product_fk  FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT artworks_designer_fk FOREIGN KEY (designer_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('artworks');
    }
};
