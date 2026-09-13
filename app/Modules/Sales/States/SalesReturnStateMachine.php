<?php

declare(strict_types=1);

namespace App\Modules\Sales\States;

use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\Services\SalesReturnCreditService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The customer return of delivered, invoiced goods — draft, approved, posted.
 *
 * Shaped after `StockAdjustmentStateMachine`, the project's other approve-then-post inventory
 * document: drafting and approving write no stock, `posted` is the effect that does, and
 * `posted` is terminal because the ledger is append-only (a mistake is corrected by an
 * adjustment, never by unposting).
 *
 * **The invoice is read, never written.** Nothing here transitions the invoice, touches
 * `received_amount`, or edits a receipt allocation. A paid invoice that has been returned
 * against is still paid, still shows the money that arrived, and still reconciles to
 * `total = received + applied credits + outstanding`. The credit that answers the return is a
 * separate document, raised in a later phase.
 *
 * @extends StateMachine<SalesReturn>
 */
class SalesReturnStateMachine extends StateMachine
{
    /**
     * Invoice states a return may be raised against.
     *
     * Everything except `draft` — which has no number and has billed nothing, so there is no
     * invoiced quantity to return against — and `cancelled`, which bills nothing either.
     *
     * `paid` and `credited` are deliberately in the list. They are terminal *for the invoice*,
     * which is the whole point: the goods were delivered, billed and settled, and they still
     * came back. Refusing the return there is what left the business with no way to record it.
     */
    public const RETURNABLE_INVOICE_STATUSES = [
        'issued',
        'partially_paid',
        'overdue',
        'paid',
        'credited',
    ];

    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly SalesReturnPostingService $stock,
        private readonly SalesReturnCreditService $credits,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            SalesReturn::DRAFT => [SalesReturn::APPROVED, SalesReturn::CANCELLED],
            SalesReturn::APPROVED => [SalesReturn::POSTED, SalesReturn::CANCELLED],
            SalesReturn::POSTED => [],
            SalesReturn::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            SalesReturn::APPROVED => 'sales_return.approve',
            SalesReturn::POSTED => 'sales_return.post',
            SalesReturn::CANCELLED => 'sales_return.update',
        ];
    }

    /**
     * @param  SalesReturn  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        // The StockAdjustment pattern: re-read under a row lock and re-check the status we
        // were told we were leaving. Two requests that both read `approved` and both decide
        // to post serialise here, and the second one finds `posted` and is refused — rather
        // than both running the effect and returning the goods twice.
        /** @var SalesReturn $locked */
        $locked = SalesReturn::query()->lockForUpdate()->findOrFail($document->getKey());

        $current = (string) $locked->getAttribute($this->statusColumn());

        if ($current !== $from) {
            throw TransitionDenied::notAllowed('SalesReturn', $current, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        if ($to === SalesReturn::APPROVED || $to === SalesReturn::POSTED) {
            // Quantities are checked at both steps and for different reasons. At approval it
            // is courtesy — the approver hears about an over-return before they sign. At
            // posting it is the rule, taken under locks, because posting is the only step
            // that consumes the returnable balance and two approved returns can race for the
            // same remainder.
            $this->guardInvoice($locked);
            $this->guardLines($locked, lockLines: $to === SalesReturn::POSTED);
        }

        if ($to === SalesReturn::POSTED) {
            // Asked here rather than inside the posting loop: half a return in the ledger is
            // worse than none, so every line is checked before the first one moves.
            $this->stock->assertPostable($locked);
        }
    }

    /**
     * The invoice this return names has to be one that billed something, and it has to belong
     * to the customer the return says it does.
     */
    private function guardInvoice(SalesReturn $return): void
    {
        /** @var SalesInvoice|null $invoice */
        $invoice = SalesInvoice::query()->find($return->sales_invoice_id);

        if ($invoice === null) {
            throw TransitionDenied::guard('SR-1', 'This return names an invoice that does not exist.');
        }

        if ($invoice->number === null || $invoice->status === 'draft') {
            throw TransitionDenied::guard(
                'SR-1',
                'A draft invoice has billed nothing, so there is nothing to return against it.',
            );
        }

        if (! in_array((string) $invoice->status, self::RETURNABLE_INVOICE_STATUSES, true)) {
            throw TransitionDenied::guard(
                'SR-1',
                "Invoice {$invoice->number} is {$invoice->status} — goods cannot be returned against it.",
            );
        }

        if ((int) $invoice->customer_id !== (int) $return->customer_id) {
            throw TransitionDenied::guard(
                'SR-2',
                "Invoice {$invoice->number} belongs to a different customer.",
            );
        }
    }

    /**
     * SR-3 — a line may not return more than it was billed, net of what has already come back.
     *
     * The returnable balance is **derived** from posted return lines rather than read from
     * `sales_invoice_lines.returned_qty`. The column is a cache for screens; a rule decided on
     * a cache is a rule decided on whatever last wrote it. The same reasoning the credit note
     * applies when it recomputes eligibility under the invoice's lock instead of trusting an
     * amount computed before it.
     */
    private function guardLines(SalesReturn $return, bool $lockLines): void
    {
        $lines = SalesReturnLine::query()
            ->where('sales_return_id', $return->getKey())
            ->orderBy('line_no')
            ->get();

        if ($lines->isEmpty()) {
            throw TransitionDenied::guard('SR-3', 'A return with no lines cannot be approved.');
        }

        $invoiceLineIds = $lines->pluck('sales_invoice_line_id')->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();

        // Locked in a stable id order so two returns touching the same pair of lines cannot
        // deadlock by taking them in opposite orders.
        $invoiceLines = $this->invoiceLines($invoiceLineIds->all(), $lockLines);

        $alreadyReturned = $this->postedReturnQuantities($invoiceLineIds->all(), (int) $return->getKey());

        /** @var array<int, float> $claimed */
        $claimed = [];

        foreach ($lines as $line) {
            $qty = (float) $line->qty;
            $invoiceLineId = (int) $line->sales_invoice_line_id;

            if ($qty <= 0.000001) {
                throw TransitionDenied::guard('SR-3', 'A return line of zero is not a return.');
            }

            $invoiceLine = $invoiceLines[$invoiceLineId] ?? null;

            if ($invoiceLine === null) {
                throw TransitionDenied::guard('SR-3', "Invoice line #{$invoiceLineId} does not exist.");
            }

            // SR-4 — the line has to be on the invoice the return names. Without this a return
            // could borrow another invoice's line and quietly credit against quantities this
            // customer was never billed for.
            if ((int) $invoiceLine->sales_invoice_id !== (int) $return->sales_invoice_id) {
                throw TransitionDenied::guard(
                    'SR-4',
                    "Line #{$invoiceLineId} is not on the invoice this return is raised against.",
                );
            }

            $claimed[$invoiceLineId] = ($claimed[$invoiceLineId] ?? 0.0) + $qty;

            $billed = (float) $invoiceLine->qty;
            $returned = $alreadyReturned[$invoiceLineId] ?? 0.0;
            $available = $billed - $returned;

            if ($claimed[$invoiceLineId] > $available + 0.000001) {
                throw TransitionDenied::guard('SR-3', sprintf(
                    'Line %d was billed %s and %s has already been returned, so only %s can come back — this return asks for %s.',
                    (int) $invoiceLine->line_no,
                    $this->formatQty($billed),
                    $this->formatQty($returned),
                    $this->formatQty(max(0, $available)),
                    $this->formatQty($claimed[$invoiceLineId]),
                ));
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function invoiceLines(array $ids, bool $lock): array
    {
        if ($ids === []) {
            return [];
        }

        $query = DB::table('sales_invoice_lines')
            ->whereIn('id', $ids)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = [];

        foreach ($query->get(['id', 'sales_invoice_id', 'line_no', 'qty', 'rate_per_m']) as $row) {
            $rows[(int) $row->id] = $row;
        }

        return $rows;
    }

    /**
     * What has already come back against these invoice lines, counting posted returns only —
     * and never this return itself, so re-running the guard on a document that is mid-flight
     * does not measure it against its own quantities.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function postedReturnQuantities(array $ids, int $exceptReturnId): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('sales_return_lines as srl')
            ->join('sales_returns as sr', 'sr.id', '=', 'srl.sales_return_id')
            ->whereIn('srl.sales_invoice_line_id', $ids)
            ->where('sr.status', SalesReturn::POSTED)
            ->where('sr.id', '!=', $exceptReturnId)
            ->groupBy('srl.sales_invoice_line_id')
            ->selectRaw('srl.sales_invoice_line_id AS invoice_line_id, SUM(srl.qty) AS qty')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->invoice_line_id] = (float) $row->qty;
        }

        return $totals;
    }

    /**
     * @param  SalesReturn  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            SalesReturn::APPROVED => $this->onApproved($document),
            SalesReturn::POSTED => $this->onPosted($document),
            default => null,
        };
    }

    /** BR-34 — numbered on the first transition out of draft, never on form open. */
    private function onApproved(SalesReturn $return): void
    {
        if ($return->number === null) {
            $return->forceFill(['number' => $this->numbers->next('sales_return')])->save();
        }

        $return->forceFill(['approved_by' => auth()->id()])->save();
    }

    /**
     * Posting consumes the returnable balance and puts the goods back.
     *
     * All three happen inside the state machine's own transaction, so a stock movement that
     * fails takes the quantity, the credit note, the status change and the audit row back with
     * it. Goods back in stock with no credit drafted, or a credit drafted for goods that never
     * arrived, are both worse than the return simply being refused.
     *
     * The credit note is a **draft**. Posting a return moves no money — it says what came back
     * and what that was worth. Accounts approve it, and where the value is finally consumed is
     * a later question with a record of its own.
     */
    private function onPosted(SalesReturn $return): void
    {
        foreach ($return->lines()->get() as $line) {
            DB::table('sales_invoice_lines')
                ->where('id', $line->sales_invoice_line_id)
                ->increment('returned_qty', (float) $line->qty);
        }

        $this->stock->post($return);
        $this->credits->draftFor($return);
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
