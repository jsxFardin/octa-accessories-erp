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
        if (Schema::hasTable('trip_stops')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE trip_stops (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_id             BIGINT UNSIGNED NOT NULL,
    sequence_no         SMALLINT UNSIGNED NOT NULL,
    delivery_challan_id BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED,
    address_id          BIGINT UNSIGNED,
    planned_at          DATETIME(3),
    arrived_at          DATETIME(3),
    departed_at         DATETIME(3),
    status              VARCHAR(25) NOT NULL DEFAULT 'pending',
    received_by_name    VARCHAR(150),
    signature_path      VARCHAR(500),
    photo_path          VARCHAR(500),
    pod_captured_at     DATETIME(3),
    failure_reason      VARCHAR(255),
    UNIQUE KEY trip_stops_uq (trip_id, sequence_no),
    KEY trip_stops_challan_idx (delivery_challan_id),
    KEY trip_stops_customer_idx (customer_id),
    KEY trip_stops_address_idx (address_id),
    CONSTRAINT trip_stops_trip_fk     FOREIGN KEY (trip_id)             REFERENCES trips(id) ON DELETE CASCADE,
    CONSTRAINT trip_stops_challan_fk  FOREIGN KEY (delivery_challan_id) REFERENCES delivery_challans(id),
    CONSTRAINT trip_stops_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT trip_stops_address_fk  FOREIGN KEY (address_id)          REFERENCES customer_addresses(id),
    CONSTRAINT trip_stops_status_chk CHECK (status IN ('pending','arrived','delivered','partially_delivered','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stops');
    }
};
