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
        if (Schema::hasTable('customer_addresses')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE customer_addresses (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id  BIGINT UNSIGNED NOT NULL,
    label        VARCHAR(80)  NOT NULL,
    kind         VARCHAR(20)  NOT NULL,
    line1        VARCHAR(255) NOT NULL,
    line2        VARCHAR(255),
    city         VARCHAR(80),
    district     VARCHAR(80),
    postcode     VARCHAR(20),
    country      VARCHAR(60) NOT NULL DEFAULT 'Bangladesh',
    transit_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    route_zone   VARCHAR(60),
    is_default   BOOLEAN NOT NULL DEFAULT FALSE,
    KEY customer_addresses_customer_idx (customer_id),
    KEY customer_addresses_zone_idx (route_zone),
    CONSTRAINT customer_addresses_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_addresses_kind_chk CHECK (kind IN ('billing','delivery','both'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
