<?php

declare(strict_types=1);

namespace App\Modules\Sales\States;

use App\Modules\Sales\Models\Inquiry;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * Inquiry lifecycle (05-workflows §1).
 *
 * The controller used to write whatever status the form sent, from any status to any other,
 * behind `inquiry.view`. A won inquiry could be put back to draft by anyone who could open
 * it, with no transition on the audit trail. The table below is the spec's diagram; the
 * permissions are the catalogue's two inquiry verbs.
 *
 * @extends StateMachine<Inquiry>
 */
class InquiryStateMachine extends StateMachine
{
    public function __construct(AuditLogger $audit, private readonly NumberAllocator $numbers)
    {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            // `quoted` straight from draft: a quotation raised against an inquiry nobody
            // submitted first has still answered it (InquiryProgression).
            'draft' => ['open', 'quoted', 'cancelled'],
            'open' => ['quoted', 'lost', 'cancelled'],
            'quoted' => ['won', 'lost', 'cancelled'],
            'won' => [],
            'lost' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'open' => 'inquiry.submit',
            // Reached as a consequence of a quotation being raised (QuotationController),
            // never by hand; the system context skips the permission for that path.
            'quoted' => 'inquiry.update',
            'won' => 'inquiry.close',
            'lost' => 'inquiry.close',
            'cancelled' => 'inquiry.close',
        ];
    }

    /**
     * @param  Inquiry  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'open' && $document->lines()->count() === 0) {
            throw TransitionDenied::guard('SL-1', 'An inquiry needs at least one line before it is submitted.');
        }

        if ($to === 'lost' && blank($context['lost_reason'] ?? null)) {
            throw TransitionDenied::guard('SL-1', 'Say why the inquiry was lost; the reason feeds the win/loss report.');
        }
    }

    /**
     * The audit row keeps what caused the move when a quotation did (InquiryProgression).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function auditContext(array $context): array
    {
        return [
            ...parent::auditContext($context),
            ...array_intersect_key($context, array_flip(['because', 'quotation', 'quotation_id'])),
        ];
    }

    /**
     * @param  Inquiry  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        // BR-34 — numbered on the first move out of draft. A draft raised by mistake and
        // cancelled stays unnumbered: giving it the next number would leave a gap in the
        // series for an inquiry that never existed.
        if ($to !== 'cancelled' && $document->number === null) {
            $document->forceFill(['number' => $this->numbers->next('inquiry')])->save();
        }

        if ($to === 'lost') {
            $document->forceFill(['lost_reason' => $context['lost_reason']])->save();
        }
    }
}
