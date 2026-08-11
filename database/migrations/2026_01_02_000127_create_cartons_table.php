<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cartons')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE cartons (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    packing_list_id BIGINT UNSIGNED NOT NULL,
    carton_no       VARCHAR(20) NOT NULL,
    barcode         VARCHAR(64),
    gross_weight_kg DECIMAL(12,3),
    net_weight_kg   DECIMAL(12,3),
    length_cm       DECIMAL(9,2),
    width_cm        DECIMAL(9,2),
    height_cm       DECIMAL(9,2),
    UNIQUE KEY cartons_uq (packing_list_id, carton_no),
    UNIQUE KEY cartons_barcode_uq (barcode),
    CONSTRAINT cartons_packing_fk FOREIGN KEY (packing_list_id) REFERENCES packing_lists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cartons');
    }
};
