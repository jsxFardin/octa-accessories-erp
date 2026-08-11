<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5. SAMPLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sample_request_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sample_request_lines (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_id  BIGINT UNSIGNED NOT NULL,
    line_no            SMALLINT UNSIGNED NOT NULL,
    product_id         BIGINT UNSIGNED,
    product_spec_id    BIGINT UNSIGNED,
    artwork_version_id BIGINT UNSIGNED,
    description        VARCHAR(255) NOT NULL,
    qty                DECIMAL(18,6) NOT NULL,
    colourway          VARCHAR(80),
    status             VARCHAR(20) NOT NULL DEFAULT 'pending',
    UNIQUE KEY sample_request_lines_uq (sample_request_id, line_no),
    KEY sample_request_lines_product_idx (product_id),
    KEY sample_request_lines_spec_idx (product_spec_id),
    KEY sample_request_lines_artwork_idx (artwork_version_id),
    CONSTRAINT sample_request_lines_req_fk     FOREIGN KEY (sample_request_id)  REFERENCES sample_requests(id) ON DELETE CASCADE,
    CONSTRAINT sample_request_lines_product_fk FOREIGN KEY (product_id)         REFERENCES products(id),
    CONSTRAINT sample_request_lines_spec_fk    FOREIGN KEY (product_spec_id)    REFERENCES product_specs(id),
    CONSTRAINT sample_request_lines_artwork_fk FOREIGN KEY (artwork_version_id) REFERENCES artwork_versions(id),
    CONSTRAINT sample_request_lines_qty_chk    CHECK (qty > 0),
    CONSTRAINT sample_request_lines_status_chk CHECK (status IN ('pending','produced','dispatched','approved','rejected'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_request_lines');
    }
};
