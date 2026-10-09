<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3. PRODUCT, ARTWORK, BOM, ROUTING, TOOLING
 *
 * Plates, screens, dies and moulds. A mould carries its cavity count, and any tool may be
 * owned by a customer rather than the factory, in which case its cost is theirs and it
 * leaves with them. A tool that is also carried as stock points at its item.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tools')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE tools (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_spec_id   BIGINT UNSIGNED,
    item_id           BIGINT UNSIGNED,
    kind              VARCHAR(20) NOT NULL,
    code              VARCHAR(40) NOT NULL,
    colour_index      SMALLINT UNSIGNED,
    cavity_count      SMALLINT UNSIGNED,
    owner_customer_id BIGINT UNSIGNED,
    location          VARCHAR(80),
    made_on           DATE,
    cost              DECIMAL(18,4) NOT NULL DEFAULT 0,
    life_impressions  BIGINT UNSIGNED,
    used_impressions  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status            VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY tools_code_uq (code),
    KEY tools_spec_idx (product_spec_id, status),
    KEY tools_item_idx (item_id),
    KEY tools_owner_idx (owner_customer_id),
    CONSTRAINT tools_spec_fk    FOREIGN KEY (product_spec_id)   REFERENCES product_specs(id),
    CONSTRAINT tools_item_fk    FOREIGN KEY (item_id)           REFERENCES items(id),
    CONSTRAINT tools_owner_fk   FOREIGN KEY (owner_customer_id) REFERENCES customers(id),
    CONSTRAINT tools_kind_chk   CHECK (kind IN ('flexo_plate','screen','offset_plate','cutting_die','embossing_die','cad_pattern','mould')),
    CONSTRAINT tools_status_chk CHECK (status IN ('in_production','available','in_use','worn','scrapped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tools');
    }
};
