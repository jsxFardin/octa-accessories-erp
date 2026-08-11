<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9. MANUFACTURING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_cards')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE job_cards (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number                  VARCHAR(30),
    factory_unit_id         BIGINT UNSIGNED NOT NULL,
    sales_order_line_id     BIGINT UNSIGNED,
    sample_request_line_id  BIGINT UNSIGNED,
    production_plan_line_id BIGINT UNSIGNED,
    product_id              BIGINT UNSIGNED NOT NULL,
    product_spec_id         BIGINT UNSIGNED NOT NULL,
    artwork_version_id      BIGINT UNSIGNED NOT NULL,   -- Gate 1 / J1
    bom_id                  BIGINT UNSIGNED,
    routing_id              BIGINT UNSIGNED NOT NULL,
    colourway               VARCHAR(80),
    planned_qty             DECIMAL(18,6) NOT NULL,
    produced_qty            DECIMAL(18,6) NOT NULL DEFAULT 0,
    good_qty                DECIMAL(18,6) NOT NULL DEFAULT 0,
    waste_qty               DECIMAL(18,6) NOT NULL DEFAULT 0,
    overrun_tolerance_pct   DECIMAL(9,4) NOT NULL DEFAULT 3,
    planned_start           DATETIME(3),
    planned_finish          DATETIME(3),
    actual_start            DATETIME(3),
    actual_finish           DATETIME(3),
    due_date                DATE,
    priority                SMALLINT UNSIGNED NOT NULL DEFAULT 50,
    gross_metres            DECIMAL(18,6),
    ends                    SMALLINT UNSIGNED,
    labels_per_metre        DECIMAL(18,6),
    status                  VARCHAR(25) NOT NULL DEFAULT 'draft',
    hold_reason             VARCHAR(500),
    material_waiver_reason  VARCHAR(500),
    created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by              BIGINT UNSIGNED,
    closed_at               DATETIME(3),
    UNIQUE KEY job_cards_number_uq (number),
    KEY job_cards_open_idx (status, factory_unit_id, due_date),
    KEY job_cards_product_idx (product_id),
    KEY job_cards_soline_idx (sales_order_line_id),
    KEY job_cards_sampleline_idx (sample_request_line_id),
    KEY job_cards_planline_idx (production_plan_line_id),
    KEY job_cards_spec_idx (product_spec_id),
    KEY job_cards_artwork_idx (artwork_version_id),
    KEY job_cards_bom_idx (bom_id),
    KEY job_cards_routing_idx (routing_id),
    KEY job_cards_creator_idx (created_by),
    CONSTRAINT job_cards_unit_fk       FOREIGN KEY (factory_unit_id)         REFERENCES factory_units(id),
    CONSTRAINT job_cards_soline_fk     FOREIGN KEY (sales_order_line_id)     REFERENCES sales_order_lines(id),
    CONSTRAINT job_cards_sampleline_fk FOREIGN KEY (sample_request_line_id)  REFERENCES sample_request_lines(id),
    CONSTRAINT job_cards_planline_fk   FOREIGN KEY (production_plan_line_id) REFERENCES production_plan_lines(id),
    CONSTRAINT job_cards_product_fk    FOREIGN KEY (product_id)              REFERENCES products(id),
    CONSTRAINT job_cards_spec_fk       FOREIGN KEY (product_spec_id)         REFERENCES product_specs(id),
    CONSTRAINT job_cards_artwork_fk    FOREIGN KEY (artwork_version_id)      REFERENCES artwork_versions(id),
    CONSTRAINT job_cards_bom_fk        FOREIGN KEY (bom_id)                  REFERENCES boms(id),
    CONSTRAINT job_cards_routing_fk    FOREIGN KEY (routing_id)              REFERENCES routings(id),
    CONSTRAINT job_cards_creator_fk    FOREIGN KEY (created_by)              REFERENCES users(id),
    CONSTRAINT job_cards_qty_chk    CHECK (planned_qty > 0),
    CONSTRAINT job_cards_status_chk CHECK (status IN ('draft','planned','material_pending','released','in_production','on_hold','qc_pending','completed','closed','cancelled')),
    CONSTRAINT job_cards_source_chk CHECK (sales_order_line_id IS NOT NULL OR sample_request_line_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('job_cards');
    }
};
