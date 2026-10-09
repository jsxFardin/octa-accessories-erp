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
        if (Schema::hasTable('routings')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE routings (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                 VARCHAR(30)  NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    production_family_id BIGINT UNSIGNED,            -- the family whose process this is (spec §2)
    product_type         VARCHAR(20),                -- label routings also name the label type
    max_lot_size         DECIMAL(18,6),
    is_default           BOOLEAN NOT NULL DEFAULT FALSE,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY routings_code_uq (code),
    KEY routings_family_idx (production_family_id),
    CONSTRAINT routings_type_fk   FOREIGN KEY (product_type)         REFERENCES product_types(code),
    CONSTRAINT routings_family_fk FOREIGN KEY (production_family_id) REFERENCES production_families(id),
    CONSTRAINT routings_scope_chk CHECK (production_family_id IS NOT NULL OR product_type IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('routings');
    }
};
