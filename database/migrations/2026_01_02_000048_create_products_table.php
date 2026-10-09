<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3. PRODUCT, ARTWORK, BOM, ROUTING, TOOLING
 *
 * The make profile of an item: what a made item needs that a bought one does not — the
 * customer it is made for (none, for a standard item), the brand, the buyer's style
 * reference, the routing and the process type. Code, name, status and classification live
 * on `items`; a product is one item, exactly once.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE products (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id              BIGINT UNSIGNED NOT NULL,
    customer_id          BIGINT UNSIGNED,
    brand_id             BIGINT UNSIGNED,
    routing_id           BIGINT UNSIGNED,
    customer_style_ref   VARCHAR(80),
    product_type         VARCHAR(20)  NOT NULL,
    is_running_programme BOOLEAN NOT NULL DEFAULT FALSE,
    annual_forecast_qty  DECIMAL(18,6),
    created_at           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by           BIGINT UNSIGNED,
    deleted_at           DATETIME(3),
    UNIQUE KEY products_item_uq (item_id),
    KEY products_customer_idx (customer_id),
    KEY products_brand_idx (brand_id),
    KEY products_routing_idx (routing_id),
    KEY products_creator_idx (created_by),
    CONSTRAINT products_item_fk     FOREIGN KEY (item_id)     REFERENCES items(id),
    CONSTRAINT products_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT products_brand_fk    FOREIGN KEY (brand_id)    REFERENCES brands(id),
    CONSTRAINT products_routing_fk  FOREIGN KEY (routing_id)  REFERENCES routings(id),
    CONSTRAINT products_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT products_type_fk     FOREIGN KEY (product_type) REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
