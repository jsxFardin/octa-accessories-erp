<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 4. CRM & SALES
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('so_delivery_schedules')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE so_delivery_schedules (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_line_id BIGINT UNSIGNED NOT NULL,
    sequence_no         SMALLINT UNSIGNED NOT NULL,
    qty                 DECIMAL(18,6) NOT NULL,
    due_date            DATE NOT NULL,
    delivered_qty       DECIMAL(18,6) NOT NULL DEFAULT 0,
    UNIQUE KEY so_delivery_schedules_uq (sales_order_line_id, sequence_no),
    CONSTRAINT so_delivery_schedules_line_fk FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_lines(id) ON DELETE CASCADE,
    CONSTRAINT so_delivery_schedules_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('so_delivery_schedules');
    }
};
