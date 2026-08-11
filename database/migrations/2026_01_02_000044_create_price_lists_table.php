<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2. ORGANISATION & MASTER DATA
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('price_lists')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE price_lists (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    currency_id BIGINT UNSIGNED NOT NULL,
    valid_from  DATE NOT NULL,
    valid_to    DATE,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY price_lists_code_uq (code),
    KEY price_lists_customer_idx (customer_id),
    KEY price_lists_currency_idx (currency_id),
    CONSTRAINT price_lists_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT price_lists_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT price_lists_valid_chk CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
