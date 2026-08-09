<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\States;

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * 05-workflows §6 — the most guarded transition in the system.
 *
 * `planned → released` is where J1 lives: approved artwork, active BOM, tools available, and
 * material either in stock or explicitly waived by a planner who typed a reason and holds the
 * permission to do so.
 *
 * @extends StateMachine<JobCard>
 */
class JobCardStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly JobCardReleaseGate $gate,
        private readonly NumberAllocator $numbers,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            JobCard::DRAFT => [JobCard::PLANNED, JobCard::CANCELLED],
            JobCard::PLANNED => [JobCard::RELEASED, JobCard::MATERIAL_PENDING, JobCard::CANCELLED],
            JobCard::MATERIAL_PENDING => [JobCard::RELEASED, JobCard::CANCELLED],
            JobCard::RELEASED => [JobCard::IN_PRODUCTION, JobCard::CANCELLED],
            JobCard::IN_PRODUCTION => [JobCard::ON_HOLD, JobCard::QC_PENDING],
            JobCard::ON_HOLD => [JobCard::IN_PRODUCTION],
            JobCard::QC_PENDING => [JobCard::IN_PRODUCTION, JobCard::COMPLETED],
            JobCard::COMPLETED => [JobCard::CLOSED],
            JobCard::CLOSED => [],
            JobCard::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            JobCard::PLANNED => 'job_card.update',
            JobCard::RELEASED => 'job_card.release',
            JobCard::MATERIAL_PENDING => 'job_card.update',
            JobCard::IN_PRODUCTION => 'operation.start',
            JobCard::ON_HOLD => 'job_card.update',
            JobCard::QC_PENDING => 'job_card.update',
            JobCard::COMPLETED => 'job_card.update',
            JobCard::CLOSED => 'job_card.close',
            JobCard::CANCELLED => 'job_card.cancel',
        ];
    }

    /**
     * @param  JobCard  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            JobCard::PLANNED => $this->guardPlanned($document),
            JobCard::RELEASED => $this->guardReleased($document, $context),
            JobCard::ON_HOLD => $this->guardHold($context),
            JobCard::QC_PENDING => $this->guardQcPending($document),
            JobCard::CLOSED => $this->guardClosed($document),
            JobCard::CANCELLED => $this->guardCancelled($document, $context),
            default => null,
        };
    }

    private function guardPlanned(JobCard $jobCard): void
    {
        if ($jobCard->operations()->count() === 0) {
            throw TransitionDenied::guard('J2', 'A job card is planned by scheduling its operations; this one has none.');
        }
    }

    /**
     * J1 — all four conditions. The message names the ones that failed, because the planner's
     * next action depends on which.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardReleased(JobCard $jobCard, array $context): void
    {
        $waived = filled($context['material_waiver_reason'] ?? null);

        if ($waived && ! (auth()->user()?->hasPermission('job_card.waive_material') ?? false)) {
            throw TransitionDenied::notPermitted('job_card.waive_material');
        }

        $report = $this->gate->evaluate($jobCard, $waived);

        if ($report['ready']) {
            return;
        }

        $failed = array_map(
            fn (array $check): string => "{$check['label']} ({$check['rule']}): {$check['detail']}",
            array_filter($report['checks'], fn (array $check): bool => ! $check['ok']),
        );

        throw TransitionDenied::guard('J1', "This job card cannot be released.\n• ".implode("\n• ", $failed));
    }

    /** @param array<string, mixed> $context */
    private function guardHold(array $context): void
    {
        if (blank($context['hold_reason'] ?? null)) {
            throw TransitionDenied::guard('J1', 'A hold needs a reason; the planning board frees the machine slot on the strength of it.');
        }
    }

    /** J2 — every operation is finished or deliberately skipped before QC sees the lot. */
    private function guardQcPending(JobCard $jobCard): void
    {
        $unfinished = $jobCard->operations()
            ->whereNotIn('status', [JobCardOperation::COMPLETED, JobCardOperation::SKIPPED, JobCardOperation::CANCELLED])
            ->count();

        if ($unfinished > 0) {
            throw TransitionDenied::guard('J2', "{$unfinished} operation(s) are still open.");
        }
    }

    /** J4, J5 */
    private function guardClosed(JobCard $jobCard): void
    {
        if ($jobCard->operations()->where('status', JobCardOperation::IN_PROGRESS)->exists()) {
            throw TransitionDenied::guard('J4', 'An operation is still in progress.');
        }

        $unresolved = \App\Modules\Quality\Models\QcInspection::query()
            ->where('job_card_id', $jobCard->getKey())
            ->where('result', 'pending')
            ->exists();

        if ($unresolved) {
            throw TransitionDenied::guard('J4', 'A mandatory QC inspection is unresolved.');
        }

        if ((float) $jobCard->produced_qty > $jobCard->overrunCeiling() + 0.000001) {
            throw TransitionDenied::guard(
                'J5',
                sprintf(
                    'Produced %s exceeds the planned quantity plus %s%% overrun tolerance.',
                    rtrim(rtrim((string) $jobCard->produced_qty, '0'), '.'),
                    $jobCard->overrun_tolerance_pct,
                ),
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function guardCancelled(JobCard $jobCard, array $context): void
    {
        $hasProduction = (float) $jobCard->produced_qty > 0;

        if ($hasProduction && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard(
                'J1',
                'This job card has logged production. Cancelling it needs a supervisor reason.',
            );
        }
    }

    /**
     * @param  JobCard  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            // BR-34: the number is assigned on the first transition out of draft, never on
            // opening a blank form.
            JobCard::PLANNED => $this->onPlanned($document),
            JobCard::RELEASED => $this->onReleased($document, $context),
            JobCard::IN_PRODUCTION => $this->onInProduction($document),
            JobCard::ON_HOLD => $document->forceFill(['hold_reason' => $context['hold_reason']])->save(),
            JobCard::COMPLETED => $document->forceFill(['actual_finish' => now()])->save(),
            JobCard::CLOSED => $document->forceFill(['closed_at' => now()])->save(),
            default => null,
        };
    }

    private function onPlanned(JobCard $jobCard): void
    {
        if ($jobCard->number === null) {
            $jobCard->forceFill(['number' => $this->numbers->next('job_card')])->save();
        }
    }

    /** @param array<string, mixed> $context */
    private function onReleased(JobCard $jobCard, array $context): void
    {
        $jobCard->forceFill([
            'material_waiver_reason' => $context['material_waiver_reason'] ?? null,
        ])->save();

        // The first operation joins the shop-floor queue; the rest wait on J2 ordering.
        $jobCard->operations()
            ->where('sequence_no', $jobCard->operations()->min('sequence_no'))
            ->update(['status' => JobCardOperation::READY]);
    }

    private function onInProduction(JobCard $jobCard): void
    {
        if ($jobCard->actual_start === null) {
            $jobCard->forceFill(['actual_start' => now()])->save();
        }
    }
}
