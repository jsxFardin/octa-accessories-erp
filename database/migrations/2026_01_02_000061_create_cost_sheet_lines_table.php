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
        if (Schema::hasTable('cost_sheet_lines')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE cost_sheet_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cost_sheet_id    BIGINT UNSIGNED NOT NULL,
    sequence_no      SMALLINT UNSIGNED NOT NULL,
    cost_type        VARCHAR(30) NOT NULL,
    item_id          BIGINT UNSIGNED,
    machine_group_id BIGINT UNSIGNED,
    description      VARCHAR(255) NOT NULL,
    basis_uom        VARCHAR(20),
    qty              DECIMAL(18,6) NOT NULL DEFAULT 0,
    rate             DECIMAL(18,6) NOT NULL DEFAULT 0,
    amount           DECIMAL(18,4) NOT NULL DEFAULT 0,
    formula_ref      VARCHAR(20),
    UNIQUE KEY cost_sheet_lines_uq (cost_sheet_id, sequence_no),
    KEY cost_sheet_lines_item_idx (item_id),
    KEY cost_sheet_lines_group_idx (machine_group_id),
    CONSTRAINT cost_sheet_lines_sheet_fk FOREIGN KEY (cost_sheet_id)    REFERENCES cost_sheets(id) ON DELETE CASCADE,
    CONSTRAINT cost_sheet_lines_item_fk  FOREIGN KEY (item_id)          REFERENCES items(id),
    CONSTRAINT cost_sheet_lines_group_fk FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT cost_sheet_lines_type_chk CHECK (cost_type IN (
        'material_yarn','material_ribbon','material_ink','material_chemical',
        'material_paper','material_film','material_packing','tooling','machine',
        'labour','energy','outsourcing','freight','overhead','margin','minimum_charge','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_sheet_lines');
    }
};
