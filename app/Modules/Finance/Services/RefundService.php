<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying a credit note back to the customer in money.
 *
 * Posted in one act, with a number, exactly as `ReceiptController` and `PaymentController`
 * create receipts and payments. Those two have no draft step and no state machine, and the
 * reason is written on the receipt controller itself: a draft "would be fiction" — either the
 * money moved or it did not, and a document that claims to be a payment while nothing has left
 * the bank is worse than no document. A refund is the same kind of fact, so it is recorded the
 * same way rather than being made the one money document in the system with a lifecycle.
 *
 * The approval that authorises the money has already happened, on the credit note. Requiring a
 * second one here would ask the same question twice about the same value.
 */
class RefundService
{
    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly CreditNoteApplicationService $applications,
        private readonly CreditNoteStateMachine $notes,
    ) {}

    /**
     * Refund `$amount` of `$note`.
     *
     * @param  array{method?: string, reference_no?: string|null, reason?: string|null}  $details
     *
     * @throws ValidationException when the note or the amount does not qualify
     */
    public function post(CreditNote $note, float $amount, array $details = []): Refund
    {
        return DB::transaction(function () use ($note, $amount, $details): Refund {
            /** @var CreditNote $locked */
            $locked = CreditNote::query()->lockForUpdate()->findOrFail($note->getKey());

            if (! in_array((string) $locked->status, [CreditNote::APPROVED, CreditNote::APPLIED], true)) {
                throw ValidationException::withMessages([
                    'credit_note_id' => "Credit note {$locked->reference()} is {$locked->status} — only an approved note can be refunded.",
                ]);
            }

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'A refund of nothing is not a refund.',
                ]);
            }

            // Recomputed under the note's lock, and counting applications *and* earlier
            // refunds. This is the invariant the whole phase turns on: applications + refunds
            // may never exceed the note. Two requests racing for the last of a note serialise
            // here, and the second sees what the first left.
            $available = $this->applications->available($locked);

            if ($amount > $available + 0.0001) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Credit note %s has %s left — %s would refund more than it is worth.',
                        $locked->reference(),
                        number_format($available, 2),
                        number_format($amount, 2),
                    ),
                ]);
            }

            $refund = Refund::query()->create([
                'number' => $this->numbers->next('refund'),
                'credit_note_id' => $locked->getKey(),
                'customer_id' => $locked->customer_id,
                // BR-55 — a refund is paid in the currency the credit was raised in. There is
                // no conversion here and no rate to record, because none was ever agreed.
                'currency_id' => $locked->currency_id,
                'refund_date' => now()->toDateString(),
                'method' => $details['method'] ?? 'bank_transfer',
                'reference_no' => $details['reference_no'] ?? null,
                'amount' => round($amount, 4),
                'reason' => $details['reason'] ?? null,
                'status' => Refund::POSTED,
                'created_by' => auth()->id(),
            ]);

            // A note with nothing left is spent, and `refunded` rather than `applied` because
            // money left the business — the stronger of the two statements, and the one
            // somebody looking for a refund will search on.
            if ($this->applications->available($locked->refresh()) <= 0.0001
                && $locked->status !== CreditNote::REFUNDED) {
                StateMachine::asSystem(fn () => $this->notes->transition($locked, CreditNote::REFUNDED));
            }

            return $refund;
        });
    }
}
