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
        if (Schema::hasTable('supplier_contacts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE supplier_contacts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    supplier_id BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    designation VARCHAR(120),
    email       VARCHAR(190),
    phone       VARCHAR(30),
    is_primary  BOOLEAN NOT NULL DEFAULT FALSE,
    KEY supplier_contacts_supplier_idx (supplier_id),
    CONSTRAINT supplier_contacts_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contacts');
    }
};
