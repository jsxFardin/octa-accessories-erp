<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4. CRM & SALES
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_order_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sales_order_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_id      BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    product_id          BIGINT UNSIGNED NOT NULL,
    product_spec_id     BIGINT UNSIGNED NOT NULL,
    artwork_version_id  BIGINT UNSIGNED,
    description         VARCHAR(255),
    ordered_qty         DECIMAL(18,6) NOT NULL,
    produced_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    delivered_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    invoiced_qty        DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m          DECIMAL(18,4) NOT NULL,
    tooling_charge      DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id              BIGINT UNSIGNED,
    line_total          DECIMAL(18,4) NOT NULL DEFAULT 0,
    over_tolerance_pct  DECIMAL(9,4) NOT NULL DEFAULT 5,
    under_tolerance_pct DECIMAL(9,4) NOT NULL DEFAULT 5,
    promised_date       DATE,
    status              VARCHAR(20) NOT NULL DEFAULT 'open',
    UNIQUE KEY sales_order_lines_uq (sales_order_id, line_no),
    KEY sales_order_lines_product_idx (product_id),
    KEY sales_order_lines_spec_idx (product_spec_id),
    KEY sales_order_lines_artwork_idx (artwork_version_id),
    KEY sales_order_lines_tax_idx (tax_id),
    CONSTRAINT sales_order_lines_order_fk   FOREIGN KEY (sales_order_id)     REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT sales_order_lines_product_fk FOREIGN KEY (product_id)         REFERENCES products(id),
    CONSTRAINT sales_order_lines_spec_fk    FOREIGN KEY (product_spec_id)    REFERENCES product_specs(id),
    CONSTRAINT sales_order_lines_artwork_fk FOREIGN KEY (artwork_version_id) REFERENCES artwork_versions(id),
    CONSTRAINT sales_order_lines_tax_fk     FOREIGN KEY (tax_id)             REFERENCES taxes(id),
    CONSTRAINT sales_order_lines_qty_chk    CHECK (ordered_qty > 0),
    CONSTRAINT sales_order_lines_rate_chk   CHECK (rate_per_m >= 0),
    CONSTRAINT sales_order_lines_status_chk CHECK (status IN ('open','planned','in_production','completed','short_closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
    }
};
