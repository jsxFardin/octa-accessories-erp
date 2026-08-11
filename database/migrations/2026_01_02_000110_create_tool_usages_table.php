<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 9. MANUFACTURING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tool_usages')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE tool_usages (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tool_id               BIGINT UNSIGNED NOT NULL,
    job_card_operation_id BIGINT UNSIGNED,
    impressions           BIGINT UNSIGNED NOT NULL DEFAULT 0,
    used_on               DATE NOT NULL DEFAULT (CURRENT_DATE),
    remarks               VARCHAR(255),
    KEY tool_usages_tool_idx (tool_id, used_on),
    KEY tool_usages_op_idx (job_card_operation_id),
    CONSTRAINT tool_usages_tool_fk FOREIGN KEY (tool_id)               REFERENCES tools(id),
    CONSTRAINT tool_usages_op_fk   FOREIGN KEY (job_card_operation_id) REFERENCES job_card_operations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_usages');
    }
};
