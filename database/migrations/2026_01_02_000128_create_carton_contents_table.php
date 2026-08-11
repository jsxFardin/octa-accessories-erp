<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('carton_contents')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE carton_contents (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carton_id           BIGINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED,
    colourway           VARCHAR(80),
    qty                 DECIMAL(18,6) NOT NULL,
    bundles             INT UNSIGNED,
    KEY carton_contents_carton_idx (carton_id),
    KEY carton_contents_lot_idx (lot_id),
    KEY carton_contents_soline_idx (sales_order_line_id),
    KEY carton_contents_product_idx (product_id),
    CONSTRAINT carton_contents_carton_fk  FOREIGN KEY (carton_id)           REFERENCES cartons(id) ON DELETE CASCADE,
    CONSTRAINT carton_contents_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT carton_contents_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT carton_contents_lot_fk     FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT carton_contents_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_contents');
    }
};
