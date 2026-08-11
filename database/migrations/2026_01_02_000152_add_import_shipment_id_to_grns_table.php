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
 * A GRN that came off a shipment carries the link, so the allocation knows
 * which receipts share the freight bill.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('grns', 'import_shipment_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE grns
    ADD COLUMN import_shipment_id BIGINT UNSIGNED AFTER po_id,
    ADD KEY grns_shipment_idx (import_shipment_id),
    ADD CONSTRAINT grns_shipment_fk FOREIGN KEY (import_shipment_id) REFERENCES import_shipments(id)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `grns` DROP FOREIGN KEY `grns_shipment_fk`');
        DB::statement('ALTER TABLE `grns` DROP INDEX `grns_shipment_idx`');
        DB::statement('ALTER TABLE `grns` DROP COLUMN `import_shipment_id`');
    }
};
