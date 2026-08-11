<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 6. PROCUREMENT
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_returns')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE purchase_returns (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number      VARCHAR(30),
    grn_id      BIGINT UNSIGNED,
    supplier_id BIGINT UNSIGNED NOT NULL,
    returned_on DATE NOT NULL DEFAULT (CURRENT_DATE),
    reason      VARCHAR(500) NOT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'draft',
    created_by  BIGINT UNSIGNED,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY purchase_returns_number_uq (number),
    KEY purchase_returns_grn_idx (grn_id),
    KEY purchase_returns_supplier_idx (supplier_id),
    KEY purchase_returns_creator_idx (created_by),
    CONSTRAINT purchase_returns_grn_fk      FOREIGN KEY (grn_id)      REFERENCES grns(id),
    CONSTRAINT purchase_returns_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT purchase_returns_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT purchase_returns_status_chk CHECK (status IN ('draft','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
