<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. ORGANISATION & MASTER DATA
 *
 * The item master — one table for everything the factory buys, makes, consumes, sells or
 * charges for. A made item (finished good, semi-finished, made component) also owns a
 * `products` row that carries its engineering and commercial profile; that is what lets a
 * family-01 tape be a line on a family-04 zipper's bill of materials without the planning
 * and inventory code knowing which of the two it is handling.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE items (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_category_id       BIGINT UNSIGNED NOT NULL,
    code                   VARCHAR(40)  NOT NULL,
    name                   VARCHAR(180) NOT NULL,
    description            VARCHAR(500),
    -- identity
    item_type              VARCHAR(20)  NOT NULL DEFAULT 'raw_material',
    make_or_buy            VARCHAR(4)   NOT NULL DEFAULT 'buy',
    -- classification
    production_family_id   BIGINT UNSIGNED,
    item_group_id          BIGINT UNSIGNED,
    garment_type           VARCHAR(10),
    spec_scope             VARCHAR(10)  NOT NULL DEFAULT 'standard',
    customer_id            BIGINT UNSIGNED,
    material_base          VARCHAR(10),
    variant_axes           JSON NOT NULL DEFAULT (JSON_ARRAY()),
    -- units and packing
    base_uom_id            BIGINT UNSIGNED NOT NULL,
    purchase_uom_id        BIGINT UNSIGNED,
    order_uom_id           BIGINT UNSIGNED,
    pack_pcs_per_inner     INT UNSIGNED,
    pack_inners_per_carton INT UNSIGNED,
    -- buying and stock policy
    default_supplier_id    BIGINT UNSIGNED,
    default_warehouse_id   BIGINT UNSIGNED,
    min_order_qty          DECIMAL(18,6) NOT NULL DEFAULT 0,
    order_multiple         DECIMAL(18,6) NOT NULL DEFAULT 1,
    reorder_level          DECIMAL(18,6) NOT NULL DEFAULT 0,
    max_stock_qty          DECIMAL(18,6),
    safety_days            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    std_rate               DECIMAL(18,4) NOT NULL DEFAULT 0,
    avg_rate               DECIMAL(18,4) NOT NULL DEFAULT 0,
    valuation_method       VARCHAR(20)  NOT NULL DEFAULT 'weighted_average',
    standard_wastage_pct   DECIMAL(9,4) NOT NULL DEFAULT 0,
    -- technical
    density                DECIMAL(18,6),
    gsm                    DECIMAL(9,3),
    ink_lay_gsm            DECIMAL(9,3),
    shade_code             VARCHAR(40),
    is_lot_tracked         BOOLEAN NOT NULL DEFAULT TRUE,
    is_shade_critical      BOOLEAN NOT NULL DEFAULT FALSE,
    has_expiry             BOOLEAN NOT NULL DEFAULT FALSE,
    shelf_life_days        SMALLINT UNSIGNED,
    -- by type
    tool_owner_customer_id BIGINT UNSIGNED,
    tool_cavities          SMALLINT UNSIGNED,
    service_charge_basis   VARCHAR(20),
    qc_plan_ref            VARCHAR(80),
    inventory_account      VARCHAR(40),
    cogs_account           VARCHAR(40),
    attributes             JSON NOT NULL DEFAULT (JSON_OBJECT()),
    -- lifecycle
    status                 VARCHAR(20)  NOT NULL DEFAULT 'draft',
    created_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by             BIGINT UNSIGNED,
    activated_at           DATETIME(3),
    activated_by           BIGINT UNSIGNED,
    deleted_at             DATETIME(3),
    UNIQUE KEY items_code_uq (code),
    KEY items_category_idx (item_category_id, status),
    KEY items_type_idx (item_type, status),
    KEY items_family_idx (production_family_id, item_group_id),
    KEY items_group_idx (item_group_id),
    KEY items_customer_idx (customer_id),
    KEY items_base_uom_idx (base_uom_id),
    KEY items_purchase_uom_idx (purchase_uom_id),
    KEY items_order_uom_idx (order_uom_id),
    KEY items_supplier_idx (default_supplier_id),
    KEY items_warehouse_idx (default_warehouse_id),
    KEY items_tool_owner_idx (tool_owner_customer_id),
    KEY items_status_idx (status),
    KEY items_creator_idx (created_by),
    KEY items_activator_idx (activated_by),
    CONSTRAINT items_category_fk   FOREIGN KEY (item_category_id)       REFERENCES item_categories(id),
    CONSTRAINT items_family_fk     FOREIGN KEY (production_family_id)   REFERENCES production_families(id),
    CONSTRAINT items_group_fk      FOREIGN KEY (item_group_id)          REFERENCES item_groups(id),
    CONSTRAINT items_base_uom_fk   FOREIGN KEY (base_uom_id)            REFERENCES uoms(id),
    CONSTRAINT items_pur_uom_fk    FOREIGN KEY (purchase_uom_id)        REFERENCES uoms(id),
    CONSTRAINT items_order_uom_fk  FOREIGN KEY (order_uom_id)           REFERENCES uoms(id),
    CONSTRAINT items_supplier_fk   FOREIGN KEY (default_supplier_id)    REFERENCES suppliers(id),
    CONSTRAINT items_warehouse_fk  FOREIGN KEY (default_warehouse_id)   REFERENCES warehouses(id),
    CONSTRAINT items_status_fk     FOREIGN KEY (status)                 REFERENCES item_statuses(code),
    CONSTRAINT items_creator_fk    FOREIGN KEY (created_by)             REFERENCES users(id),
    CONSTRAINT items_activator_fk  FOREIGN KEY (activated_by)           REFERENCES users(id),
    CONSTRAINT items_multiple_chk  CHECK (order_multiple > 0),
    CONSTRAINT items_type_chk      CHECK (item_type IN ('finished_good','raw_material','consumable','component','semi_finished','tool','service','packaging')),
    CONSTRAINT items_make_chk      CHECK (make_or_buy IN ('make','buy')),
    CONSTRAINT items_garment_chk   CHECK (garment_type IS NULL OR garment_type IN ('knit','woven','both')),
    CONSTRAINT items_scope_chk     CHECK (spec_scope IN ('standard','buyer')),
    CONSTRAINT items_material_chk  CHECK (material_base IS NULL OR material_base IN ('textile','plastic','metal','paper','film')),
    CONSTRAINT items_valuation_chk CHECK (valuation_method IN ('weighted_average','standard')),
    CONSTRAINT items_wastage_chk   CHECK (standard_wastage_pct >= 0),
    CONSTRAINT items_max_chk       CHECK (max_stock_qty IS NULL OR max_stock_qty >= reorder_level),
    CONSTRAINT items_charge_chk    CHECK (service_charge_basis IS NULL OR service_charge_basis IN ('per_piece','per_hour','per_lot','per_kg','per_metre')),
    CONSTRAINT items_family_chk    CHECK (make_or_buy = 'buy' OR production_family_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
