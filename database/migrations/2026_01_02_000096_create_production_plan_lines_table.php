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
        if (Schema::hasTable('production_plan_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE production_plan_lines (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    production_plan_id  BIGINT UNSIGNED NOT NULL,
    sales_order_line_id BIGINT UNSIGNED,
    product_id          BIGINT UNSIGNED NOT NULL,
    planned_qty         DECIMAL(18,6) NOT NULL,
    planned_start       DATE,
    planned_finish      DATE,
    machine_group_id    BIGINT UNSIGNED,
    priority            SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    status              VARCHAR(20) NOT NULL DEFAULT 'planned',
    KEY production_plan_lines_plan_idx (production_plan_id),
    KEY production_plan_lines_soline_idx (sales_order_line_id),
    KEY production_plan_lines_product_idx (product_id),
    KEY production_plan_lines_group_idx (machine_group_id),
    CONSTRAINT production_plan_lines_plan_fk    FOREIGN KEY (production_plan_id)  REFERENCES production_plans(id) ON DELETE CASCADE,
    CONSTRAINT production_plan_lines_soline_fk  FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id),
    CONSTRAINT production_plan_lines_product_fk FOREIGN KEY (product_id)          REFERENCES products(id),
    CONSTRAINT production_plan_lines_group_fk   FOREIGN KEY (machine_group_id)    REFERENCES machine_groups(id),
    CONSTRAINT production_plan_lines_qty_chk    CHECK (planned_qty > 0),
    CONSTRAINT production_plan_lines_status_chk CHECK (status IN ('planned','released','in_production','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_lines');
    }
};
