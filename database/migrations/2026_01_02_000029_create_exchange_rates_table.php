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
        if (Schema::hasTable('exchange_rates')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE exchange_rates (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    currency_id  BIGINT UNSIGNED NOT NULL,
    effective_on DATE NOT NULL,
    rate_to_base DECIMAL(18,8) NOT NULL,
    UNIQUE KEY exchange_rates_uq (currency_id, effective_on),
    CONSTRAINT exchange_rates_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT exchange_rates_rate_chk CHECK (rate_to_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
