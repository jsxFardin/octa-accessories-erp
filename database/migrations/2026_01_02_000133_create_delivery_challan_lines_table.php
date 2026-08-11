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
        if (Schema::hasTable('delivery_challan_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE delivery_challan_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_challan_id BIGINT UNSIGNED NOT NULL,
    line_no             SMALLINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    lot_id              BIGINT UNSIGNED,
    qty                 DECIMAL(18,6) NOT NULL,
    cartons             INT UNSIGNED,
    UNIQUE KEY delivery_challan_lines_uq (delivery_challan_id, line_no),
    KEY delivery_challan_lines_soline_idx (sales_order_line_id),
    KEY delivery_challan_lines_product_idx (product_id),
    KEY delivery_challan_lines_lot_idx (lot_id),
    CONSTRAINT delivery_challan_lines_challan_fk FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id) ON DELETE CASCADE,
    CONSTRAINT delivery_challan_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT delivery_challan_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT delivery_challan_lines_lot_fk     FOREIGN KEY (lot_id)              REFERENCES stock_lots(id),
    CONSTRAINT delivery_challan_lines_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_challan_lines');
    }
};
