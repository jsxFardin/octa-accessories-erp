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
        if (Schema::hasTable('bom_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE bom_lines (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bom_id       BIGINT UNSIGNED NOT NULL,
    item_id      BIGINT UNSIGNED NOT NULL,
    uom_id       BIGINT UNSIGNED NOT NULL,
    qty_per_base DECIMAL(18,6) NOT NULL,
    wastage_pct  DECIMAL(9,4)  NOT NULL DEFAULT 0,
    colour_index SMALLINT UNSIGNED,
    is_optional  BOOLEAN NOT NULL DEFAULT FALSE,
    formula_ref  VARCHAR(20),
    notes        VARCHAR(255),
    colour_key   SMALLINT UNSIGNED GENERATED ALWAYS AS (IFNULL(colour_index, 0)) STORED,
    UNIQUE KEY bom_lines_uq (bom_id, item_id, colour_key),
    KEY bom_lines_item_idx (item_id),
    KEY bom_lines_uom_idx (uom_id),
    CONSTRAINT bom_lines_bom_fk  FOREIGN KEY (bom_id)  REFERENCES boms(id) ON DELETE CASCADE,
    CONSTRAINT bom_lines_item_fk FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT bom_lines_uom_fk  FOREIGN KEY (uom_id)  REFERENCES uoms(id),
    CONSTRAINT bom_lines_qty_chk CHECK (qty_per_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
    }
};
