<?php

declare(strict_types=1);

namespace App\Modules\Sales\States;

use App\Modules\Costing\Models\CostSheet;
use App\Modules\Sales\Models\Quotation;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * 05-workflows §2.
 *
 * A `sent` quotation is immutable — there is no edit path, only a revision. Sending is the
 * moment Q1 takes effect: rates, overheads, margin and exchange rate are snapshotted and the
 * cost sheets lock, so later master-data changes cannot alter a document already in a
 * customer's inbox.
 *
 * @extends StateMachine<Quotation>
 */
class QuotationStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['sent', 'cancelled'],
            'sent' => ['accepted', 'rejected', 'revised', 'expired'],
            'accepted' => [],
            'rejected' => [],
            'revised' => [],
            'expired' => ['revised'],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'sent' => 'quotation.send',
            'accepted' => 'quotation.accept',
            'rejected' => 'quotation.reject',
            'revised' => 'quotation.revise',
            'expired' => 'quotation.update',
            'cancelled' => 'quotation.update',
        ];
    }

    /**
     * @param  Quotation  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'sent') {
            $lines = $document->lines()->get();

            if ($lines->isEmpty()) {
                throw TransitionDenied::guard('Q1', 'A quotation with no lines cannot be sent.');
            }

            $unpriced = $lines->filter(fn ($line): bool => (float) $line->rate_per_m <= 0);

            if ($unpriced->isNotEmpty()) {
                throw TransitionDenied::guard(
                    'Q2',
                    'Every line needs a rate per 1000 pieces: line '
                    .$unpriced->pluck('line_no')->join(', ').' has none.',
                );
            }

            $withoutSheet = $lines->filter(
                fn ($line): bool => ! CostSheet::query()->where('quotation_line_id', $line->id)->exists(),
            );

            if ($withoutSheet->isNotEmpty()) {
                throw TransitionDenied::guard(
                    'Q1',
                    'Every line needs a cost sheet before sending: line '
                    .$withoutSheet->pluck('line_no')->join(', ').' has none.',
                );
            }
        }

        if ($to === 'rejected' && blank($context['reject_reason'] ?? null)) {
            throw TransitionDenied::guard('BR-33', 'A rejection needs a reason; it feeds win/loss analysis.');
        }
    }

    /**
     * @param  Quotation  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'sent' => $this->onSent($document),
            'accepted' => $document->forceFill(['decided_at' => now()])->save(),
            'rejected' => $document->forceFill([
                'decided_at' => now(),
                'reject_reason' => $context['reject_reason'],
            ])->save(),
            default => null,
        };
    }

    private function onSent(Quotation $quotation): void
    {
        if ($quotation->number === null) {
            $quotation->forceFill(['number' => $this->numbers->next('quotation')])->save();
        }

        $quotation->forceFill(['sent_at' => now()])->save();

        // Q1 — the snapshot becomes read-only here, and stays that way for the life of the
        // document. Reprinting a two-year-old quotation must reproduce the numbers it carried.
        CostSheet::query()
            ->whereIn('quotation_line_id', $quotation->lines()->select('id'))
            ->update(['is_locked' => true]);
    }
}
