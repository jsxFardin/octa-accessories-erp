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
        if (Schema::hasTable('expenses')) {
            return;
        }

        DB::unprepared(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
