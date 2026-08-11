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
        if (Schema::hasTable('uom_conversions')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE uom_conversions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id     BIGINT UNSIGNED,                 -- NULL = global conversion
    from_uom_id BIGINT UNSIGNED NOT NULL,
    to_uom_id   BIGINT UNSIGNED NOT NULL,
    factor      DECIMAL(18,8) NOT NULL,
    item_key    BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(item_id, 0)) STORED,
    UNIQUE KEY uom_conversions_uq (item_key, from_uom_id, to_uom_id),
    KEY uom_conversions_from_idx (from_uom_id),
    KEY uom_conversions_to_idx (to_uom_id),
    KEY uom_conversions_item_idx (item_id),
    CONSTRAINT uom_conversions_item_fk FOREIGN KEY (item_id)     REFERENCES items(id),   -- no CASCADE: item_id feeds item_key (§15)
    CONSTRAINT uom_conversions_from_fk FOREIGN KEY (from_uom_id) REFERENCES uoms(id),
    CONSTRAINT uom_conversions_to_fk   FOREIGN KEY (to_uom_id)   REFERENCES uoms(id),
    CONSTRAINT uom_conversions_factor_chk CHECK (factor > 0),
    CONSTRAINT uom_conversions_diff_chk   CHECK (from_uom_id <> to_uom_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('uom_conversions');
    }
};
