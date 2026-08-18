<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Quality\States\NcrStateMachine;
use App\Support\Notifications\Notifier;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * P2-2 — assignment, CAPA recording and the NCR lifecycle, in that order.
 *
 * Status never moves by direct assignment. Stock never moves from here: rework and the scrap
 * freeze already ran inside the QC rejection (P1-3). Closing an NCR is a compliance step,
 * not a second inventory event.
 */
class NcrService
{
    public function __construct(
        private readonly NcrStateMachine $states,
        private readonly Notifier $notifier,
    ) {}

    public function assign(Ncr $ncr, int $ownerId): Ncr
    {
        return DB::transaction(function () use ($ncr, $ownerId): Ncr {
            /** @var Ncr $locked */
            $locked = Ncr::query()->lockForUpdate()->findOrFail($ncr->getKey());

            if ($locked->status === Ncr::CLOSED) {
                throw TransitionDenied::guard('QL-7', 'A closed NCR cannot be reassigned.');
            }

            $previous = $locked->owner_id;

            $locked->forceFill(['owner_id' => $ownerId])->save();

            if ((int) $previous !== $ownerId) {
                try {
                    $this->notifier->notifyNcrAssigned($locked);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $locked;
        });
    }

    /**
     * Record investigation findings as CAPA rows, then move open → investigating.
     *
     * A second call while already investigating appends a preventive CAPA (or a further
     * corrective if none exists yet) and is a no-op on status — the machine is idempotent.
     *
     * @param  array<string, mixed>  $data
     */
    public function investigate(Ncr $ncr, array $data): Ncr
    {
        return DB::transaction(function () use ($ncr, $data): Ncr {
            /** @var Ncr $locked */
            $locked = Ncr::query()->lockForUpdate()->findOrFail($ncr->getKey());

            if (! in_array($locked->status, [Ncr::OPEN, Ncr::INVESTIGATING], true)) {
                throw TransitionDenied::notAllowed('Ncr', $locked->status, Ncr::INVESTIGATING);
            }

            if ($locked->owner_id === null) {
                throw TransitionDenied::guard('QL-7', 'Assign an owner before investigating this NCR.');
            }

            $responsibleId = isset($data['responsible_id']) ? (int) $data['responsible_id'] : $locked->owner_id;

            if (! $this->hasCorrectiveCapa($locked)) {
                Capa::query()->create([
                    'ncr_id' => $locked->id,
                    'kind' => Capa::KIND_CORRECTIVE,
                    'root_cause' => (string) $data['root_cause'],
                    'action' => (string) $data['action'],
                    'responsible_id' => $responsibleId,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => Capa::IN_PROGRESS,
                ]);
            }

            if (filled($data['preventive_action'] ?? null) && ! $this->hasPreventiveCapa($locked)) {
                Capa::query()->create([
                    'ncr_id' => $locked->id,
                    'kind' => Capa::KIND_PREVENTIVE,
                    'root_cause' => (string) $data['root_cause'],
                    'action' => (string) $data['preventive_action'],
                    'responsible_id' => $responsibleId,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => Capa::IN_PROGRESS,
                ]);
            }

            $this->states->transition($locked, Ncr::INVESTIGATING, ['reason' => 'investigation recorded']);

            return $locked->refresh();
        });
    }

    /**
     * Complete the corrective CAPA and move investigating → action_taken.
     *
     * This does not re-apply the QC disposition. Rework / scrap / concession already ran
     * (or were recorded) at rejection.
     */
    public function disposition(Ncr $ncr): Ncr
    {
        return DB::transaction(function () use ($ncr): Ncr {
            /** @var Ncr $locked */
            $locked = Ncr::query()->lockForUpdate()->findOrFail($ncr->getKey());

            $capa = Capa::query()
                ->where('ncr_id', $locked->id)
                ->where('kind', Capa::KIND_CORRECTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($capa !== null && $capa->completed_on === null) {
                $capa->forceFill([
                    'completed_on' => now()->toDateString(),
                    'status' => Capa::COMPLETED,
                ])->save();
            }

            $this->states->transition($locked, Ncr::ACTION_TAKEN, ['reason' => 'CAPA action taken']);

            return $locked->refresh();
        });
    }

    /**
     * Record the effectiveness review and move action_taken → verified.
     */
    public function verify(Ncr $ncr, string $effectiveness): Ncr
    {
        return DB::transaction(function () use ($ncr, $effectiveness): Ncr {
            /** @var Ncr $locked */
            $locked = Ncr::query()->lockForUpdate()->findOrFail($ncr->getKey());

            Capa::query()
                ->where('ncr_id', $locked->id)
                ->where('kind', Capa::KIND_CORRECTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->first()?->forceFill([
                    'effectiveness' => $effectiveness,
                    'status' => Capa::VERIFIED,
                ])->save();

            $this->states->transition($locked, Ncr::VERIFIED, ['reason' => 'effectiveness reviewed']);

            return $locked->refresh();
        });
    }

    public function close(Ncr $ncr): Ncr
    {
        return DB::transaction(function () use ($ncr): Ncr {
            /** @var Ncr $locked */
            $locked = Ncr::query()->lockForUpdate()->findOrFail($ncr->getKey());

            $this->states->transition($locked, Ncr::CLOSED, ['reason' => 'NCR closed']);

            return $locked->refresh();
        });
    }

    private function hasCorrectiveCapa(Ncr $ncr): bool
    {
        return Capa::query()
            ->where('ncr_id', $ncr->id)
            ->where('kind', Capa::KIND_CORRECTIVE)
            ->exists();
    }

    private function hasPreventiveCapa(Ncr $ncr): bool
    {
        return Capa::query()
            ->where('ncr_id', $ncr->id)
            ->where('kind', Capa::KIND_PREVENTIVE)
            ->exists();
    }
}
