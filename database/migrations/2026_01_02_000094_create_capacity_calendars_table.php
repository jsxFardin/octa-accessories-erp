<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 8. PLANNING & MRP
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capacity_calendars')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE capacity_calendars (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    machine_id           BIGINT UNSIGNED,
    machine_group_id     BIGINT UNSIGNED,
    calendar_date        DATE NOT NULL,
    shift_id             BIGINT UNSIGNED,
    available_minutes    DECIMAL(9,2) NOT NULL DEFAULT 0,
    planned_downtime_pct DECIMAL(9,4) NOT NULL DEFAULT 0,
    is_holiday           BOOLEAN NOT NULL DEFAULT FALSE,
    remarks              VARCHAR(255),
    KEY capacity_calendars_date_idx (calendar_date, machine_id),
    KEY capacity_calendars_group_idx (machine_group_id),
    KEY capacity_calendars_shift_idx (shift_id),
    KEY capacity_calendars_machine_idx (machine_id),
    CONSTRAINT capacity_calendars_machine_fk FOREIGN KEY (machine_id)       REFERENCES machines(id),
    CONSTRAINT capacity_calendars_group_fk   FOREIGN KEY (machine_group_id) REFERENCES machine_groups(id),
    CONSTRAINT capacity_calendars_shift_fk   FOREIGN KEY (shift_id)         REFERENCES shifts(id),
    CONSTRAINT capacity_calendars_minutes_chk CHECK (available_minutes >= 0),
    CONSTRAINT capacity_calendars_target_chk  CHECK (machine_id IS NOT NULL OR machine_group_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_calendars');
    }
};
