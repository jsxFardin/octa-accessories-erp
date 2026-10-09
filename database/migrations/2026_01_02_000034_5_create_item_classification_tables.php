<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. ORGANISATION & MASTER DATA — item classification
 *
 * A garments-accessories factory makes ten kinds of thing (narrow textile, printed label,
 * paper, zipper, fastener, cord, injection moulding, packaging, textile support, decoration),
 * and the item master classifies every item into one of them, then into a group and a
 * sub-group below it. The family also decides which specification attributes an item of it
 * must carry (`family_attributes`), so a drawcord asks for its diameter and a zipper for its
 * chain type without a release for each.
 *
 * Runs before `items`, which carries the foreign keys.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('production_families')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE production_families (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code        CHAR(2)      NOT NULL,
    name        VARCHAR(120) NOT NULL,
    code_prefix VARCHAR(4)   NOT NULL,
    requires_artwork BOOLEAN NOT NULL DEFAULT FALSE,   -- 02, 03, 08, 10: no job without an approved artwork
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY production_families_code_uq (code),
    UNIQUE KEY production_families_prefix_uq (code_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE item_groups (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    production_family_id BIGINT UNSIGNED NOT NULL,
    parent_id            BIGINT UNSIGNED,
    code                 VARCHAR(20)  NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY item_groups_uq (production_family_id, code),
    KEY item_groups_parent_idx (parent_id),
    CONSTRAINT item_groups_family_fk FOREIGN KEY (production_family_id) REFERENCES production_families(id),
    CONSTRAINT item_groups_parent_fk FOREIGN KEY (parent_id)            REFERENCES item_groups(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE family_attributes (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    production_family_id BIGINT UNSIGNED NOT NULL,
    attr_key             VARCHAR(40)  NOT NULL,
    label                VARCHAR(80)  NOT NULL,
    data_type            VARCHAR(10)  NOT NULL,
    unit                 VARCHAR(20),
    options              JSON,
    is_required          BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY family_attributes_uq (production_family_id, attr_key),
    CONSTRAINT family_attributes_family_fk FOREIGN KEY (production_family_id) REFERENCES production_families(id),
    CONSTRAINT family_attributes_type_chk  CHECK (data_type IN ('text','number','select','boolean'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('family_attributes');
        Schema::dropIfExists('item_groups');
        Schema::dropIfExists('production_families');
    }
};
