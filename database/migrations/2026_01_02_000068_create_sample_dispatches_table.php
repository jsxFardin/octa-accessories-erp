<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 5. SAMPLING
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sample_dispatches')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE sample_dispatches (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sample_request_id BIGINT UNSIGNED NOT NULL,
    dispatched_on     DATE NOT NULL DEFAULT (CURRENT_DATE),
    courier_name      VARCHAR(80),
    tracking_no       VARCHAR(80),
    recipient         VARCHAR(150),
    delivered_on      DATE,
    remarks           VARCHAR(255),
    KEY sample_dispatches_req_idx (sample_request_id),
    CONSTRAINT sample_dispatches_req_fk FOREIGN KEY (sample_request_id) REFERENCES sample_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_dispatches');
    }
};
