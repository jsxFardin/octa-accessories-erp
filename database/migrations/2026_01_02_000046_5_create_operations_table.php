<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operation master (spec §2).
 *
 * A process shared by several families — dyeing for narrow textile and cord, printing for
 * label, paper, packaging and decoration, plating for fasteners, cutting for nearly all — is
 * one row here, and every family routing that runs it points at that row. Its rate and
 * machine group are the defaults a routing step starts from. Before this, each routing typed
 * its own steps and nothing said that two of them were the same process on the same machines.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('operations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE operations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code             VARCHAR(30)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    process_type     VARCHAR(30)  NOT NULL,
    machine_group_id BIGINT UNSIGNED,
    output_uom       VARCHAR(20)  NOT NULL DEFAULT 'pcs',
    is_shared        BOOLEAN NOT NULL DEFAULT FALSE,
    requires_qc      BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active        BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY operations_code_uq (code),
    KEY operations_group_idx (machine_group_id),
    CONSTRAINT operations_group_fk   FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT operations_process_chk CHECK (process_type IN ('design','digitizing','warping','weaving','knitting','braiding','twisting','heat_setting','dyeing','finishing','coating','adhesive','drying','flexo','screen','heat_transfer','offset','thermal','printing','curing','lamination','slitting','cutting','die_cutting','creasing','punching','folding','barcode_verify','eyeleting','stringing','tipping','chain_forming','assembly','slider_fitting','stopping','puller_fitting','moulding','injection','trimming','polishing','drilling','logo_marking','stamping','die_casting','forming','deburring','plating','mixing','cooling','extrusion','sealing','gluing','embroidery','inspection','packing'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
