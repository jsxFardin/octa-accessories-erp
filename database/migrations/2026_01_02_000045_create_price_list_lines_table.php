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
        if (Schema::hasTable('price_list_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE price_list_lines (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    price_list_id BIGINT UNSIGNED NOT NULL,
    product_id    BIGINT UNSIGNED,
    description   VARCHAR(255),
    min_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m    DECIMAL(18,4) NOT NULL,
    KEY price_list_lines_list_idx (price_list_id),
    KEY price_list_lines_product_idx (product_id),
    CONSTRAINT price_list_lines_list_fk FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_lines');
    }
};
