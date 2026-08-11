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
        if (Schema::hasTable('items')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE items (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_category_id    BIGINT UNSIGNED NOT NULL,
    code                VARCHAR(40)  NOT NULL,
    name                VARCHAR(180) NOT NULL,
    description         VARCHAR(500),
    base_uom_id         BIGINT UNSIGNED NOT NULL,
    purchase_uom_id     BIGINT UNSIGNED,
    default_supplier_id BIGINT UNSIGNED,
    min_order_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    order_multiple      DECIMAL(18,6) NOT NULL DEFAULT 1,
    reorder_level       DECIMAL(18,6) NOT NULL DEFAULT 0,
    safety_days         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    std_rate            DECIMAL(18,4) NOT NULL DEFAULT 0,
    avg_rate            DECIMAL(18,4) NOT NULL DEFAULT 0,
    density             DECIMAL(18,6),
    gsm                 DECIMAL(9,3),
    ink_lay_gsm         DECIMAL(9,3),
    shade_code          VARCHAR(40),
    is_lot_tracked      BOOLEAN NOT NULL DEFAULT TRUE,
    is_shade_critical   BOOLEAN NOT NULL DEFAULT FALSE,
    has_expiry          BOOLEAN NOT NULL DEFAULT FALSE,
    shelf_life_days     SMALLINT UNSIGNED,
    attributes          JSON NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at          DATETIME(3),
    UNIQUE KEY items_code_uq (code),
    KEY items_category_idx (item_category_id, is_active),
    KEY items_base_uom_idx (base_uom_id),
    KEY items_purchase_uom_idx (purchase_uom_id),
    KEY items_supplier_idx (default_supplier_id),
    CONSTRAINT items_category_fk FOREIGN KEY (item_category_id)    REFERENCES item_categories(id),
    CONSTRAINT items_base_uom_fk FOREIGN KEY (base_uom_id)         REFERENCES uoms(id),
    CONSTRAINT items_pur_uom_fk  FOREIGN KEY (purchase_uom_id)     REFERENCES uoms(id),
    CONSTRAINT items_supplier_fk FOREIGN KEY (default_supplier_id) REFERENCES suppliers(id),
    CONSTRAINT items_multiple_chk CHECK (order_multiple > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
