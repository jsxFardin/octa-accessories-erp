<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-2 AC4 — records which RFQ a purchase order was raised from.
 *
 * The requirement is that three supplier quotations exist **before PO approval** above a value
 * threshold. The quotations live on `supplier_quotations.rfq_id`, and nothing on the order said
 * which RFQ — the RFQ → PO path wrote "Raised from RFQ RFQ-26-00001" into `remarks`, a free-text
 * field no guard can safely parse. So the rule was enforceable only at the moment a winner was
 * selected, and an order raised directly (which the application fully supports) had no
 * quotation requirement at all: a purchase manager could take the direct route and approve at
 * any value with nothing to compare against.
 *
 * Nullable, because an order raised without an RFQ is legitimate and common — a repeat order, a
 * sole-source item, an urgent replacement. Null means "no RFQ behind this", which the guard
 * reads as zero quotations; above the threshold that order needs a documented override reason
 * rather than being impossible.
 *
 * Existing rows are left NULL rather than reverse-engineered from `remarks`: parsing a document
 * number out of prose and writing it back as a foreign key is a guess recorded as a fact, and
 * every already-approved order keeps its approval regardless — the guard runs on the transition
 * into `approved`, not retrospectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_orders', 'rfq_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE purchase_orders
    ADD COLUMN rfq_id BIGINT UNSIGNED NULL AFTER supplier_id,
    ADD KEY purchase_orders_rfq_idx (rfq_id),
    ADD CONSTRAINT purchase_orders_rfq_fk
        FOREIGN KEY (rfq_id) REFERENCES supplier_rfqs(id) ON DELETE SET NULL
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_orders', 'rfq_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE purchase_orders
    DROP FOREIGN KEY purchase_orders_rfq_fk,
    DROP KEY purchase_orders_rfq_idx,
    DROP COLUMN rfq_id
SQL);
    }
};
