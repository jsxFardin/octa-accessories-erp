<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7. INVENTORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_ledger')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE stock_ledger (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lot_id        BIGINT UNSIGNED NOT NULL,
    item_id       BIGINT UNSIGNED,
    product_id    BIGINT UNSIGNED,
    warehouse_id  BIGINT UNSIGNED NOT NULL,
    bin_id        BIGINT UNSIGNED,
    movement_type VARCHAR(30) NOT NULL,
    qty           DECIMAL(18,6) NOT NULL,          -- signed
    uom_id        BIGINT UNSIGNED NOT NULL,
    unit_cost     DECIMAL(18,4) NOT NULL DEFAULT 0,
    value         DECIMAL(18,4) NOT NULL DEFAULT 0,
    source_type   VARCHAR(120) NOT NULL,
    source_id     BIGINT UNSIGNED NOT NULL,
    occurred_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    remarks       VARCHAR(255),
    KEY stock_ledger_lot_idx (lot_id, occurred_at),
    KEY stock_ledger_item_idx (item_id, warehouse_id, occurred_at),
    KEY stock_ledger_product_idx (product_id, occurred_at),
    KEY stock_ledger_source_idx (source_type, source_id),
    KEY stock_ledger_warehouse_idx (warehouse_id),
    KEY stock_ledger_bin_idx (bin_id),
    KEY stock_ledger_uom_idx (uom_id),
    KEY stock_ledger_creator_idx (created_by),
    CONSTRAINT stock_ledger_lot_fk       FOREIGN KEY (lot_id)       REFERENCES stock_lots(id),
    CONSTRAINT stock_ledger_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT stock_ledger_product_fk   FOREIGN KEY (product_id)   REFERENCES products(id),
    CONSTRAINT stock_ledger_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT stock_ledger_bin_fk       FOREIGN KEY (bin_id)       REFERENCES bins(id),
    CONSTRAINT stock_ledger_uom_fk       FOREIGN KEY (uom_id)       REFERENCES uoms(id),
    CONSTRAINT stock_ledger_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT stock_ledger_qty_chk  CHECK (qty <> 0),
    CONSTRAINT stock_ledger_type_chk CHECK (movement_type IN (
        'grn_receipt','purchase_return','issue_to_job','return_from_job',
        'production_output','wip_transfer','transfer_in','transfer_out',
        'adjustment_in','adjustment_out','waste','scrap','sample_issue',
        'fg_receipt','dispatch','sales_return','count_variance'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ledger');
    }
};
