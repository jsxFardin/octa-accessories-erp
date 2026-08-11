<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11. COMPLIANCE & CHAIN OF CUSTODY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('certifications')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE certifications (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scheme            VARCHAR(20) NOT NULL,
    certificate_no    VARCHAR(80) NOT NULL,
    issuing_body      VARCHAR(150),
    issued_on         DATE NOT NULL,
    expires_on        DATE NOT NULL,
    scope_description VARCHAR(500),
    document_path     VARCHAR(500),
    reminder_days     SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    status            VARCHAR(20) NOT NULL DEFAULT 'active',
    UNIQUE KEY certifications_uq (scheme, certificate_no),
    KEY certifications_expiry_idx (expires_on, status),
    CONSTRAINT certifications_scheme_chk CHECK (scheme IN ('FSC','GRS','OEKO_TEX','BSCI','SMETA','ISO_9001','ISO_14001','SCOPE','OTHER')),
    CONSTRAINT certifications_status_chk CHECK (status IN ('active','expired','suspended','withdrawn')),
    CONSTRAINT certifications_dates_chk  CHECK (expires_on > issued_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('certifications');
    }
};
