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
        if (Schema::hasTable('packing_lists')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE packing_lists (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    sales_order_id      BIGINT UNSIGNED,
    customer_id         BIGINT UNSIGNED NOT NULL,
    delivery_address_id BIGINT UNSIGNED,
    packed_on           DATE NOT NULL DEFAULT (CURRENT_DATE),
    total_cartons       INT UNSIGNED NOT NULL DEFAULT 0,
    total_qty           DECIMAL(18,6) NOT NULL DEFAULT 0,
    gross_weight_kg     DECIMAL(12,3),
    net_weight_kg       DECIMAL(12,3),
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    cert_claim_scheme   VARCHAR(20),
    cert_claim_pct      DECIMAL(9,4) NOT NULL DEFAULT 0,
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY packing_lists_number_uq (number),
    KEY packing_lists_customer_idx (customer_id, status),
    KEY packing_lists_order_idx (sales_order_id),
    KEY packing_lists_address_idx (delivery_address_id),
    KEY packing_lists_creator_idx (created_by),
    CONSTRAINT packing_lists_order_fk    FOREIGN KEY (sales_order_id)      REFERENCES sales_orders(id),
    CONSTRAINT packing_lists_customer_fk FOREIGN KEY (customer_id)         REFERENCES customers(id),
    CONSTRAINT packing_lists_address_fk  FOREIGN KEY (delivery_address_id) REFERENCES customer_addresses(id),
    CONSTRAINT packing_lists_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT packing_lists_status_chk CHECK (status IN ('draft','packed','dispatched','delivered','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_lists');
    }
};
