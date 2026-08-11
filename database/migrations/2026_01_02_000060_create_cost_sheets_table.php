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
        if (Schema::hasTable('cost_sheets')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE cost_sheets (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quotation_line_id BIGINT UNSIGNED,
    product_id        BIGINT UNSIGNED,
    product_spec_id   BIGINT UNSIGNED,
    basis_qty         DECIMAL(18,6) NOT NULL,
    gross_metres      DECIMAL(18,6),
    total_wastage_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    overhead_pct      DECIMAL(9,4) NOT NULL DEFAULT 12,
    admin_pct         DECIMAL(9,4) NOT NULL DEFAULT 5,
    margin_pct        DECIMAL(9,4) NOT NULL DEFAULT 20,
    material_cost     DECIMAL(18,4) NOT NULL DEFAULT 0,
    tooling_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    machine_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    labour_cost       DECIMAL(18,4) NOT NULL DEFAULT 0,
    energy_cost       DECIMAL(18,4) NOT NULL DEFAULT 0,
    packing_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    other_cost        DECIMAL(18,4) NOT NULL DEFAULT 0,
    overhead_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    total_cost        DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit_cost         DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate_per_m        DECIMAL(18,4) NOT NULL DEFAULT 0,
    is_locked         BOOLEAN NOT NULL DEFAULT FALSE,
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        BIGINT UNSIGNED,
    KEY cost_sheets_qline_idx (quotation_line_id),
    KEY cost_sheets_product_idx (product_id),
    KEY cost_sheets_spec_idx (product_spec_id),
    KEY cost_sheets_creator_idx (created_by),
    CONSTRAINT cost_sheets_qline_fk   FOREIGN KEY (quotation_line_id) REFERENCES quotation_lines(id) ON DELETE CASCADE,
    CONSTRAINT cost_sheets_product_fk FOREIGN KEY (product_id)        REFERENCES products(id),
    CONSTRAINT cost_sheets_spec_fk    FOREIGN KEY (product_spec_id)   REFERENCES product_specs(id),
    CONSTRAINT cost_sheets_creator_fk FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT cost_sheets_basis_chk  CHECK (basis_qty > 0),
    CONSTRAINT cost_sheets_margin_chk CHECK (margin_pct < 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_sheets');
    }
};
