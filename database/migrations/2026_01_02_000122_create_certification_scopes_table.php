<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11. COMPLIANCE & CHAIN OF CUSTODY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certification_scopes')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE certification_scopes (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certification_id      BIGINT UNSIGNED NOT NULL,
    product_type          VARCHAR(20),
    item_category_id      BIGINT UNSIGNED,
    min_claim_pct         DECIMAL(9,4) NOT NULL DEFAULT 0,     -- BR-41
    labelled_claim_pct    DECIMAL(9,4) NOT NULL DEFAULT 50,
    max_conversion_factor DECIMAL(9,4) NOT NULL DEFAULT 1,     -- BR-42
    KEY certification_scopes_cert_idx (certification_id),
    KEY certification_scopes_category_idx (item_category_id),
    CONSTRAINT certification_scopes_cert_fk     FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE,
    CONSTRAINT certification_scopes_category_fk FOREIGN KEY (item_category_id) REFERENCES item_categories(id),
    CONSTRAINT certification_scopes_type_fk     FOREIGN KEY (product_type)     REFERENCES product_types(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_scopes');
    }
};
