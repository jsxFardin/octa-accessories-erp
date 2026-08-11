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
        if (Schema::hasTable('products')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE products (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id          BIGINT UNSIGNED NOT NULL,
    brand_id             BIGINT UNSIGNED,
    routing_id           BIGINT UNSIGNED,
    code                 VARCHAR(40)  NOT NULL,
    customer_style_ref   VARCHAR(80),
    name                 VARCHAR(180) NOT NULL,
    product_type         VARCHAR(20)  NOT NULL,
    is_running_programme BOOLEAN NOT NULL DEFAULT FALSE,
    annual_forecast_qty  DECIMAL(18,6),
    status               VARCHAR(20)  NOT NULL DEFAULT 'development',
    is_active            BOOLEAN NOT NULL DEFAULT TRUE,
    created_at           DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by           BIGINT UNSIGNED,
    deleted_at           DATETIME(3),
    UNIQUE KEY products_code_uq (code),
    KEY products_customer_idx (customer_id, is_active),
    KEY products_brand_idx (brand_id),
    KEY products_routing_idx (routing_id),
    KEY products_creator_idx (created_by),
    CONSTRAINT products_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT products_brand_fk    FOREIGN KEY (brand_id)    REFERENCES brands(id),
    CONSTRAINT products_routing_fk  FOREIGN KEY (routing_id)  REFERENCES routings(id),
    CONSTRAINT products_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT products_type_fk   FOREIGN KEY (product_type) REFERENCES product_types(code),
    CONSTRAINT products_status_fk FOREIGN KEY (status)       REFERENCES product_statuses(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
