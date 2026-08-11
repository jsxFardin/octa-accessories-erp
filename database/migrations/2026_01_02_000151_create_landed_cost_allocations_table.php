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
 * The audit of BR-36: which cost, spread over which GRN line, on what
 * basis, for how much. Re-running an allocation replaces these rows, so
 * the arithmetic behind a lot's unit cost can always be shown.
 *
 * Transcribed verbatim from docs/02a-schema.sql, which stays the reference document.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('landed_cost_allocations')) {
            return;
        }

        DB::unprepared(<<<'SQL'
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_allocations');
    }
};
