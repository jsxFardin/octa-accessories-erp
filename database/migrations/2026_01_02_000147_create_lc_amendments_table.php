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
 * Amendments are appended, never edited into the LC: what the bank charged
 * for and when the date moved is the whole point of the record.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lc_amendments')) {
            return;
        }

        DB::unprepared(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_amendments');
    }
};
