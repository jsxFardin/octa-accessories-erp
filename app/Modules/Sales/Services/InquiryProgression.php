<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\States\InquiryStateMachine;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §1 — the inquiry follows its quotations.
 *
 * ```
 * draft → open  : submit
 * open  → quoted: a quotation exists
 * quoted → won  : a quotation was accepted
 * quoted → lost : the quotation was rejected and nothing else is still open
 * ```
 *
 * The diagram was drawn and never wired up: an inquiry answered by an accepted quotation —
 * one of them already carrying a sales order — still read `open` on the list, while older
 * inquiries read `won` because the demo seeder set the column by hand. The funnel report and
 * the merchandiser's follow-up list both read that column, so both were wrong.
 *
 * This is not a second state machine. The inquiry's own table lives in `InquiryStateMachine`;
 * what lives here is the *consequence* on the inquiry of something happening to its
 * quotation, called from `QuotationStateMachine`'s effect and from quotation creation. It
 * only ever moves an inquiry forward along the documented path, through that machine.
 */
class InquiryProgression
{
    /** Statuses a follow-on may still move. `won`, `lost` and `cancelled` are decided. */
    private const MOVEABLE = [Inquiry::DRAFT, Inquiry::OPEN, Inquiry::QUOTED];

    /** A quotation that has not been answered yet, so the inquiry is not lost while it stands. */
    private const OPEN_QUOTATION_STATUSES = ['draft', 'sent', 'accepted'];

    public function __construct(private readonly InquiryStateMachine $states) {}

    /** A quotation was raised against this inquiry: `open` → `quoted`. */
    public function quoted(Quotation $quotation): void
    {
        $this->move($quotation, Inquiry::QUOTED);
    }

    /** The customer accepted: `quoted` → `won`. The order, if any, follows later (Q3/Q5). */
    public function won(Quotation $quotation): void
    {
        $this->move($quotation, Inquiry::WON);
    }

    /**
     * The customer rejected. The inquiry is only lost once nothing else is still standing —
     * a rejected revision beside an accepted one has not lost the customer.
     */
    public function lost(Quotation $quotation, ?string $reason): void
    {
        $stillOpen = Quotation::query()
            ->where('inquiry_id', $quotation->inquiry_id)
            ->whereKeyNot($quotation->getKey())
            ->whereIn('status', self::OPEN_QUOTATION_STATUSES)
            ->exists();

        if ($stillOpen) {
            return;
        }

        $this->move($quotation, Inquiry::LOST, $reason);
    }

    private function move(Quotation $quotation, string $to, ?string $lostReason = null): void
    {
        if ($quotation->inquiry_id === null) {
            return;
        }

        $inquiry = Inquiry::query()->lockForUpdate()->find($quotation->inquiry_id);

        if ($inquiry === null || ! in_array($inquiry->status, self::MOVEABLE, true) || $inquiry->status === $to) {
            return;
        }

        // Through the inquiry's own machine, as the system: the move is a consequence of what
        // happened to the quotation, not an act by whoever decided the quotation, so no
        // inquiry permission is charged — but the table, the guards and the audit row are the
        // same ones a hand-made transition gets.
        StateMachine::asSystem(fn () => $this->states->transition($inquiry, $to, [
            'lost_reason' => $to === Inquiry::LOST
                ? ($lostReason ?: 'Quotation '.$quotation->reference().' rejected')
                : null,
            'because' => 'quotation',
            'quotation' => $quotation->reference(),
            'quotation_id' => $quotation->getKey(),
        ]));
    }

    /**
     * The status an inquiry *should* hold, given its quotations — used by the backfill and by
     * the test that proves the two agree. Null when nothing about its quotations decides it.
     */
    public function derivedStatusFor(int $inquiryId): ?string
    {
        $statuses = DB::table('quotations')->where('inquiry_id', $inquiryId)->pluck('status');

        if ($statuses->isEmpty()) {
            return null;
        }

        if ($statuses->contains('accepted')) {
            return Inquiry::WON;
        }

        if ($statuses->intersect(self::OPEN_QUOTATION_STATUSES)->isNotEmpty()) {
            return Inquiry::QUOTED;
        }

        return $statuses->contains('rejected') ? Inquiry::LOST : Inquiry::QUOTED;
    }
}
