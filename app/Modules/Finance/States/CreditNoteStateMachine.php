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
use Illuminate\Support\Facades\DB;

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
            // `refunded` sits beside `applied` rather than after it: both mean the note is
            // spent, and they differ in where the value went — other invoices, or the bank.
            'approved' => ['applied', 'refunded', 'cancelled'],
            'applied' => [],
            'refunded' => [],
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
            // Paying money back is a larger act than moving credit between receivables, so it
            // is its own right rather than another use of `receipt.allocate`.
            'refunded' => 'credit_note.refund',
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
            'refunded' => $this->guardRefunded($document),
            default => null,
        };
    }

    /**
     * 06-rbac §5 — the approver's band must cover the value; above it, only the MD signs.
     *
     * BR-51 — the band is a base-currency figure, so a note raised in another currency has to
     * be converted before it is compared. Left raw, a USD credit note passed an accounts band
     * expressed in taka as though a dollar and a taka were the same size, and accounts could
     * sign alone for a note that needed the Managing Director.
     */
    private function guardApproved(CreditNote $note): void
    {
        $band = $this->settings->decimal('credit_note_approval_band_accounts', 50000);
        $value = $this->baseValue($note);

        if ($value <= $band) {
            return;
        }

        $user = auth()->user();

        if ($user === null || ! $user->hasRole('md')) {
            $code = (string) $this->settings->get('base_currency', 'BDT');

            throw TransitionDenied::guard(
                '06-rbac §5',
                sprintf(
                    'This credit note is worth %s %s, above the %s %s band accounts may approve. It needs the Managing Director.',
                    $code,
                    number_format($value, 2),
                    $code,
                    number_format($band, 2),
                ),
            );
        }
    }

    /**
     * BR-51 — the note's value in the factory's own currency.
     *
     * A credit note records no rate of its own; the documented one is the rate snapshotted on
     * the invoice it credits (BR-22), which is also the rate that invoice was booked at. A note
     * with no invoice behind it is already in the base currency.
     */
    public function baseValue(CreditNote $note): float
    {
        $rate = $note->sales_invoice_id === null
            ? 1.0
            : (float) (DB::table('sales_invoices')->where('id', $note->sales_invoice_id)->value('exchange_rate') ?? 1);

        return round((float) $note->amount * ($rate > 0 ? $rate : 1.0), 4);
    }

    /**
     * The critical guard. Invoice row-locked, eligibility recomputed under the lock:
     * eligible = total − received − Σ(already-applied credits). A concurrent receipt or
     * second application serialises here and sees the truth.
     */
    private function guardApplied(CreditNote $note): void
    {
        // A note whose value has already been consumed through `credit_note_applications` is
        // simply being marked spent, and every check below has already run — once per
        // application, against the invoice that actually took the value, under that invoice's
        // own lock. Re-running them here would ask about the *provenance* invoice, which for a
        // customer return is the paid one the credit could never have gone to.
        //
        // This is not the outstanding rule being relaxed. It is the outstanding rule being
        // asked where the money moved instead of where it came from. The path below is
        // untouched and still governs every note applied the original way.
        if (DB::table('credit_note_applications')->where('credit_note_id', $note->getKey())->exists()) {
            return;
        }

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
     * A note is `refunded` when its value is gone and some of it went back as money. The
     * refund rows are written first, by `RefundService`, under this note's own row lock; this
     * only confirms that nothing is left before the note is called spent.
     */
    private function guardRefunded(CreditNote $note): void
    {
        $refunded = DB::table('refunds')
            ->where('credit_note_id', $note->getKey())
            ->where('status', 'posted')
            ->exists();

        if (! $refunded) {
            throw TransitionDenied::guard(
                'P2-1',
                'No money has been refunded against this credit note.',
            );
        }

        $applied = (float) DB::table('credit_note_applications')
            ->where('credit_note_id', $note->getKey())->sum('amount');

        $paid = (float) DB::table('refunds')
            ->where('credit_note_id', $note->getKey())->where('status', 'posted')->sum('amount');

        if (round((float) $note->amount - $applied - $paid, 4) > 0.0001) {
            throw TransitionDenied::guard(
                'P2-1',
                'This credit note still has value left; it is not spent yet.',
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
        // Already consumed through `credit_note_applications` — each one re-derived its
        // invoice's payment state as it landed. Nothing left to do but be spent.
        if (DB::table('credit_note_applications')->where('credit_note_id', $note->getKey())->exists()) {
            return;
        }

        /** @var SalesInvoice $invoice */
        $invoice = SalesInvoice::query()->findOrFail($note->sales_invoice_id);

        // The direct route: approve a note against an open invoice and apply it whole, which
        // is how every credit note in this system worked before returns existed and how the
        // accounts screen still does it. The status write used to *be* the application;
        // `appliedCredits()` now reads the applications table, so the row that has always been
        // implied is written here instead of inferred.
        DB::table('credit_note_applications')->insert([
            'credit_note_id' => $note->getKey(),
            'sales_invoice_id' => $invoice->getKey(),
            'amount' => round((float) $note->amount, 4),
            'applied_on' => now()->toDateString(),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        $this->invoices->reflectPayment($invoice);
    }
}
