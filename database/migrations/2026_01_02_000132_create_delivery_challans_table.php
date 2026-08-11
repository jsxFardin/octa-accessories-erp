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
        if (Schema::hasTable('delivery_challans')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE delivery_challans (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    packing_list_id     BIGINT UNSIGNED,
    sales_order_id      BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    delivery_address_id BIGINT UNSIGNED,
    trip_id             BIGINT UNSIGNED,
    challan_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    mode                VARCHAR(25) NOT NULL DEFAULT 'own_fleet',
    courier_name        VARCHAR(80),
    tracking_no         VARCHAR(80),
    total_cartons       INT UNSIGNED NOT NULL DEFAULT 0,
    total_qty           DECIMAL(18,6) NOT NULL DEFAULT 0,
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    gate_pass_no        VARCHAR(40),
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY delivery_challans_number_uq (number),
    KEY delivery_challans_customer_idx (customer_id, status, challan_date),
    KEY delivery_challans_packing_idx (packing_list_id),
    KEY delivery_challans_order_idx (sales_order_id),
    KEY delivery_challans_address_idx (delivery_address_id),
    KEY delivery_challans_trip_idx (trip_id),
    KEY delivery_challans_creator_idx (created_by),
    CONSTRAINT delivery_challans_packing_fk  FOREIGN KEY (packing_list_id)     REFERENCES packing_lists(id),
    CONSTRAINT delivery_challans_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT delivery_challans_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT delivery_challans_address_fk  FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT delivery_challans_trip_fk     FOREIGN KEY (trip_id)             REFERENCES trips(id),
    CONSTRAINT delivery_challans_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT delivery_challans_mode_chk   CHECK (mode IN ('own_fleet','courier','customer_pickup','freight_forwarder')),
    CONSTRAINT delivery_challans_status_chk CHECK (status IN ('draft','issued','in_transit','delivered','returned','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_challans');
    }
};
