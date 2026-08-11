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
 * The credit itself. `number` is ours (BR-34); `lc_no` is the bank's, and
 * only exists once the LC is actually opened.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letters_of_credit')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE letters_of_credit (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number             VARCHAR(30),
    lc_no              VARCHAR(60),
    kind               VARCHAR(20) NOT NULL DEFAULT 'sight',
    supplier_id        BIGINT UNSIGNED NOT NULL,
    bank_account_id    BIGINT UNSIGNED,
    currency_id        BIGINT UNSIGNED NOT NULL,
    exchange_rate      DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount             DECIMAL(18,4) NOT NULL DEFAULT 0,
    tolerance_pct      DECIMAL(9,4)  NOT NULL DEFAULT 0,
    margin_pct         DECIMAL(9,4)  NOT NULL DEFAULT 0,
    tenor_days         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    charges_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    applied_on         DATE,
    issued_on          DATE,
    expiry_date        DATE,
    last_shipment_date DATE,
    incoterm           VARCHAR(20),
    port_of_loading    VARCHAR(80),
    port_of_discharge  VARCHAR(80),
    status             VARCHAR(25) NOT NULL DEFAULT 'draft',
    remarks            VARCHAR(500),
    created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by         BIGINT UNSIGNED,
    UNIQUE KEY letters_of_credit_number_uq (number),
    KEY letters_of_credit_supplier_idx (supplier_id, status),
    KEY letters_of_credit_expiry_idx (status, expiry_date),
    KEY letters_of_credit_bank_idx (bank_account_id),
    KEY letters_of_credit_currency_idx (currency_id),
    KEY letters_of_credit_creator_idx (created_by),
    CONSTRAINT letters_of_credit_supplier_fk FOREIGN KEY (supplier_id)     REFERENCES suppliers(id),
    CONSTRAINT letters_of_credit_bank_fk     FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    CONSTRAINT letters_of_credit_currency_fk FOREIGN KEY (currency_id)     REFERENCES currencies(id),
    CONSTRAINT letters_of_credit_creator_fk  FOREIGN KEY (created_by)      REFERENCES users(id),
    CONSTRAINT letters_of_credit_kind_chk   CHECK (kind IN ('sight','usance','back_to_back','tt','da','dp')),
    CONSTRAINT letters_of_credit_status_chk CHECK (status IN ('draft','applied','opened','shipped','retired','closed','cancelled')),
    CONSTRAINT letters_of_credit_amount_chk CHECK (amount >= 0),
    CONSTRAINT letters_of_credit_dates_chk CHECK (
        expiry_date IS NULL OR last_shipment_date IS NULL OR last_shipment_date <= expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('letters_of_credit');
    }
};
