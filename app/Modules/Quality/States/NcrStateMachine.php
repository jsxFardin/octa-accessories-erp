<?php

declare(strict_types=1);

namespace App\Modules\Quality\States;

use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use App\Support\Notifications\Notifier;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-2 — NCR / CAPA (05-workflows §12, QL-7).
 *
 * Vocabulary is the schema's, not a parallel set:
 *   open → investigating → action_taken → verified → closed
 *
 * Assignment is `owner_id`, not a status. Disposition of the rejected lot lives on the QC
 * inspection (P1-3 already applied rework/scrap side effects there). This machine records
 * investigation and CAPA; it does not rewrite stock.
 *
 * @extends StateMachine<Ncr>
 */
class NcrStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly Notifier $notifier,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            Ncr::OPEN => [Ncr::INVESTIGATING],
            Ncr::INVESTIGATING => [Ncr::ACTION_TAKEN],
            Ncr::ACTION_TAKEN => [Ncr::VERIFIED],
            Ncr::VERIFIED => [Ncr::CLOSED],
            Ncr::CLOSED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            Ncr::INVESTIGATING => 'ncr.update',
            Ncr::ACTION_TAKEN => 'ncr.update',
            Ncr::VERIFIED => 'ncr.close',
            Ncr::CLOSED => 'ncr.close',
        ];
    }

    /**
     * @param  Ncr  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        /** @var Ncr $locked */
        $locked = Ncr::query()->lockForUpdate()->findOrFail($document->getKey());

        $current = (string) $locked->getAttribute($this->statusColumn());

        if ($current !== $from) {
            throw TransitionDenied::notAllowed('Ncr', $current, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        match ($to) {
            Ncr::INVESTIGATING => $this->guardInvestigating($locked),
            Ncr::ACTION_TAKEN => $this->guardActionTaken($locked),
            Ncr::VERIFIED => $this->guardVerified($locked),
            Ncr::CLOSED => $this->guardClosed($locked),
            default => null,
        };
    }

    private function guardInvestigating(Ncr $ncr): void
    {
        if ($ncr->owner_id === null) {
            throw TransitionDenied::guard('QL-7', 'Assign an owner before investigating this NCR.');
        }
    }

    private function guardActionTaken(Ncr $ncr): void
    {
        $capa = $this->correctiveCapa($ncr);

        if ($capa === null || blank($capa->root_cause) || blank($capa->action)) {
            throw TransitionDenied::guard(
                'QL-7',
                'Record a corrective CAPA with a root cause and an action before marking action taken.',
            );
        }
    }

    /**
     * QL-7 AC3–AC4: verified needs a completed CAPA, a root cause, and an effectiveness review.
     * An operational disposition that P1-3 has not yet applied also blocks here — the NCR
     * must not read as resolved while the physical action is still pending.
     */
    private function guardVerified(Ncr $ncr): void
    {
        $capa = $this->correctiveCapa($ncr);

        if ($capa === null
            || blank($capa->root_cause)
            || blank($capa->action)
            || $capa->completed_on === null
            || ! in_array($capa->effectiveness, ['effective', 'not_effective'], true)
        ) {
            throw TransitionDenied::guard(
                'QL-7',
                'Verification needs a completed CAPA with a root cause, a completed action, and an effectiveness review.',
            );
        }

        $this->assertDispositionSettled($ncr);
    }

    private function guardClosed(Ncr $ncr): void
    {
        $this->assertDispositionSettled($ncr);
    }

    private function assertDispositionSettled(Ncr $ncr): void
    {
        $pending = $ncr->pendingAction();

        if ($pending['status'] === 'pending') {
            throw TransitionDenied::guard('QL-7', $pending['detail']);
        }
    }

    private function correctiveCapa(Ncr $ncr): ?Capa
    {
        return Capa::query()
            ->where('ncr_id', $ncr->id)
            ->where('kind', Capa::KIND_CORRECTIVE)
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  Ncr  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to === Ncr::CLOSED) {
            $document->forceFill(['closed_on' => now()->toDateString()])->save();
        }

        try {
            match ($to) {
                Ncr::ACTION_TAKEN => $this->notifier->notifyNcrVerificationRequired($document),
                Ncr::CLOSED => $this->notifier->notifyNcrClosed($document),
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
