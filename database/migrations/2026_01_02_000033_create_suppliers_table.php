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
        if (Schema::hasTable('suppliers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE suppliers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20)  NOT NULL,
    name            VARCHAR(150) NOT NULL,
    country         VARCHAR(60),
    address         VARCHAR(255),
    email           VARCHAR(190),
    phone           VARCHAR(30),
    currency_id     BIGINT UNSIGNED,
    payment_term_id BIGINT UNSIGNED,
    lead_time_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    is_approved     BOOLEAN NOT NULL DEFAULT FALSE,
    rating          DECIMAL(4,2),
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at      DATETIME(3),
    UNIQUE KEY suppliers_code_uq (code),
    KEY suppliers_currency_idx (currency_id),
    KEY suppliers_term_idx (payment_term_id),
    CONSTRAINT suppliers_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT suppliers_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
