<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which sales return a credit note answers.
 *
 * `credit_notes.sales_invoice_id` says which invoice the credit *came from*. It does not say
 * what happened, and after this feature there are two quite different things it could be: an
 * accounts-raised claim against an open invoice, or the financial half of goods physically
 * coming back. Telling them apart mattered enough to be structural.
 *
 * The alternative was the remarks field, and this project has already learned what that costs.
 * `purchase_orders.rfq_id` exists for exactly this reason: the RFQ → PO link lived only as
 * "Raised from RFQ RFQ-26-00001" in prose, no guard could parse it, and the three-quote rule
 * was therefore unenforceable on the route that did not use the RFQ screen. A link a rule may
 * need to read is a foreign key, not a sentence.
 *
 * Nullable, because most credit notes have no return behind them — a rate difference, a
 * discount, a quality claim settled without goods moving — and the challan-return drafter that
 * already existed writes none either. Null means "no goods came back for this", which is the
 * ordinary case rather than missing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('credit_notes', 'sales_return_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE credit_notes
    ADD COLUMN sales_return_id BIGINT UNSIGNED NULL AFTER sales_invoice_id,
    ADD KEY credit_notes_return_idx (sales_return_id),
    ADD CONSTRAINT credit_notes_return_fk
        FOREIGN KEY (sales_return_id) REFERENCES sales_returns(id) ON DELETE SET NULL
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('credit_notes', 'sales_return_id')) {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE credit_notes
    DROP FOREIGN KEY credit_notes_return_fk,
    DROP KEY credit_notes_return_idx,
    DROP COLUMN sales_return_id
SQL);
    }
};
