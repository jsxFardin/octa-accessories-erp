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
        if (Schema::hasTable('supplier_rfqs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_rfqs (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number     VARCHAR(30),
    pr_id      BIGINT UNSIGNED,
    issued_on  DATE NOT NULL DEFAULT (CURRENT_DATE),
    respond_by DATE,
    status     VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY supplier_rfqs_number_uq (number),
    KEY supplier_rfqs_pr_idx (pr_id),
    KEY supplier_rfqs_creator_idx (created_by),
    CONSTRAINT supplier_rfqs_pr_fk      FOREIGN KEY (pr_id)      REFERENCES purchase_requisitions(id),
    CONSTRAINT supplier_rfqs_creator_fk FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT supplier_rfqs_status_chk CHECK (status IN ('draft','issued','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_rfqs');
    }
};
