<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 8. PLANNING & MRP
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('material_requirements')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE material_requirements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mrp_run_id       BIGINT UNSIGNED NOT NULL,
    item_id          BIGINT UNSIGNED NOT NULL,
    warehouse_id     BIGINT UNSIGNED,
    need_date        DATE NOT NULL,
    gross_req_qty    DECIMAL(18,6) NOT NULL DEFAULT 0,
    on_hand_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    reserved_qty     DECIMAL(18,6) NOT NULL DEFAULT 0,
    on_order_qty     DECIMAL(18,6) NOT NULL DEFAULT 0,
    net_req_qty      DECIMAL(18,6) NOT NULL DEFAULT 0,
    suggested_po_qty DECIMAL(18,6) NOT NULL DEFAULT 0,
    po_place_by      DATE,
    is_shortage      BOOLEAN NOT NULL DEFAULT FALSE,
    pr_line_id       BIGINT UNSIGNED,
    KEY material_requirements_shortage_idx (mrp_run_id, is_shortage, item_id),
    KEY material_requirements_item_idx (item_id, need_date),
    KEY material_requirements_warehouse_idx (warehouse_id),
    KEY material_requirements_prline_idx (pr_line_id),
    CONSTRAINT material_requirements_run_fk       FOREIGN KEY (mrp_run_id)   REFERENCES mrp_runs(id) ON DELETE CASCADE,
    CONSTRAINT material_requirements_item_fk      FOREIGN KEY (item_id)      REFERENCES items(id),
    CONSTRAINT material_requirements_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT material_requirements_prline_fk    FOREIGN KEY (pr_line_id)   REFERENCES purchase_requisition_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requirements');
    }
};
