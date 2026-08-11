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
        if (Schema::hasTable('product_specs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE product_specs (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id         BIGINT UNSIGNED NOT NULL,
    version_no         SMALLINT UNSIGNED NOT NULL,
    status             VARCHAR(20) NOT NULL DEFAULT 'draft',
    label_width_mm     DECIMAL(9,2) NOT NULL,
    label_height_mm    DECIMAL(9,2) NOT NULL,
    web_width_mm       DECIMAL(9,2),
    selvedge_mm        DECIMAL(9,2) NOT NULL DEFAULT 0,
    lane_gap_mm        DECIMAL(9,2) NOT NULL DEFAULT 0,
    cut_gap_mm         DECIMAL(9,2) NOT NULL DEFAULT 2,
    ends               SMALLINT UNSIGNED,
    base_material      VARCHAR(60),
    fabric_gsm         DECIMAL(9,3),
    warp_ratio         DECIMAL(9,4) NOT NULL DEFAULT 0.60,
    colours            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    colour_list        JSON NOT NULL,
    cut_type           VARCHAR(20),
    fold_type          VARCHAR(20),
    finish             VARCHAR(120),
    coverage_pct       DECIMAL(9,4) NOT NULL DEFAULT 40,
    bundle_size        INT UNSIGNED NOT NULL DEFAULT 500,
    bundles_per_carton INT UNSIGNED NOT NULL DEFAULT 20,
    care_symbols       JSON NOT NULL,
    fibre_composition  VARCHAR(255),
    country_of_origin  VARCHAR(60),
    claims             JSON NOT NULL,
    attributes         JSON NOT NULL,
    notes              TEXT,
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by         BIGINT UNSIGNED,
    current_key        BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'current', product_id, NULL)) STORED,
    UNIQUE KEY product_specs_uq (product_id, version_no),
    UNIQUE KEY product_specs_one_current_uq (current_key),
    KEY product_specs_creator_idx (created_by),
    CONSTRAINT product_specs_product_fk FOREIGN KEY (product_id) REFERENCES products(id),   -- no CASCADE: product_id feeds current_key (§15)
    CONSTRAINT product_specs_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT product_specs_version_chk CHECK (version_no > 0),
    CONSTRAINT product_specs_status_chk  CHECK (status IN ('draft','current','superseded')),
    CONSTRAINT product_specs_width_chk   CHECK (label_width_mm > 0),
    CONSTRAINT product_specs_height_chk  CHECK (label_height_mm > 0),
    CONSTRAINT product_specs_ends_chk    CHECK (ends IS NULL OR ends >= 1),
    CONSTRAINT product_specs_warp_chk    CHECK (warp_ratio > 0 AND warp_ratio < 1),
    CONSTRAINT product_specs_colours_chk CHECK (colours >= 1),
    CONSTRAINT product_specs_bundle_chk  CHECK (bundle_size > 0 AND bundles_per_carton > 0),
    CONSTRAINT product_specs_cut_fk      FOREIGN KEY (cut_type) REFERENCES cut_types(code),
    CONSTRAINT product_specs_fold_chk    CHECK (fold_type IS NULL OR fold_type IN ('flat','centre_fold','end_fold','loop','mitre','manhattan','book_cover'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specs');
    }
};
