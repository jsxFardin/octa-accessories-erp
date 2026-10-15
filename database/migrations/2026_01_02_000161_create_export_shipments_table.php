<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11. DISPATCH — export shipments
 *
 * A consignment leaving the country: the transport facts once (vessel or flight, container,
 * seal, BL/AWB, EXP, UD) for one or more delivery notes, the documents that travel with it,
 * and the credit it ships under. The twin of `import_shipments`.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('export_shipments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE export_shipments (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    customer_id         BIGINT UNSIGNED NOT NULL,
    lc_id               BIGINT UNSIGNED,                      -- the export credit it ships under, if any
    mode                VARCHAR(20) NOT NULL DEFAULT 'sea',
    forwarder           VARCHAR(120),
    carrier             VARCHAR(120),                         -- shipping line or airline
    vessel_or_flight    VARCHAR(120),
    container_no        VARCHAR(40),
    seal_no             VARCHAR(40),
    transport_doc_no    VARCHAR(60),                          -- BL or AWB
    transport_doc_date  DATE,
    exp_form_no         VARCHAR(60),
    exp_form_date       DATE,
    ud_no               VARCHAR(60),                          -- utilisation declaration (bonded buyers)
    port_of_loading     VARCHAR(80),
    port_of_discharge   VARCHAR(80),
    final_destination   VARCHAR(120),
    incoterm            VARCHAR(20),
    etd                 DATE,
    eta                 DATE,
    shipped_on          DATE,
    presented_on        DATE,
    delivered_on        DATE,
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    goods_value         DECIMAL(18,4) NOT NULL DEFAULT 0,
    gross_kg            DECIMAL(18,3),
    net_kg              DECIMAL(18,3),
    cbm                 DECIMAL(18,3),
    status              VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks             VARCHAR(500),
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY export_shipments_number_uq (number),
    KEY export_shipments_customer_idx (customer_id, status),
    KEY export_shipments_lc_idx (lc_id),
    KEY export_shipments_eta_idx (status, eta),
    KEY export_shipments_currency_idx (currency_id),
    KEY export_shipments_creator_idx (created_by),
    CONSTRAINT export_shipments_customer_fk FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT export_shipments_lc_fk       FOREIGN KEY (lc_id)       REFERENCES letters_of_credit(id),
    CONSTRAINT export_shipments_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT export_shipments_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT export_shipments_mode_chk   CHECK (mode IN ('sea','air','road','courier')),
    CONSTRAINT export_shipments_status_chk CHECK (status IN ('draft','booked','shipped','presented','delivered','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE delivery_challans
    ADD KEY delivery_challans_export_shipment_idx (export_shipment_id),
    ADD CONSTRAINT delivery_challans_export_shipment_fk FOREIGN KEY (export_shipment_id) REFERENCES export_shipments(id)
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE export_documents
    ADD KEY export_documents_shipment_idx (export_shipment_id),
    ADD CONSTRAINT export_documents_shipment_fk FOREIGN KEY (export_shipment_id) REFERENCES export_shipments(id) ON DELETE CASCADE
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `export_documents` DROP FOREIGN KEY `export_documents_shipment_fk`');
        DB::statement('ALTER TABLE `delivery_challans` DROP FOREIGN KEY `delivery_challans_export_shipment_fk`');
        Schema::dropIfExists('export_shipments');
    }
};
