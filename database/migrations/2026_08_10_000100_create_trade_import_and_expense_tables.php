<?php

use App\Support\Schema\SqlScript;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trade finance, import and expenses (docs/02a-schema.sql §13a).
 *
 * The DDL is repeated here rather than read from the schema document, and deliberately: that
 * document is the shape of a *new* database and will keep moving, while this migration is the
 * one step an existing database takes. A database created from the document already has these
 * tables, so every statement is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('letters_of_credit')) {
            return;
        }

        foreach (SqlScript::fromString(self::DDL)->statements() as $statement) {
            DB::unprepared($statement);
        }
    }

    public function down(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        if (Schema::hasColumn('grns', 'import_shipment_id')) {
            DB::statement('ALTER TABLE grns DROP FOREIGN KEY grns_shipment_fk');
            DB::statement('ALTER TABLE grns DROP COLUMN import_shipment_id');
        }

        foreach (array_reverse(SqlScript::fromString(self::DDL)->tables()) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private const DDL = <<<'SQL'
-- =====================================================================
-- 13a. TRADE FINANCE, IMPORT & EXPENSES
--
-- Yarn, ribbon and ink are imported (00-overview §2), which means the cost
-- of a kilo of yarn is not the supplier's rate: it is that rate plus
-- freight, insurance, duty, C&F and bank charges, and none of those are
-- known on the day the PO is raised. These tables carry the documents
-- between the order and the true cost — the letter of credit, the
-- shipment, the costs against it — and end by writing the landed rate onto
-- the GRN line and the lot (BR-36).
--
-- `expenses` is the general factory expense document. It shares the
-- approval shape of the other money documents and is deliberately separate
-- from `import_costs`: an expense is something somebody pays for, an
-- import cost is something a shipment carries.
-- =====================================================================

CREATE TABLE bank_accounts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    bank_name   VARCHAR(120) NOT NULL,
    branch      VARCHAR(120),
    account_no  VARCHAR(60),
    swift_code  VARCHAR(20),
    currency_id BIGINT UNSIGNED NOT NULL,
    kind        VARCHAR(20) NOT NULL DEFAULT 'current',
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY bank_accounts_code_uq (code),
    KEY bank_accounts_currency_idx (currency_id),
    CONSTRAINT bank_accounts_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT bank_accounts_kind_chk CHECK (kind IN ('current','od','lc','cash','fc'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expense_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    kind       VARCHAR(20) NOT NULL DEFAULT 'factory',
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY expense_categories_code_uq (code),
    CONSTRAINT expense_categories_kind_chk CHECK (kind IN ('factory','admin','selling','financial','import'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The credit itself. `number` is ours (BR-34); `lc_no` is the bank's, and
-- only exists once the LC is actually opened.
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
    -- The bank will not accept a shipment date past expiry, so neither do we.
    CONSTRAINT letters_of_credit_dates_chk CHECK (
        expiry_date IS NULL OR last_shipment_date IS NULL OR last_shipment_date <= expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One LC commonly covers several POs to the same supplier.
CREATE TABLE lc_purchase_orders (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lc_id          BIGINT UNSIGNED NOT NULL,
    po_id          BIGINT UNSIGNED NOT NULL,
    covered_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    UNIQUE KEY lc_purchase_orders_uq (lc_id, po_id),
    KEY lc_purchase_orders_po_idx (po_id),
    CONSTRAINT lc_purchase_orders_lc_fk FOREIGN KEY (lc_id) REFERENCES letters_of_credit(id) ON DELETE CASCADE,
    CONSTRAINT lc_purchase_orders_po_fk FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Amendments are appended, never edited into the LC: what the bank charged
-- for and when the date moved is the whole point of the record.
CREATE TABLE lc_amendments (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lc_id                   BIGINT UNSIGNED NOT NULL,
    amendment_no            SMALLINT UNSIGNED NOT NULL,
    amended_on              DATE NOT NULL DEFAULT (CURRENT_DATE),
    amount_delta            DECIMAL(18,4) NOT NULL DEFAULT 0,
    new_expiry_date         DATE,
    new_last_shipment_date  DATE,
    charges_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    narrative               VARCHAR(500),
    created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by              BIGINT UNSIGNED,
    UNIQUE KEY lc_amendments_uq (lc_id, amendment_no),
    KEY lc_amendments_creator_idx (created_by),
    CONSTRAINT lc_amendments_lc_fk      FOREIGN KEY (lc_id)      REFERENCES letters_of_credit(id) ON DELETE CASCADE,
    CONSTRAINT lc_amendments_creator_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE expenses (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    number              VARCHAR(30),
    expense_date        DATE NOT NULL DEFAULT (CURRENT_DATE),
    expense_category_id BIGINT UNSIGNED NOT NULL,
    factory_unit_id     BIGINT UNSIGNED,
    department_id       BIGINT UNSIGNED,
    supplier_id         BIGINT UNSIGNED,
    import_shipment_id  BIGINT UNSIGNED,
    payee               VARCHAR(180) NOT NULL,
    description         VARCHAR(500),
    currency_id         BIGINT UNSIGNED NOT NULL,
    exchange_rate       DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount              DECIMAL(18,4) NOT NULL,
    tax_amount          DECIMAL(18,4) NOT NULL DEFAULT 0,
    total               DECIMAL(18,4) NOT NULL DEFAULT 0,
    method              VARCHAR(20) NOT NULL DEFAULT 'cash',
    bank_account_id     BIGINT UNSIGNED,
    reference_no        VARCHAR(80),
    status              VARCHAR(20) NOT NULL DEFAULT 'draft',
    approved_by         BIGINT UNSIGNED,
    approved_at         DATETIME(3),
    paid_on             DATE,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by          BIGINT UNSIGNED,
    UNIQUE KEY expenses_number_uq (number),
    KEY expenses_date_idx (expense_date, status),
    KEY expenses_category_idx (expense_category_id, expense_date),
    KEY expenses_unit_idx (factory_unit_id),
    KEY expenses_department_idx (department_id),
    KEY expenses_supplier_idx (supplier_id),
    KEY expenses_shipment_idx (import_shipment_id),
    KEY expenses_currency_idx (currency_id),
    KEY expenses_bank_idx (bank_account_id),
    KEY expenses_approver_idx (approved_by),
    KEY expenses_creator_idx (created_by),
    CONSTRAINT expenses_category_fk FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id),
    CONSTRAINT expenses_unit_fk     FOREIGN KEY (factory_unit_id)     REFERENCES factory_units(id),
    CONSTRAINT expenses_dept_fk     FOREIGN KEY (department_id)       REFERENCES departments(id),
    CONSTRAINT expenses_supplier_fk FOREIGN KEY (supplier_id)         REFERENCES suppliers(id),
    CONSTRAINT expenses_shipment_fk FOREIGN KEY (import_shipment_id)  REFERENCES import_shipments(id),
    CONSTRAINT expenses_currency_fk FOREIGN KEY (currency_id)         REFERENCES currencies(id),
    CONSTRAINT expenses_bank_fk     FOREIGN KEY (bank_account_id)     REFERENCES bank_accounts(id),
    CONSTRAINT expenses_approver_fk FOREIGN KEY (approved_by)         REFERENCES users(id),
    CONSTRAINT expenses_creator_fk  FOREIGN KEY (created_by)          REFERENCES users(id),
    CONSTRAINT expenses_method_chk CHECK (method IN ('cash','cheque','bank_transfer','card','adjustment')),
    CONSTRAINT expenses_status_chk CHECK (status IN ('draft','pending_approval','approved','paid','cancelled')),
    CONSTRAINT expenses_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What a shipment costs beyond the goods. `is_allocable` separates the
-- costs that belong in inventory (freight, duty, C&F) from the ones that
-- do not (a demurrage penalty is a period cost, not part of a kilo of yarn).
CREATE TABLE import_costs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id   BIGINT UNSIGNED NOT NULL,
    cost_type     VARCHAR(30) NOT NULL,
    description   VARCHAR(180),
    supplier_id   BIGINT UNSIGNED,
    expense_id    BIGINT UNSIGNED,
    reference_no  VARCHAR(80),
    incurred_on   DATE NOT NULL DEFAULT (CURRENT_DATE),
    currency_id   BIGINT UNSIGNED NOT NULL,
    exchange_rate DECIMAL(18,8) NOT NULL DEFAULT 1,
    amount        DECIMAL(18,4) NOT NULL,
    base_amount   DECIMAL(18,4) NOT NULL DEFAULT 0,
    is_allocable  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED,
    KEY import_costs_shipment_idx (shipment_id, cost_type),
    KEY import_costs_supplier_idx (supplier_id),
    KEY import_costs_expense_idx (expense_id),
    KEY import_costs_currency_idx (currency_id),
    KEY import_costs_creator_idx (created_by),
    CONSTRAINT import_costs_shipment_fk FOREIGN KEY (shipment_id) REFERENCES import_shipments(id) ON DELETE CASCADE,
    CONSTRAINT import_costs_supplier_fk FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT import_costs_expense_fk  FOREIGN KEY (expense_id)  REFERENCES expenses(id),
    CONSTRAINT import_costs_currency_fk FOREIGN KEY (currency_id) REFERENCES currencies(id),
    CONSTRAINT import_costs_creator_fk  FOREIGN KEY (created_by)  REFERENCES users(id),
    CONSTRAINT import_costs_type_chk CHECK (cost_type IN (
        'freight','insurance','duty','vat','advance_income_tax','c_and_f','port',
        'inland_transport','bank_charge','lc_commission','inspection','demurrage','other')),
    CONSTRAINT import_costs_amount_chk CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The audit of BR-36: which cost, spread over which GRN line, on what
-- basis, for how much. Re-running an allocation replaces these rows, so
-- the arithmetic behind a lot's unit cost can always be shown.
CREATE TABLE landed_cost_allocations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id    BIGINT UNSIGNED NOT NULL,
    import_cost_id BIGINT UNSIGNED NOT NULL,
    grn_line_id    BIGINT UNSIGNED NOT NULL,
    stock_lot_id   BIGINT UNSIGNED,
    basis          VARCHAR(20) NOT NULL DEFAULT 'value',
    basis_value    DECIMAL(18,6) NOT NULL DEFAULT 0,
    amount         DECIMAL(18,4) NOT NULL,
    allocated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY landed_cost_allocations_uq (import_cost_id, grn_line_id),
    KEY landed_cost_allocations_shipment_idx (shipment_id),
    KEY landed_cost_allocations_grnline_idx (grn_line_id),
    KEY landed_cost_allocations_lot_idx (stock_lot_id),
    CONSTRAINT landed_cost_allocations_shipment_fk FOREIGN KEY (shipment_id)    REFERENCES import_shipments(id) ON DELETE CASCADE,
    CONSTRAINT landed_cost_allocations_cost_fk     FOREIGN KEY (import_cost_id) REFERENCES import_costs(id) ON DELETE CASCADE,
    CONSTRAINT landed_cost_allocations_grnline_fk  FOREIGN KEY (grn_line_id)    REFERENCES grn_lines(id),
    CONSTRAINT landed_cost_allocations_lot_fk      FOREIGN KEY (stock_lot_id)   REFERENCES stock_lots(id),
    CONSTRAINT landed_cost_allocations_basis_chk CHECK (basis IN ('value','qty'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A GRN that came off a shipment carries the link, so the allocation knows
-- which receipts share the freight bill.
ALTER TABLE grns
    ADD COLUMN import_shipment_id BIGINT UNSIGNED AFTER po_id,
    ADD KEY grns_shipment_idx (import_shipment_id),
    ADD CONSTRAINT grns_shipment_fk FOREIGN KEY (import_shipment_id) REFERENCES import_shipments(id);
SQL;
};
