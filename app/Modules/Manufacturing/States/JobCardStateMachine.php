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
use Illuminate\Support\Facades\DB;

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
            // P0-3 — one way back. `closed` releases reservations and stamps `closed_at`, and
            // nothing downstream of it can be undone by reopening, so this is not a general
            // "unlock the card" door: it exists because finished goods can only be received
            // from a card that is not yet closed, and a card closed with output still
            // unreceived would otherwise strand that output permanently — the pieces on the
            // job, nothing in stock, and the order line's headroom already spent so no
            // replacement card can be raised either. Guarded on a reason and the close
            // permission, and audit-logged like every other transition.
            JobCard::CLOSED => [JobCard::COMPLETED],
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
            JobCard::COMPLETED => $this->guardCompleted($document, $from, $context),
            JobCard::CLOSED => $this->guardClosed($document, $context),
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

    /**
     * I7 — every material the active BOM calls for has to have been issued, or the shortfall
     * waived by someone who may waive it.
     *
     * A job completing with cartons on its BOM and none ever issued leaves the store's records
     * holding packaging that physically left the shelf. Nothing complained, and the drift only
     * surfaced at the next physical count.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardMaterialIssued(JobCard $jobCard, array $context): void
    {
        $bom = $jobCard->bom;

        if ($bom === null) {
            return;
        }

        $issued = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $jobCard->getKey())
            ->where('mi.status', 'posted')
            ->groupBy('mil.item_id')
            ->selectRaw("mil.item_id, SUM(CASE WHEN mi.issue_type = 'return' THEN -mil.qty ELSE mil.qty END) as qty")
            ->pluck('qty', 'mil.item_id');

        $missing = $bom->lines
            ->filter(fn ($line): bool => ! (bool) $line->is_optional && (float) ($issued[$line->item_id] ?? 0) <= 0)
            ->map(fn ($line): string => (string) ($line->item->code ?? "item #{$line->item_id}"))
            ->values();

        if ($missing->isEmpty()) {
            return;
        }

        // The same permission that waives material at release waives it here; a job that ran
        // on substitutes is a real thing, and saying so is the price.
        if (filled($context['material_waiver_reason'] ?? null)) {
            if (! (auth()->user()?->hasPermission('job_card.waive_material') ?? false)) {
                throw TransitionDenied::notPermitted('job_card.waive_material');
            }

            return;
        }

        throw TransitionDenied::guard(
            'I7',
            'Nothing was issued against '.$missing->implode(', ').
            '. Issue the material, or complete with a documented waiver.',
        );
    }

    /**
     * P1-1 — a job whose routing demands QC cannot complete without an accepted final
     * verdict. The requirement comes from the routing's own `requires_qc` flags (explicit
     * configuration is authoritative); `qc_final_required_default` extends it to every job
     * when the factory turns the setting on. An unresolved rejection blocks until rework
     * produces a later accepted inspection.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardCompleted(JobCard $jobCard, string $from, array $context): void
    {
        if ($from === JobCard::CLOSED) {
            $this->guardReopen($jobCard, $context);

            return;
        }

        $required = $jobCard->operations()->where('requires_qc', true)->exists()
            || app(\App\Support\Settings\Settings::class)->bool('qc_final_required_default', false);

        if (! $required) {
            $this->guardMaterialIssued($jobCard, $context);

            return;
        }

        $latest = \App\Modules\Quality\Models\QcInspection::query()
            ->where('job_card_id', $jobCard->getKey())
            ->where('stage', 'final')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            throw TransitionDenied::guard(
                'P1-1 · QC1',
                'This routing requires final QC and none has been performed. Inspect before completing.',
            );
        }

        if ($latest->result === 'rejected') {
            throw TransitionDenied::guard(
                'P1-1 · QC2',
                "The latest final inspection ({$latest->number}) rejected the lot — disposition {$latest->disposition}. "
                .'Rework and re-inspect before completing.',
            );
        }

        if (! in_array($latest->result, ['accepted', 'accepted_with_concession'], true)) {
            throw TransitionDenied::guard('P1-1 · QC1', "Final inspection {$latest->number} is still {$latest->result}.");
        }

        // Last, so a job that is both unin­spected and short of material hears about the
        // inspection first — that is the one that keeps defective labels off a garment.
        $this->guardMaterialIssued($jobCard, $context);
    }

    /**
     * Reopening a closed card, back to `completed`.
     *
     * The QC and material conditions are deliberately not re-asked: this card already passed
     * them on its way to `completed` the first time, and nothing about being closed and
     * reopened can have made a passed final inspection stop existing. Re-running them would
     * refuse the reopen for a job whose inspection was later superseded, which is the opposite
     * of the point.
     *
     * What it does ask is who, and why. The permission is `job_card.close` — the one that
     * closed it — rather than a new `job_card.reopen`, so this works on a live tenant without
     * reseeding permissions. If reopening should be narrower than closing, that is a
     * permission to add and assign, not a different shape of guard.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardReopen(JobCard $jobCard, array $context): void
    {
        if (blank($context['reopen_reason'] ?? null)) {
            throw TransitionDenied::guard(
                'P0-3',
                'Reopening a closed job card needs a reason. It is recorded on the card\'s history.',
            );
        }

        if (! (auth()->user()?->hasPermission('job_card.close') ?? false)) {
            throw TransitionDenied::notPermitted('job_card.close');
        }
    }

    /**
     * J4, J5.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardClosed(JobCard $jobCard, array $context): void
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

        // P0-2 — the job's output is the final operation's, in pieces. The row's running
        // totals sum metres and pieces across operations, so measuring a piece ceiling
        // against them failed jobs that had produced exactly what was planned.
        $produced = $jobCard->finalOperationOutput()['produced'];

        if ($produced > $jobCard->overrunCeiling() + 0.000001) {
            throw TransitionDenied::guard(
                'J5',
                sprintf(
                    'Produced %s exceeds the planned quantity plus %s%% overrun tolerance.',
                    rtrim(rtrim(number_format($produced, 6, '.', ''), '0'), '.'),
                    $jobCard->overrun_tolerance_pct,
                ),
            );
        }

        $this->guardOutputReceived($jobCard, $context);
    }

    /**
     * P0-3 — output that was made but never received into stock.
     *
     * `closed` is terminal and finished goods may only be received from `in_production`,
     * `qc_pending` or `completed`, so closing a card with unreceived production strands it:
     * the pieces exist on the job card and nowhere in inventory, the order they were made for
     * cannot be packed, and there is no transition back. A card closed that way is
     * unrecoverable through the application.
     *
     * The panel on the job card screen already prints the gap in red. It was the one figure
     * nothing checked.
     *
     * Closing with output still unreceived is a real thing — a run scrapped after final QC,
     * output written off rather than stocked — so it stays possible with a reason, under the
     * same permission that waives material. What it stops being is an accident.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardOutputReceived(JobCard $jobCard, array $context): void
    {
        $good = (float) JobCardOperation::query()
            ->where('job_card_id', $jobCard->getKey())
            ->reorder('sequence_no', 'desc')
            ->value('good_qty');

        $received = (float) DB::table('fg_receipts')
            ->where('job_card_id', $jobCard->getKey())
            ->where('status', 'posted')
            ->sum('qty');

        $unreceived = round($good - $received, 6);

        if ($unreceived <= 0.000001) {
            return;
        }

        $reason = trim((string) ($context['unreceived_output_reason'] ?? ''));

        if ($reason === '') {
            throw TransitionDenied::guard('P0-3', sprintf(
                '%s piece(s) of this job\'s output have never been received into finished goods. '
                .'Receive them before closing — a closed card cannot receive, and cannot be reopened. '
                .'To close anyway, say why the output is not being stocked.',
                rtrim(rtrim(number_format($unreceived, 6, '.', ''), '0'), '.'),
            ));
        }

        if (! (auth()->user()?->hasPermission('job_card.waive_material') ?? false)) {
            throw TransitionDenied::notPermitted('job_card.waive_material');
        }

        // The reason itself needs no column: `auditContext()` keeps every `*_reason` key, so
        // the state machine writes it onto the transition's own audit row.
    }

    /** @param array<string, mixed> $context */
    private function guardCancelled(JobCard $jobCard, array $context): void
    {
        // "Has anything at all been booked against this card?" — the one question the
        // cross-unit running total is the right answer to (J6).
        $hasProduction = (float) $jobCard->produced_qty_running > 0;

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
            JobCard::COMPLETED => $this->onCompleted($document, $from),
            JobCard::CLOSED => $this->onClosed($document),
            JobCard::CANCELLED => app(\App\Modules\Inventory\Services\ReservationService::class)
                ->releaseForJob((int) $document->getKey()),
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

        // P1-2 — the release claims its material, so the next job's J1 gate sees the truth.
        // A waived release reserves what exists rather than failing on the waived shortfall.
        app(\App\Modules\Inventory\Services\ReservationService::class)->reserveForJob(
            $jobCard,
            allowShortfall: filled($context['material_waiver_reason'] ?? null),
        );

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

    /**
     * Reopening clears `closed_at` — the card is not closed any more, and a stale stamp would
     * leave it reading as closed on every report that asks the column rather than the status.
     * `actual_finish` is not re-stamped on the way back: the job finished when it finished,
     * and overwriting it with the moment of an administrative correction would lose that.
     */
    private function onCompleted(JobCard $jobCard, string $from): void
    {
        $jobCard->forceFill($from === JobCard::CLOSED
            ? ['closed_at' => null]
            : ['actual_finish' => now()])->save();
    }

    private function onClosed(JobCard $jobCard): void
    {
        $jobCard->forceFill(['closed_at' => now()])->save();

        // P1-2 — leftover claims of a finished job go back to the pool; history rows stay.
        app(\App\Modules\Inventory\Services\ReservationService::class)->releaseForJob((int) $jobCard->getKey());
    }
}
