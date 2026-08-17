<?php

declare(strict_types=1);

namespace App\Modules\Finance\States;

use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-1 — the corrective financial document. A credit note walks draft → approved → applied;
 * only `applied` touches the invoice's arithmetic, and it does so under the invoice's row
 * lock with eligibility recalculated inside the transaction — an amount computed before the
 * lock is never trusted.
 *
 * The reconciliation this machine protects:
 *   invoice.total = received_amount + Σ(applied credits) + outstanding
 *
 * Approval follows the PO band pattern: accounts up to `credit_note_approval_band_accounts`,
 * the MD above it.
 *
 * @extends StateMachine<CreditNote>
 */
class CreditNoteStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly Settings $settings,
        private readonly SalesInvoiceStateMachine $invoices,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['approved', 'cancelled'],
            'approved' => ['applied', 'cancelled'],
            'applied' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'approved' => 'credit_note.approve',
            // Applying moves money arithmetic — the same right that allocates receipts.
            'applied' => 'receipt.allocate',
            'cancelled' => 'credit_note.delete',
        ];
    }

    /**
     * @param  CreditNote  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'approved' => $this->guardApproved($document),
            'applied' => $this->guardApplied($document),
            default => null,
        };
    }

    /** 06-rbac §5 — the approver's band must cover the value; above it, only the MD signs. */
    private function guardApproved(CreditNote $note): void
    {
        $band = $this->settings->decimal('credit_note_approval_band_accounts', 50000);

        if ((float) $note->amount <= $band) {
            return;
        }

        $user = auth()->user();

        if ($user === null || ! $user->hasRole('md')) {
            throw TransitionDenied::guard(
                '06-rbac §5',
                sprintf(
                    'This credit note is %s, above the %s band accounts may approve. It needs the Managing Director.',
                    number_format((float) $note->amount, 2),
                    number_format($band, 2),
                ),
            );
        }
    }

    /**
     * The critical guard. Invoice row-locked, eligibility recomputed under the lock:
     * eligible = total − received − Σ(already-applied credits). A concurrent receipt or
     * second application serialises here and sees the truth.
     */
    private function guardApplied(CreditNote $note): void
    {
        if ($note->sales_invoice_id === null) {
            throw TransitionDenied::guard('P2-1', 'A credit note can only be applied against an invoice.');
        }

        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($note->sales_invoice_id);

        if (! in_array($invoice->status, ['issued', 'partially_paid', 'overdue'], true)) {
            throw TransitionDenied::guard(
                'P2-1',
                "Invoice {$invoice->number} is {$invoice->status} — nothing is outstanding to credit against.",
            );
        }

        $eligible = $this->invoices->outstanding($invoice);

        if ((float) $note->amount > $eligible + 0.0001) {
            throw TransitionDenied::guard(
                'P2-1',
                sprintf(
                    'Invoice %s has %s outstanding after receipts and earlier credits — a %s credit would over-credit it.',
                    $invoice->number,
                    number_format($eligible, 2),
                    number_format((float) $note->amount, 2),
                ),
            );
        }
    }

    /**
     * @param  CreditNote  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            // BR-34 — the number is assigned on the first transition out of draft.
            'approved' => $this->onApproved($document),
            'applied' => $this->onApplied($document),
            default => null,
        };
    }

    private function onApproved(CreditNote $note): void
    {
        if ($note->number === null) {
            $note->forceFill(['number' => $this->numbers->next('credit_note')])->save();
        }

        $note->forceFill(['approved_by' => auth()->id()])->save();
    }

    /**
     * The status write in this transition IS the application — appliedCredits() derives from
     * `status = 'applied'` rows, so there is no second column to keep in step. The invoice's
     * payment state then re-derives from money + credits, through its own machine.
     */
    private function onApplied(CreditNote $note): void
    {
        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->findOrFail($note->sales_invoice_id);

        $this->invoices->reflectPayment($invoice);
    }
}
