<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Finance\Models\CreditNote;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Facades\DB;

/**
 * The financial half of a customer return: a draft credit note for what came back.
 *
 * **Draft, and only draft.** Creating it changes no money. Nothing about the invoice moves,
 * nothing is credited, and the note waits for accounts exactly as one raised by hand does —
 * `CreditNote::booted()` sends the same approval notification either way, which is why this
 * creates the row the same way rather than through a private path of its own.
 *
 * The invoice recorded on the note is the one the goods were billed on. That is **provenance**:
 * where this credit came from. It is deliberately not a statement that the credit has been
 * applied to that invoice, and on a paid invoice it cannot be — `guardApplied()` refuses to
 * apply credit to an invoice with nothing outstanding, and this phase leaves that guard exactly
 * as it is. Where the credit is eventually consumed is a separate question, answered by a
 * separate record, and until that record exists the honest answer is "nowhere yet".
 */
class SalesReturnCreditService
{
    /**
     * Draft the credit note for a posted return. Returns null when there is nothing to credit.
     *
     * Runs inside the return's own posting transaction, so a note is never left behind by a
     * posting that failed.
     */
    public function draftFor(SalesReturn $return): ?CreditNote
    {
        $invoice = DB::table('sales_invoices')
            ->where('id', $return->sales_invoice_id)
            ->first(['id', 'number', 'currency_id', 'status']);

        // The guard on the transition already refused a draft or cancelled invoice, so this is
        // belt and braces rather than the rule — but a credit note against an invoice that
        // billed nothing would be a credit for money never charged.
        if ($invoice === null || $invoice->number === null) {
            return null;
        }

        $amount = $this->value($return);

        if ($amount <= 0) {
            return null;
        }

        return CreditNote::query()->create([
            'customer_id' => $return->customer_id,
            'sales_invoice_id' => $invoice->id,
            'sales_return_id' => $return->getKey(),
            'note_date' => now()->toDateString(),
            'reason' => 'return',
            // BR-22 — the credit is worth what the invoice charged, in the currency it charged
            // it in. A note carries no rate of its own; `CreditNoteStateMachine::baseValue()`
            // reads the invoice's snapshotted one, which is why the two must agree.
            'currency_id' => $invoice->currency_id,
            'amount' => $amount,
            'status' => 'draft',
            'remarks' => "Goods returned on {$return->reference()} against invoice {$invoice->number}.",
        ]);
    }

    /**
     * BR-1 — what came back is worth what it was billed at: the per-1000 rate snapshotted onto
     * the return line from the invoice line, not a live price-list lookup months later.
     */
    private function value(SalesReturn $return): float
    {
        $amount = 0.0;

        foreach ($return->lines()->get() as $line) {
            $amount += $line->value();
        }

        return round($amount, 4);
    }
}
