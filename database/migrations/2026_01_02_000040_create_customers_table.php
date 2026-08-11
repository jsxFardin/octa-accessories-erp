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
        if (Schema::hasTable('customers')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE customers (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(20)  NOT NULL,
    name                VARCHAR(180) NOT NULL,
    kind                VARCHAR(20)  NOT NULL DEFAULT 'manufacturer',
    buying_house_id     BIGINT UNSIGNED,
    agent_id            BIGINT UNSIGNED,
    currency_id         BIGINT UNSIGNED,
    payment_term_id     BIGINT UNSIGNED,
    credit_limit        DECIMAL(18,4) NOT NULL DEFAULT 0,
    min_order_value     DECIMAL(18,4) NOT NULL DEFAULT 0,
    over_tolerance_pct  DECIMAL(9,4)  NOT NULL DEFAULT 5,
    under_tolerance_pct DECIMAL(9,4)  NOT NULL DEFAULT 5,
    bin_no              VARCHAR(40),
    tin_no              VARCHAR(40),
    email               VARCHAR(190),
    phone               VARCHAR(30),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at          DATETIME(3),
    UNIQUE KEY customers_code_uq (code),
    KEY customers_bh_idx (buying_house_id),
    KEY customers_agent_idx (agent_id),
    KEY customers_currency_idx (currency_id),
    KEY customers_term_idx (payment_term_id),
    CONSTRAINT customers_bh_fk       FOREIGN KEY (buying_house_id) REFERENCES buying_houses(id),
    CONSTRAINT customers_agent_fk    FOREIGN KEY (agent_id)        REFERENCES agents(id),
    CONSTRAINT customers_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT customers_term_fk     FOREIGN KEY (payment_term_id) REFERENCES payment_terms(id),
    CONSTRAINT customers_kind_fk FOREIGN KEY (kind) REFERENCES customer_kinds(code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
