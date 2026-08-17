<?php

declare(strict_types=1);

namespace App\Modules\Finance\States;

use App\Modules\Finance\Models\SalesInvoice;
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
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['issued', 'cancelled'],
            'issued' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
            'partially_paid' => ['paid', 'overdue'],
            'overdue' => ['partially_paid', 'paid'],
            'paid' => [],
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

    /**
     * Receipt allocation moved the received amount; derive the payment status and walk the
     * machine there — never a direct column write.
     */
    public function reflectPayment(SalesInvoice $invoice): void
    {
        $target = match (true) {
            (float) $invoice->received_amount >= (float) $invoice->total - 0.0001 => 'paid',
            (float) $invoice->received_amount > 0 => 'partially_paid',
            default => null,
        };

        if ($target !== null && $invoice->status !== $target) {
            $this->transition($invoice, $target);
        }
    }
}
