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
        if (Schema::hasTable('inquiries')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE inquiries (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    customer_id         BIGINT UNSIGNED NOT NULL,
    customer_contact_id BIGINT UNSIGNED,
    brand_id            BIGINT UNSIGNED,
    inquiry_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    required_by         DATE,
    source              VARCHAR(20),
    merchandiser_id     BIGINT UNSIGNED,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    lost_reason         VARCHAR(255),
    notes               TEXT,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    updated_at          DATETIME(3),
    UNIQUE KEY inquiries_number_uq (number),
    KEY inquiries_open_idx (status, customer_id, inquiry_date),
    KEY inquiries_contact_idx (customer_contact_id),
    KEY inquiries_brand_idx (brand_id),
    KEY inquiries_merch_idx (merchandiser_id),
    KEY inquiries_creator_idx (created_by),
    CONSTRAINT inquiries_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT inquiries_contact_fk  FOREIGN KEY (customer_contact_id) REFERENCES customer_contacts(id),
    CONSTRAINT inquiries_brand_fk    FOREIGN KEY (brand_id)            REFERENCES brands(id),
    CONSTRAINT inquiries_merch_fk    FOREIGN KEY (merchandiser_id)     REFERENCES employees(id),
    CONSTRAINT inquiries_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT inquiries_source_fk FOREIGN KEY (source) REFERENCES inquiry_sources(code),
    CONSTRAINT inquiries_status_chk CHECK (status IN ('draft','open','quoted','won','lost','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
