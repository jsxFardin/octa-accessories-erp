<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 10. QUALITY & LABORATORY
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('qc_inspection_defects')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE qc_inspection_defects (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    qc_inspection_id BIGINT UNSIGNED NOT NULL,
    defect_id        BIGINT UNSIGNED NOT NULL,
    qty              INT UNSIGNED NOT NULL,
    remarks          VARCHAR(255),
    UNIQUE KEY qc_inspection_defects_uq (qc_inspection_id, defect_id),
    KEY qc_inspection_defects_defect_idx (defect_id),
    CONSTRAINT qc_inspection_defects_insp_fk   FOREIGN KEY (qc_inspection_id) REFERENCES qc_inspections(id) ON DELETE CASCADE,
    CONSTRAINT qc_inspection_defects_defect_fk FOREIGN KEY (defect_id)        REFERENCES defects(id),
    CONSTRAINT qc_inspection_defects_qty_chk CHECK (qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('qc_inspection_defects');
    }
};
