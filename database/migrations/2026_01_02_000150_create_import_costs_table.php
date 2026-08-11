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
 * What a shipment costs beyond the goods. `is_allocable` separates the
 * costs that belong in inventory (freight, duty, C&F) from the ones that
 * do not (a demurrage penalty is a period cost, not part of a kilo of yarn).
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('import_costs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_costs');
    }
};
