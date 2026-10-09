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
    number            VARCHAR(30),                                -- CS-26-00012, allocated when the sheet is submitted
    revision_no       SMALLINT UNSIGNED NOT NULL DEFAULT 0,       -- a marketing sheet revises with its quotation
    stage             VARCHAR(20) NOT NULL DEFAULT 'marketing',  -- spec §4: marketing → pre_production (locked at confirm) → post_production (actuals at close)
    status            VARCHAR(20) NOT NULL DEFAULT 'draft',      -- draft → submitted → approved / rejected; approval is a state, not a flag
    quotation_line_id BIGINT UNSIGNED,
    sales_order_line_id BIGINT UNSIGNED,                        -- the pre-production benchmark of this line
    job_card_id       BIGINT UNSIGNED,                          -- the post-production actuals of this job
    product_id        BIGINT UNSIGNED,
    product_spec_id   BIGINT UNSIGNED,
    basis_qty         DECIMAL(18,6) NOT NULL,
    costing_per       DECIMAL(18,6) NOT NULL DEFAULT 1,           -- the unit the price is quoted per: 1 pc, 12, 144, 1000
    currency_id       BIGINT UNSIGNED,                            -- the quote's currency; price and margin read in it
    exchange_rate     DECIMAL(18,6) NOT NULL DEFAULT 1,
    incoterm          VARCHAR(10),
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
    revenue_per_unit  DECIMAL(18,6),                            -- quoted, agreed or invoiced price per unit: what the margin is against
    variance_reason   VARCHAR(255),                             -- why actual differed from the benchmark, in the costing clerk's words
    is_locked         BOOLEAN NOT NULL DEFAULT FALSE,
    locked_at         DATETIME(3),
    submitted_by      BIGINT UNSIGNED,
    submitted_at      DATETIME(3),
    approved_by       BIGINT UNSIGNED,
    approved_at       DATETIME(3),
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        BIGINT UNSIGNED,
    UNIQUE KEY cost_sheets_number_uq (number),
    KEY cost_sheets_status_idx (stage, status),
    KEY cost_sheets_currency_idx (currency_id),
    KEY cost_sheets_submitter_idx (submitted_by),
    KEY cost_sheets_approver_idx (approved_by),
    KEY cost_sheets_qline_idx (quotation_line_id),
    KEY cost_sheets_soline_idx (sales_order_line_id),
    KEY cost_sheets_job_idx (job_card_id),
    KEY cost_sheets_product_idx (product_id),
    KEY cost_sheets_spec_idx (product_spec_id),
    KEY cost_sheets_creator_idx (created_by),
    CONSTRAINT cost_sheets_qline_fk   FOREIGN KEY (quotation_line_id) REFERENCES quotation_lines(id) ON DELETE CASCADE,
    CONSTRAINT cost_sheets_product_fk FOREIGN KEY (product_id)        REFERENCES products(id),
    CONSTRAINT cost_sheets_spec_fk    FOREIGN KEY (product_spec_id)   REFERENCES product_specs(id),
    CONSTRAINT cost_sheets_creator_fk FOREIGN KEY (created_by)        REFERENCES users(id),
    CONSTRAINT cost_sheets_currency_fk  FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT cost_sheets_submitter_fk FOREIGN KEY (submitted_by)    REFERENCES users(id),
    CONSTRAINT cost_sheets_approver_fk  FOREIGN KEY (approved_by)     REFERENCES users(id),
    CONSTRAINT cost_sheets_stage_chk  CHECK (stage IN ('marketing','pre_production','post_production')),
    CONSTRAINT cost_sheets_status_chk CHECK (status IN ('draft','submitted','approved','rejected')),
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
