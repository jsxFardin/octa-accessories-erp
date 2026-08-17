<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\States\TransitionDenied;

/**
 * P1-1 — final QC is a gate, not a suggestion. A job whose routing flags QC cannot complete
 * without an accepted final inspection for THAT job; an unresolved rejection blocks until a
 * later inspection accepts the rework.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->states = app(JobCardStateMachine::class);

    // Drive the job to qc_pending through the machine — release (waived), produce, finish ops.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'QC gate walkthrough']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED, 'input_qty' => 1000, 'good_qty' => 1000]);
    $this->states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);
});

/** Post a final inspection through the QC route with the given defect count. */
function finalInspection(object $test, int $jobCardId, int $majors, ?string $disposition = null): void
{
    $test->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());

    $test->post('/qc-inspections', array_filter([
        'job_card_id' => $jobCardId, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => $majors, 'disposition' => $disposition,
    ]))->assertSessionHasNoErrors();

    $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
}

it('blocks completion when required final QC has not happened', function (): void {
    expect($this->jobCard->operations()->where('requires_qc', true)->exists())->toBeTrue();

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class, 'requires final QC');
});

it('allows completion after an accepted final inspection', function (): void {
    finalInspection($this, (int) $this->jobCard->id, majors: 0);

    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED);

    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});

it('blocks completion while the latest final verdict is an unresolved rejection', function (): void {
    // A scrap disposition raises the NCR but leaves the job in qc_pending (P1-3) — the
    // unresolved rejection is exactly what the completion gate must refuse.
    finalInspection($this, (int) $this->jobCard->id, majors: 50, disposition: 'scrap');

    expect($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING);

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class, 'rejected');

    // A later inspection accepts what survived — the gate opens.
    finalInspection($this, (int) $this->jobCard->id, majors: 0);

    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED);
    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});

it('sends a rework rejection back to the floor instead of leaving it completable', function (): void {
    // P1-3 — disposition `rework` reopens the flagged operation and moves the job to
    // in_production, where `completed` is not even reachable in the transition graph.
    finalInspection($this, (int) $this->jobCard->id, majors: 50, disposition: 'rework');

    expect($this->jobCard->refresh()->status)->toBe(JobCard::IN_PRODUCTION);

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class, 'cannot move');
});

it('ignores another job\'s inspection and non-final stages', function (): void {
    $other = $this->jobCard->replicate(['number', 'produced_qty', 'good_qty', 'waste_qty', 'actual_start', 'actual_finish']);
    $other->status = JobCard::DRAFT;
    $other->save();

    // Accepted final QC — but for the sibling job.
    finalInspection($this, (int) $other->id, majors: 0);

    // And an accepted in_process inspection for OUR job — wrong stage.
    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
    $this->post('/qc-inspections', [
        'job_card_id' => $this->jobCard->id, 'stage' => 'in_process', 'lot_size' => 500, 'major_found' => 0,
    ]);
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class, 'requires final QC');
});

it('leaves QC-free routings alone unless the factory default demands otherwise', function (): void {
    // Strip the QC flags: existing behavior — completion needs no inspection.
    $this->jobCard->operations()->update(['requires_qc' => false]);

    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED);
    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});

it('applies the global default when the setting is on', function (): void {
    $this->jobCard->operations()->update(['requires_qc' => false]);
    app(App\Support\Settings\Settings::class)->set('qc_final_required_default', true);

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class, 'requires final QC');
});

it('cannot be bypassed over HTTP by a user without the transition permission', function (): void {
    finalInspection($this, (int) $this->jobCard->id, majors: 0);

    // The driver holds nothing on job cards: the route itself refuses.
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail());
    $this->post("/job-cards/{$this->jobCard->id}/transition", ['to' => JobCard::COMPLETED])->assertForbidden();

    expect($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING);
});
