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
        if (Schema::hasTable('supplier_items')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id    BIGINT UNSIGNED NOT NULL,
    item_id        BIGINT UNSIGNED NOT NULL,
    supplier_code  VARCHAR(60),
    last_rate      DECIMAL(18,4),
    currency_id    BIGINT UNSIGNED,
    lead_time_days SMALLINT UNSIGNED,
    moq            DECIMAL(18,6),
    UNIQUE KEY supplier_items_uq (supplier_id, item_id),
    KEY supplier_items_item_idx (item_id),
    KEY supplier_items_currency_idx (currency_id),
    CONSTRAINT supplier_items_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT supplier_items_item_fk     FOREIGN KEY (item_id)     REFERENCES items(id) ON DELETE CASCADE,
    CONSTRAINT supplier_items_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_items');
    }
};
