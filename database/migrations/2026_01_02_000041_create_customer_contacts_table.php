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
        if (Schema::hasTable('customer_contacts')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE customer_contacts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id    BIGINT UNSIGNED NOT NULL,
    name           VARCHAR(150) NOT NULL,
    designation    VARCHAR(120),
    email          VARCHAR(190),
    phone          VARCHAR(30),
    is_primary     BOOLEAN NOT NULL DEFAULT FALSE,
    portal_user_id BIGINT UNSIGNED,
    KEY customer_contacts_customer_idx (customer_id),
    KEY customer_contacts_user_idx (portal_user_id),
    CONSTRAINT customer_contacts_customer_fk FOREIGN KEY (customer_id)    REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT customer_contacts_user_fk     FOREIGN KEY (portal_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
