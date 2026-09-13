<?php

declare(strict_types=1);

namespace App\Modules\Finance\States;

use App\Modules\Finance\Models\SalesInvoice;
use App\Support\Notifications\Notifier;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §11 — the sales invoice lifecycle, on the schema's own vocabulary
 * (`sales_invoices_status_chk`). Issuing makes the receivable real: credit control reads
 * issued/partially_paid/overdue invoices, so BR-46 exposure starts here. Payment statuses
 * are driven by receipt allocation, never typed.
 *
 * @extends StateMachine<SalesInvoice>
 */
class SalesInvoiceStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly Notifier $notifier,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['issued', 'cancelled'],
            'issued' => ['partially_paid', 'paid', 'credited', 'overdue', 'cancelled'],
            'partially_paid' => ['paid', 'credited', 'overdue'],
            'overdue' => ['partially_paid', 'paid', 'credited'],
            'paid' => [],
            // Terminal, like `paid`, and deliberately not the same word: the money never came.
            'credited' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'issued' => 'sales_invoice.issue',
            'cancelled' => 'sales_invoice.cancel',
            // Payment statuses move as receipts allocate — the allocator's right, not a typist's.
            'partially_paid' => 'receipt.allocate',
            'paid' => 'receipt.allocate',
            // Same permission as `paid`: both are reached by applying money or credit, and
            // `receipt.allocate` is what the credit-note machine itself requires.
            'credited' => 'receipt.allocate',
            'overdue' => 'sales_invoice.update',
        ];
    }

    /**
     * @param  SalesInvoice  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'issued') {
            if (DB::table('sales_invoice_lines')->where('sales_invoice_id', $document->getKey())->doesntExist()) {
                throw TransitionDenied::guard('FN-1', 'An invoice with no lines cannot be issued.');
            }
        }

        if ($to === 'cancelled' && (float) $document->received_amount > 0) {
            throw TransitionDenied::guard(
                'FN-2',
                'Money has been received against this invoice. Reverse the receipts (credit note) instead of cancelling it.',
            );
        }
    }

    /**
     * @param  SalesInvoice  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'issued' => $this->onIssued($document),
            'cancelled' => $this->onCancelled($document, $from),
            'overdue' => $this->onOverdue($document),
            default => null,
        };
    }

    private function onIssued(SalesInvoice $invoice): void
    {
        if ($invoice->number === null) {
            $invoice->forceFill(['number' => $this->numbers->next('sales_invoice')])->save();
        }

        // The order line remembers what has been billed — the third quantity after produced
        // and delivered, from its own authoritative document.
        foreach (DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->get() as $line) {
            if ($line->sales_order_line_id !== null) {
                DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)
                    ->increment('invoiced_qty', (float) $line->qty);
            }
        }
    }

    private function onCancelled(SalesInvoice $invoice, string $from): void
    {
        if ($from !== 'issued') {
            return;
        }

        foreach (DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->getKey())->get() as $line) {
            if ($line->sales_order_line_id !== null) {
                DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)
                    ->decrement('invoiced_qty', (float) $line->qty);
            }
        }
    }

    private function onOverdue(SalesInvoice $invoice): void
    {
        try {
            $this->notifier->notifyInvoiceOverdue($invoice);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * P2-1 — the credit consumed *against this invoice*. Derived from the applications
     * themselves, never cached: there is no second column to drift.
     *
     * This used to read `credit_notes.sales_invoice_id` with `status = 'applied'`, which was
     * correct while a credit note could only ever reduce the invoice it named. A customer
     * return ends that: the goods were billed on an invoice the customer has already paid, so
     * the note names that invoice as **provenance** while its value has to be consumed
     * somewhere with something left to reduce.
     *
     * Reading the old way, a return credit note would have counted as applied against a paid
     * invoice the moment it was approved — `total = received + credited + outstanding` with
     * `received` already equal to `total` leaves `outstanding` negative, and receipts, credit
     * applications and BR-46 exposure all read that figure.
     *
     * `credit_note_applications` was backfilled from every existing applied note in the same
     * migration that created it, so this returns exactly what it always did for data that
     * predates returns.
     */
    public function appliedCredits(SalesInvoice $invoice): float
    {
        return (float) DB::table('credit_note_applications')
            ->where('sales_invoice_id', $invoice->getKey())
            ->sum('amount');
    }

    /**
     * The one formula (P2-1): outstanding = total − received − applied credits.
     * Receipts, credit applications and payment status all read this — nowhere else.
     */
    public function outstanding(SalesInvoice $invoice): float
    {
        return round(
            (float) $invoice->total - (float) $invoice->received_amount - $this->appliedCredits($invoice),
            4,
        );
    }

    /**
     * Money or credit moved; derive the payment status and walk the machine there — never a
     * direct column write. Settled = received + credited covers the total.
     */
    public function reflectPayment(SalesInvoice $invoice): void
    {
        $received = (float) $invoice->received_amount;
        $credited = $this->appliedCredits($invoice);
        $settled = $received + $credited;

        $target = match (true) {
            // Settled by credit alone is a write-off, not a collection. Reporting it as `paid`
            // put a quality claim in the receivables total as money received (P2-1).
            $settled >= (float) $invoice->total - 0.0001 && $received <= 0.0001 && $credited > 0 => 'credited',
            $settled >= (float) $invoice->total - 0.0001 => 'paid',
            $settled > 0 => 'partially_paid',
            default => null,
        };

        if ($target !== null && $invoice->status !== $target) {
            $this->transition($invoice, $target);
        }
    }
}
