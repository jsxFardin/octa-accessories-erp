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
        if (Schema::hasTable('expense_categories')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE expense_categories (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL,
    name       VARCHAR(120) NOT NULL,
    kind       VARCHAR(20) NOT NULL DEFAULT 'factory',
    is_active  BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY expense_categories_code_uq (code),
    CONSTRAINT expense_categories_kind_chk CHECK (kind IN ('factory','admin','selling','financial','import'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
