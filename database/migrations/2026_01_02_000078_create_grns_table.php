<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 6. PROCUREMENT
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grns')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE grns (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number          VARCHAR(30),
    po_id           BIGINT UNSIGNED,
    supplier_id     BIGINT UNSIGNED NOT NULL,
    warehouse_id    BIGINT UNSIGNED NOT NULL,
    received_on     DATE NOT NULL DEFAULT (CURRENT_DATE),
    challan_no      VARCHAR(60),
    invoice_no      VARCHAR(60),
    lc_no           VARCHAR(60),
    bill_of_entry   VARCHAR(60),
    freight_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    duty_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    clearing_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    status          VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks         VARCHAR(500),
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      BIGINT UNSIGNED,
    UNIQUE KEY grns_number_uq (number),
    KEY grns_po_idx (po_id),
    KEY grns_supplier_idx (supplier_id, received_on),
    KEY grns_warehouse_idx (warehouse_id),
    KEY grns_creator_idx (created_by),
    CONSTRAINT grns_po_fk        FOREIGN KEY (po_id)        REFERENCES purchase_orders(id),
    CONSTRAINT grns_supplier_fk  FOREIGN KEY (supplier_id)  REFERENCES suppliers(id),
    CONSTRAINT grns_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    CONSTRAINT grns_creator_fk   FOREIGN KEY (created_by)   REFERENCES users(id),
    CONSTRAINT grns_status_chk CHECK (status IN ('draft','pending_qc','accepted','partially_accepted','rejected','posted','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('grns');
    }
};
