<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12. FINISHED GOODS, PACKING, DISPATCH, FLEET
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trips')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE trips (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number         VARCHAR(30),
    vehicle_id     BIGINT UNSIGNED NOT NULL,
    driver_id      BIGINT UNSIGNED,
    trip_date      DATE NOT NULL DEFAULT (CURRENT_DATE),
    route_zone     VARCHAR(60),
    started_at     DATETIME(3),
    completed_at   DATETIME(3),
    start_odometer DECIMAL(12,2),
    end_odometer   DECIMAL(12,2),
    fuel_cost      DECIMAL(18,4) NOT NULL DEFAULT 0,
    status         VARCHAR(20) NOT NULL DEFAULT 'planned',
    remarks        VARCHAR(255),
    UNIQUE KEY trips_number_uq (number),
    KEY trips_vehicle_idx (vehicle_id, trip_date),
    KEY trips_driver_idx (driver_id),
    KEY trips_status_idx (status, trip_date),
    CONSTRAINT trips_vehicle_fk FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    CONSTRAINT trips_driver_fk  FOREIGN KEY (driver_id)  REFERENCES drivers(id),
    CONSTRAINT trips_status_chk CHECK (status IN ('planned','loading','in_transit','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
