<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 13a. TRADE FINANCE, IMPORT & EXPENSES
 *
 * Yarn, ribbon and ink are imported (00-overview §2), which means the cost
 * of a kilo of yarn is not the supplier's rate: it is that rate plus
 * freight, insurance, duty, C&F and bank charges, and none of those are
 * known on the day the PO is raised. These tables carry the documents
 * between the order and the true cost — the letter of credit, the
 * shipment, the costs against it — and end by writing the landed rate onto
 * the GRN line and the lot (BR-36).
 *
 * `expenses` is the general factory expense document. It shares the
 * approval shape of the other money documents and is deliberately separate
 * from `import_costs`: an expense is something somebody pays for, an
 * import cost is something a shipment carries.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_shipments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE import_shipments (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number            VARCHAR(30),
    lc_id             BIGINT UNSIGNED,
    supplier_id       BIGINT UNSIGNED NOT NULL,
    invoice_no        VARCHAR(60),
    invoice_date      DATE,
    transport_doc_no  VARCHAR(60),
    mode              VARCHAR(20) NOT NULL DEFAULT 'sea',
    carrier           VARCHAR(120),
    etd               DATE,
    eta               DATE,
    arrived_on        DATE,
    cleared_on        DATE,
    bill_of_entry     VARCHAR(60),
    be_date           DATE,
    port_of_loading   VARCHAR(80),
    port_of_discharge VARCHAR(80),
    incoterm          VARCHAR(20),
    currency_id       BIGINT UNSIGNED NOT NULL,
    exchange_rate     DECIMAL(18,8) NOT NULL DEFAULT 1,
    goods_value       DECIMAL(18,4) NOT NULL DEFAULT 0,
    cost_total        DECIMAL(18,4) NOT NULL DEFAULT 0,
    allocated_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    status            VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks           VARCHAR(500),
    created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        BIGINT UNSIGNED,
    UNIQUE KEY import_shipments_number_uq (number),
    KEY import_shipments_supplier_idx (supplier_id, status),
    KEY import_shipments_lc_idx (lc_id),
    KEY import_shipments_eta_idx (status, eta),
    KEY import_shipments_currency_idx (currency_id),
    KEY import_shipments_creator_idx (created_by),
    CONSTRAINT import_shipments_lc_fk       FOREIGN KEY (lc_id)       REFERENCES letters_of_credit(id),
    CONSTRAINT import_shipments_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT import_shipments_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT import_shipments_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT import_shipments_mode_chk   CHECK (mode IN ('sea','air','road','rail','courier')),
    CONSTRAINT import_shipments_status_chk CHECK (status IN ('draft','in_transit','arrived','cleared','costed','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_shipments');
    }
};
