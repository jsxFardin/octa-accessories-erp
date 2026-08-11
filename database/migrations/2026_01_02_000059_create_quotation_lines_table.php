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
        if (Schema::hasTable('quotation_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE quotation_lines (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    quotation_id    BIGINT UNSIGNED NOT NULL,
    line_no         SMALLINT UNSIGNED NOT NULL,
    product_id      BIGINT UNSIGNED,
    product_spec_id BIGINT UNSIGNED,
    description     VARCHAR(255) NOT NULL,
    qty             DECIMAL(18,6) NOT NULL,
    rate_per_m      DECIMAL(18,4) NOT NULL,
    tooling_charge  DECIMAL(18,4) NOT NULL DEFAULT 0,
    tax_id          BIGINT UNSIGNED,
    line_total      DECIMAL(18,4) NOT NULL DEFAULT 0,
    lead_time_days  SMALLINT UNSIGNED,
    UNIQUE KEY quotation_lines_uq (quotation_id, line_no),
    KEY quotation_lines_product_idx (product_id),
    KEY quotation_lines_spec_idx (product_spec_id),
    KEY quotation_lines_tax_idx (tax_id),
    CONSTRAINT quotation_lines_quotation_fk FOREIGN KEY (quotation_id)    REFERENCES quotations(id) ON DELETE CASCADE,
    CONSTRAINT quotation_lines_product_fk   FOREIGN KEY (product_id)      REFERENCES products(id),
    CONSTRAINT quotation_lines_spec_fk      FOREIGN KEY (product_spec_id) REFERENCES product_specs(id),
    CONSTRAINT quotation_lines_tax_fk       FOREIGN KEY (tax_id)          REFERENCES taxes(id),
    CONSTRAINT quotation_lines_qty_chk  CHECK (qty > 0),
    CONSTRAINT quotation_lines_rate_chk CHECK (rate_per_m >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
    }
};
