<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consuming a credit note's value against an invoice.
 *
 * The shape is `ReceiptController`'s, deliberately: an amount of money, an invoice to put it
 * against, both rows locked, eligibility recomputed *inside* the lock, and the invoice's
 * payment status re-derived through its own state machine rather than written directly. A
 * credit reduces what is owed in exactly the way a receipt does; the only difference is where
 * the value came from.
 *
 * What is new is that the invoice being reduced need not be the invoice the credit came from.
 * `credit_notes.sales_invoice_id` is provenance — which sale produced this credit — and it no
 * longer implies consumption. That is what makes a return against a paid invoice expressible:
 * the credit exists, the paid invoice stays paid, and the value goes to an invoice that still
 * has something to reduce.
 */
class CreditNoteApplicationService
{
    public function __construct(
        private readonly SalesInvoiceStateMachine $invoices,
        private readonly CreditNoteStateMachine $notes,
    ) {}

    /**
     * Value on a note that is neither applied nor refunded yet.
     *
     * Derived, like every other balance in this module — there is no cached column to drift.
     * Both legs are counted here rather than in two places, so the invariant
     *
     *     applications + refunds <= note amount
     *
     * is enforced by one calculation whichever way the value is being consumed. A refund and an
     * application racing for the last of a note both read this under the note's row lock.
     */
    public function available(CreditNote $note): float
    {
        $applied = (float) DB::table('credit_note_applications')
            ->where('credit_note_id', $note->getKey())
            ->sum('amount');

        $refunded = (float) DB::table('refunds')
            ->where('credit_note_id', $note->getKey())
            ->where('status', Refund::POSTED)
            ->sum('amount');

        return round((float) $note->amount - $applied - $refunded, 4);
    }

    /**
     * BR-46 — credit this customer holds and has not spent, in the factory's own currency.
     *
     * Exposure is what a customer owes the business. An approved credit note they have not yet
     * consumed is the business owing *them*, so leaving it out overstates exposure by its whole
     * value: a customer sitting on a large return credit could be refused an order against a
     * limit they were, on balance, nowhere near.
     *
     * Only `approved` notes count. A draft has not been agreed, and `applied`/`refunded` notes
     * are spent — their value already shows up as a reduced invoice balance or as money that
     * has left the bank, and counting them here as well would subtract it twice.
     *
     * BR-51 — converted the way every other commercial figure is, at the rate the document that
     * produced it snapshotted (BR-22), never a live rate. `baseValue()` on the note's own state
     * machine owns that conversion, so there is one rule rather than two.
     */
    public function availableForCustomer(int $customerId): float
    {
        $notes = CreditNote::query()
            ->where('customer_id', $customerId)
            ->where('status', CreditNote::APPROVED)
            ->get();

        $available = 0.0;

        foreach ($notes as $note) {
            $remaining = $this->available($note);

            if ($remaining <= 0.0001) {
                continue;
            }

            $rate = (float) $note->amount > 0
                ? $this->notes->baseValue($note) / (float) $note->amount
                : 1.0;

            $available += $remaining * $rate;
        }

        return round($available, 4);
    }

    /**
     * Apply `$amount` of `$note` to `$invoice`.
     *
     * @throws ValidationException when the note, the invoice or the amount does not qualify
     */
    public function apply(CreditNote $note, SalesInvoice $invoice, float $amount): void
    {
        DB::transaction(function () use ($note, $invoice, $amount): void {
            // Lock order is note then invoice, everywhere, so two applications touching the
            // same pair cannot take them in opposite orders and deadlock.
            /** @var CreditNote $lockedNote */
            $lockedNote = CreditNote::query()->lockForUpdate()->findOrFail($note->getKey());

            /** @var SalesInvoice $lockedInvoice */
            $lockedInvoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $this->assertNoteUsable($lockedNote);
            $this->assertInvoiceUsable($lockedNote, $lockedInvoice);

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'An application of nothing is not an application.',
                ]);
            }

            // Recomputed under the lock, never trusted from before it — the same reason
            // `guardApplied()` recalculates eligibility rather than accepting a figure worked
            // out a moment earlier. Two applications racing for the last of a note serialise
            // here and the second one sees what the first left.
            $available = $this->available($lockedNote);

            if ($amount > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Credit note %s has %s left to apply — %s would over-apply it.',
                        $lockedNote->number ?? '(draft)',
                        number_format($available, 2),
                        number_format($amount, 2),
                    ),
                ]);
            }

            $outstanding = $this->invoices->outstanding($lockedInvoice);

            if ($amount > $outstanding + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Invoice %s has %s outstanding — %s would over-credit it.',
                        $lockedInvoice->number,
                        number_format($outstanding, 2),
                        number_format($amount, 2),
                    ),
                ]);
            }

            DB::table('credit_note_applications')->insert([
                'credit_note_id' => $lockedNote->getKey(),
                'sales_invoice_id' => $lockedInvoice->getKey(),
                'amount' => round($amount, 4),
                'applied_on' => now()->toDateString(),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            // The invoice's payment state is derived from money plus credit, through its own
            // machine. Never a status write from here.
            $this->invoices->reflectPayment($lockedInvoice->refresh());

            // A note with nothing left is spent. The status change is the consequence of the
            // last application, not a separate decision, so the system makes it: `applied`
            // needs `receipt.allocate`, which the person applying already had to hold to get
            // this far.
            if ($this->available($lockedNote->refresh()) <= 0.0001 && $lockedNote->status !== CreditNote::APPLIED) {
                StateMachine::asSystem(fn () => $this->notes->transition($lockedNote, CreditNote::APPLIED));
            }
        });
    }

    private function assertNoteUsable(CreditNote $note): void
    {
        if (! in_array((string) $note->status, ['approved', 'applied'], true)) {
            throw ValidationException::withMessages([
                'credit_note_id' => "Credit note {$note->reference()} is {$note->status} — only an approved note can be applied.",
            ]);
        }
    }

    private function assertInvoiceUsable(CreditNote $note, SalesInvoice $invoice): void
    {
        // The same three states a receipt may be allocated to. A paid or cancelled invoice has
        // nothing to reduce, and crediting it would be the very defect this design exists to
        // avoid — stated here as a rule rather than left to the arithmetic.
        if (! in_array((string) $invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => "Invoice {$invoice->number} is {$invoice->status} — it has nothing outstanding to credit.",
            ]);
        }

        if ((int) $invoice->customer_id !== (int) $note->customer_id) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => "Invoice {$invoice->number} belongs to a different customer.",
            ]);
        }

        // BR-57 — credit is allocated within one currency, exactly as money is. A note carries
        // no rate of its own, so applying it across currencies would need a rate nobody
        // recorded and would restate the credit every time it was read.
        if ((int) $invoice->currency_id !== (int) $note->currency_id) {
            throw ValidationException::withMessages([
                'sales_invoice_id' => sprintf(
                    'Credit note %s is in a different currency from invoice %s.',
                    $note->reference(),
                    $invoice->number,
                ),
            ]);
        }
    }
}
