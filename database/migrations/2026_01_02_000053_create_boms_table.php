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
        if (Schema::hasTable('boms')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE boms (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id      BIGINT UNSIGNED NOT NULL,
    product_spec_id BIGINT UNSIGNED,
    version_no      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status          VARCHAR(20) NOT NULL DEFAULT 'draft',
    base_qty        DECIMAL(18,6) NOT NULL DEFAULT 1000,
    notes           TEXT,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    active_key      BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', product_id, NULL)) STORED,
    UNIQUE KEY boms_uq (product_id, version_no),
    UNIQUE KEY boms_one_active_uq (active_key),
    KEY boms_spec_idx (product_spec_id),
    KEY boms_creator_idx (created_by),
    CONSTRAINT boms_product_fk FOREIGN KEY (product_id)      REFERENCES products(id),   -- no CASCADE: product_id feeds active_key (§15)
    CONSTRAINT boms_spec_fk    FOREIGN KEY (product_spec_id) REFERENCES product_specs(id),
    CONSTRAINT boms_creator_fk FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT boms_version_chk CHECK (version_no > 0),
    CONSTRAINT boms_status_chk  CHECK (status IN ('draft','active','superseded'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
