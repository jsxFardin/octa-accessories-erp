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
        if (Schema::hasTable('so_amendments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE so_amendments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sales_order_id BIGINT UNSIGNED NOT NULL,
    revision_no    SMALLINT UNSIGNED NOT NULL,
    changed_field  VARCHAR(80)  NOT NULL,
    old_value      VARCHAR(255),
    new_value      VARCHAR(255),
    reason         VARCHAR(500) NOT NULL,
    approved_by    BIGINT UNSIGNED,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by     BIGINT UNSIGNED,
    KEY so_amendments_order_idx (sales_order_id),
    KEY so_amendments_approver_idx (approved_by),
    KEY so_amendments_creator_idx (created_by),
    CONSTRAINT so_amendments_order_fk    FOREIGN KEY (sales_order_id) REFERENCES sales_orders(id) ON DELETE CASCADE,
    CONSTRAINT so_amendments_approver_fk FOREIGN KEY (approved_by)    REFERENCES users(id),
    CONSTRAINT so_amendments_creator_fk  FOREIGN KEY (created_by)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('so_amendments');
    }
};
