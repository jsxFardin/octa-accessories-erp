<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GAP M-02 / M-03 — the terminal's START, DOWNTIME and FINISH ask the same questions as
 * OUTPUT does.
 *
 * `log` has always checked J1. `start` and `downtime` took the operation id off the URL and
 * wrote; `finish` accepted a completed step again and counted the tool's shots twice.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->states = app(JobCardStateMachine::class);
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->where('status', JobCard::PLANNED)->firstOrFail();
    $this->first = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');
    $this->token = $this->postJson('/api/v1/device/session', ['card_no' => $card, 'pin' => substr((string) $card, -4)])
        ->assertCreated()->json('token');

    $this->headers = fn (): array => ['Authorization' => "Bearer {$this->token}", 'Idempotency-Key' => (string) Str::uuid()];
});

it('refuses START on a card that is not released (J1)', function (): void {
    expect($this->jobCard->status)->toBe(JobCard::PLANNED);

    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('code', 'job_card_not_released');

    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/downtime", [
            'downtime_reason_id' => DB::table('downtime_reasons')->value('id'), 'minutes' => 5,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'job_card_not_released');

    expect($this->first->fresh()->status)->not->toBe(JobCardOperation::IN_PROGRESS)
        ->and(DB::table('downtime_logs')->where('job_card_operation_id', $this->first->id)->count())->toBe(0);
});

it('refuses START on a held card but still books the output already made (MF-6)', function (): void {
    $this->actingAs($this->admin);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'guard test']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->states->transition($this->jobCard->refresh(), JobCard::ON_HOLD, ['hold_reason' => 'Shade query with the customer']);

    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('code', 'job_card_on_hold');

    // The shift that already ran may be written down.
    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/log", ['good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10])
        ->assertOk();
});

it('lifts a hold only with a resolution, and clears the reason when it does', function (): void {
    $this->actingAs($this->admin);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'guard test']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->states->transition($this->jobCard->refresh(), JobCard::ON_HOLD, ['hold_reason' => 'Shade query with the customer']);

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION))
        ->toThrow(TransitionDenied::class);

    expect($this->jobCard->fresh()->status)->toBe(JobCard::ON_HOLD);

    $this->post("/job-cards/{$this->jobCard->id}/transition", ['to' => 'in_production', 'hold_resolution' => 'Customer approved shade B'])
        ->assertSessionHas('success');

    $card = $this->jobCard->fresh();

    expect($card->status)->toBe(JobCard::IN_PRODUCTION)
        ->and($card->hold_reason)->toBeNull();

    $row = DB::table('audit_logs')->where('auditable_type', JobCard::class)->where('auditable_id', $card->id)
        ->where('event', 'status_changed')->orderByDesc('id')->first();

    expect(json_decode((string) $row->new_values, true)['hold_resolution'] ?? null)->toBe('Customer approved shade B');
});

it('finishes a step once and refuses the second FINISH instead of counting it again', function (): void {
    $this->actingAs($this->admin);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'guard test']);

    $this->withHeaders(($this->headers)())->postJson("/api/v1/operations/{$this->first->id}/start")->assertOk();
    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/log", ['good_qty' => 100, 'waste_qty' => 0, 'input_qty' => 100])
        ->assertOk();

    $this->withHeaders(($this->headers)())->postJson("/api/v1/operations/{$this->first->id}/finish")->assertOk();

    $finished = $this->first->fresh();
    $finishedAt = $finished->finished_at;

    // A second FINISH with a fresh key is a different request, not a replay.
    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$this->first->id}/finish")
        ->assertStatus(422)
        ->assertJsonPath('code', 'step_already_finished');

    expect($this->first->fresh()->finished_at?->equalTo($finishedAt))->toBeTrue();
});

// GAP Q-02 — QL-2 AC1: a step flagged for in-process QC finishes only once inspected.
it('refuses to finish a requires_qc step before its inspection is recorded', function (): void {
    $this->actingAs($this->admin);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'guard test']);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $flagged = $this->jobCard->operations()->where('requires_qc', true)
        ->where('sequence_no', '<', $final->sequence_no)->orderBy('sequence_no')->first();

    if ($flagged === null) {
        $flagged = $this->jobCard->operations()->where('sequence_no', '<', $final->sequence_no)->orderBy('sequence_no')->firstOrFail();
        $flagged->forceFill(['requires_qc' => true])->save();
    }

    completeOperationsBefore($flagged, 100);

    $this->withHeaders(($this->headers)())->postJson("/api/v1/operations/{$flagged->id}/start")->assertOk();
    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$flagged->id}/log", ['good_qty' => 100, 'waste_qty' => 0, 'input_qty' => 100])
        ->assertOk();

    $this->withHeaders(($this->headers)())
        ->postJson("/api/v1/operations/{$flagged->id}/finish")
        ->assertStatus(422)
        ->assertJsonPath('code', 'step_needs_inspection');

    expect($flagged->fresh()->status)->toBe(JobCardOperation::IN_PROGRESS);

    DB::table('qc_inspections')->insert([
        'stage' => 'in_process', 'job_card_id' => $this->jobCard->id, 'job_card_operation_id' => $flagged->id,
        'inspected_on' => now()->toDateString(), 'lot_size' => 100, 'result' => 'accepted', 'created_at' => now(),
    ]);

    $this->withHeaders(($this->headers)())->postJson("/api/v1/operations/{$flagged->id}/finish")->assertOk();

    expect($flagged->fresh()->status)->toBe(JobCardOperation::COMPLETED);
});

// GAP Q-02 — QL-2 AC2: a failed in-process check holds the card and tells the supervisor.
it('puts the card on hold and notifies the supervisor when an in-process check fails', function (): void {
    $this->actingAs($this->admin);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'guard test']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail());
    $this->post('/qc-inspections', [
        'job_card_id' => $this->jobCard->id, 'stage' => 'in_process', 'job_card_operation_id' => $this->first->id,
        'lot_size' => 500, 'major_found' => 50, 'disposition' => 'scrap',
    ])->assertSessionHasNoErrors();

    $card = $this->jobCard->fresh();
    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    expect($card->status)->toBe(JobCard::ON_HOLD)
        ->and($card->hold_reason)->toContain('In-process QC')
        ->and(DB::table('notifications')->where('notifiable_id', $supervisor->id)
            ->where('data->dedupe_key', 'like', 'job_card:held:'.$card->id.':%')->exists())->toBeTrue();
});
