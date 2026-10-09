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
        if (Schema::hasTable('machine_groups')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE machine_groups (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(20)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    process_type VARCHAR(30)  NOT NULL,
    output_uom   VARCHAR(20)  NOT NULL DEFAULT 'metre',
    UNIQUE KEY machine_groups_code_uq (code),
    CONSTRAINT machine_groups_process_chk CHECK (process_type IN ('design','digitizing','warping','weaving','knitting','braiding','twisting','heat_setting','dyeing','finishing','coating','adhesive','drying','flexo','screen','heat_transfer','offset','thermal','printing','curing','lamination','slitting','cutting','die_cutting','creasing','punching','folding','barcode_verify','eyeleting','stringing','tipping','chain_forming','assembly','slider_fitting','stopping','puller_fitting','moulding','injection','trimming','polishing','drilling','logo_marking','stamping','die_casting','forming','deburring','plating','mixing','cooling','extrusion','sealing','gluing','embroidery','inspection','packing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_groups');
    }
};
