<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10. QUALITY & LABORATORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_test_requirements')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE customer_test_requirements (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    product_id   BIGINT UNSIGNED,
    lab_test_id  BIGINT UNSIGNED NOT NULL,
    pass_value   VARCHAR(40) NOT NULL,
    is_mandatory BOOLEAN NOT NULL DEFAULT TRUE,
    product_key  BIGINT UNSIGNED GENERATED ALWAYS AS (IFNULL(product_id, 0)) STORED,
    UNIQUE KEY customer_test_requirements_uq (customer_id, product_key, lab_test_id),
    KEY customer_test_requirements_product_idx (product_id),
    KEY customer_test_requirements_test_idx (lab_test_id),
    CONSTRAINT customer_test_requirements_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_test_requirements_product_fk  FOREIGN KEY (product_id)  REFERENCES products(id),
    CONSTRAINT customer_test_requirements_test_fk     FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_test_requirements');
    }
};
