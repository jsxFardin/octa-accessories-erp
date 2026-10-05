<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Receipt;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Procurement\Models\SupplierBill;
use App\Modules\Procurement\States\SupplierBillStateMachine;
use App\Support\Audit\AuditLogger;
use App\Support\Text\Plain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoing a customer receipt or a supplier payment that should not stand.
 *
 * A cheque bounces; a receipt is keyed against the wrong customer; a payment is entered twice.
 * Until now a posted receipt or payment could not be corrected at all — the status vocabulary
 * had `bounced` and `cancelled`, and nothing could reach either. (UX audit H-27.)
 *
 * Reversing does exactly the opposite of posting, document by document and under the same
 * locks: each allocation is taken back off the invoice or bill it settled, and that document's
 * payment status is derived again from what is left. The receipt or payment itself is kept,
 * with its allocations, marked with what happened and why — the record of money that came and
 * went is not deleted, and the reason is written to the audit trail.
 */
final class SettlementReversal
{
    public function __construct(
        private readonly SalesInvoiceStateMachine $invoices,
        private readonly SupplierBillStateMachine $bills,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  'bounced'|'cancelled'  $outcome
     *
     * @throws ValidationException
     */
    public function reverseReceipt(Receipt $receipt, string $outcome, string $reason): void
    {
        DB::transaction(function () use ($receipt, $outcome, $reason): void {
            /** @var Receipt $locked */
            $locked = Receipt::query()->lockForUpdate()->findOrFail($receipt->getKey());

            if ($locked->status !== 'posted') {
                throw ValidationException::withMessages([
                    'reason' => "Receipt {$locked->number} is already ".Plain::status($locked->status).'.',
                ]);
            }

            $allocations = DB::table('receipt_allocations')->where('receipt_id', $locked->id)->orderBy('id')->get();

            foreach ($allocations as $allocation) {
                /** @var SalesInvoice $invoice */
                $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($allocation->sales_invoice_id);

                $invoice->forceFill([
                    // Never below zero: the figure is money received, and it cannot be negative.
                    'received_amount' => max(0.0, round((float) $invoice->received_amount - (float) $allocation->amount, 4)),
                ])->save();

                $this->invoices->reflectReversal($invoice->refresh());
            }

            $locked->forceFill([
                'status' => $outcome,
                'remarks' => $this->appendReason($locked->remarks, $outcome === 'bounced' ? 'Bounced' : 'Voided', $reason),
            ])->save();

            $this->audit->recordTransition($locked, 'posted', $outcome, ['reason' => $reason]);
        });
    }

    /** @throws ValidationException */
    public function reversePayment(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason): void {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($locked->status !== 'posted') {
                throw ValidationException::withMessages([
                    'reason' => "Payment {$locked->number} is already ".Plain::status($locked->status).'.',
                ]);
            }

            $allocations = DB::table('payment_allocations')->where('payment_id', $locked->id)->orderBy('id')->get();

            foreach ($allocations as $allocation) {
                /** @var SupplierBill $bill */
                $bill = SupplierBill::query()->lockForUpdate()->findOrFail($allocation->supplier_bill_id);

                $bill->forceFill([
                    'paid_amount' => max(0.0, round((float) $bill->paid_amount - (float) $allocation->amount, 4)),
                ])->save();

                $this->bills->reflectReversal($bill->refresh());
            }

            $locked->forceFill([
                'status' => 'cancelled',
                'remarks' => $this->appendReason($locked->remarks, 'Voided', $reason),
            ])->save();

            $this->audit->recordTransition($locked, 'posted', 'cancelled', ['reason' => $reason]);
        });
    }

    private function appendReason(?string $remarks, string $what, string $reason): string
    {
        $note = "{$what} on ".now()->format('d M Y').": {$reason}";

        return mb_substr(trim(($remarks ? $remarks."\n" : '').$note), 0, 500);
    }
}
