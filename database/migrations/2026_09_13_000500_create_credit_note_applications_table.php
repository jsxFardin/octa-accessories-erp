<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a credit note's value was actually consumed.
 *
 * P2-1 reconciles an invoice as `total = received_amount + Σ(applied credits) + outstanding`,
 * and until now the second term was read straight off `credit_notes.sales_invoice_id`: a note
 * naming an invoice, in status `applied`, *was* an application against it. One column carried
 * both "where did this credit come from" and "where did it go", which worked only because
 * those were always the same invoice.
 *
 * A customer return breaks that. The goods were billed on an invoice the customer has already
 * paid — provenance — and the credit has to go somewhere else entirely, because a paid invoice
 * has nothing left to reduce. Keeping one column would have meant a return credit note reading
 * as applied against an invoice with zero outstanding, driving it negative and breaking the
 * identity everything else reads.
 *
 * So consumption becomes a record of its own, mirroring `receipt_allocations` — which has
 * always modelled exactly this shape for money: one receipt, several invoices, an amount each.
 *
 * **The backfill is the point of this migration, not an afterthought.** `appliedCredits()`
 * starts reading this table in the same commit, so every credit note already applied needs the
 * row that says so before it does. Without it, every invoice settled by credit in the history
 * of the database would silently lose that credit and jump back to outstanding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('credit_note_applications')) {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE credit_note_applications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    credit_note_id   BIGINT UNSIGNED NOT NULL,
    sales_invoice_id BIGINT UNSIGNED NOT NULL,
    amount           DECIMAL(18,4) NOT NULL,
    applied_on       DATE NOT NULL DEFAULT (CURRENT_DATE),
    created_by       BIGINT UNSIGNED,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY credit_note_applications_uq (credit_note_id, sales_invoice_id),
    KEY credit_note_applications_invoice_idx (sales_invoice_id),
    KEY credit_note_applications_creator_idx (created_by),
    CONSTRAINT credit_note_applications_note_fk    FOREIGN KEY (credit_note_id)   REFERENCES credit_notes(id) ON DELETE CASCADE,
    CONSTRAINT credit_note_applications_invoice_fk FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id),
    CONSTRAINT credit_note_applications_creator_fk FOREIGN KEY (created_by)       REFERENCES users(id),
    CONSTRAINT credit_note_applications_amount_chk CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

        // Every note already `applied` was, by the old model's definition, applied in full
        // against the invoice it names. That statement is exactly what this row records, so
        // the backfill is a transcription rather than a guess — and `appliedCredits()` returns
        // the identical figure for every existing invoice the moment it switches over.
        DB::statement(<<<'SQL'
INSERT INTO credit_note_applications (credit_note_id, sales_invoice_id, amount, applied_on, created_at)
SELECT id, sales_invoice_id, amount, DATE(created_at), NOW(3)
FROM credit_notes
WHERE status = 'applied' AND sales_invoice_id IS NOT NULL AND amount > 0
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
    }
};
