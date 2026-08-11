<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4. CRM & SALES
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inquiry_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE inquiry_lines (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inquiry_id        BIGINT UNSIGNED NOT NULL,
    line_no           SMALLINT UNSIGNED NOT NULL,
    product_id        BIGINT UNSIGNED,
    description       VARCHAR(255) NOT NULL,
    product_type      VARCHAR(20),
    qty               DECIMAL(18,6) NOT NULL,
    target_rate_per_m DECIMAL(18,4),
    notes             VARCHAR(255),
    UNIQUE KEY inquiry_lines_uq (inquiry_id, line_no),
    KEY inquiry_lines_product_idx (product_id),
    CONSTRAINT inquiry_lines_inquiry_fk FOREIGN KEY (inquiry_id) REFERENCES inquiries(id) ON DELETE CASCADE,
    CONSTRAINT inquiry_lines_product_fk FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT inquiry_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT inquiry_lines_type_fk FOREIGN KEY (product_type) REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_lines');
    }
};
