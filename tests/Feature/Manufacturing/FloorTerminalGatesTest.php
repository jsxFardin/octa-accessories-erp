<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * The four things an end-to-end browser run found on the shop floor.
 *
 * Each of these looked like it worked: the ceiling was on the screen, the QC badge was on the
 * row, and the terminal said nothing when the server refused. Displayed is not enforced.
 */
beforeEach(function (): void {
    // The demo seed's job card, put on the floor: these gates are about what the terminal
    // may do to a card that is already running.
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->headers = fn (string $key): array => [
        'Authorization' => "Bearer {$this->token}",
        'Idempotency-Key' => $key,
    ];
});

it('lets an operator close the final operation of a job card', function (): void {
    // The operator holds four permissions and `job_card.update` is not among them. Closing
    // the last operation moves the card to qc_pending, which does require it — and charging
    // the operator for that transition made every job card unfinishable from the floor. The
    // transition is the system's consequence, not the operator's action.
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();
    expect($operator->hasPermission('job_card.update'))->toBeFalse();

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    // The steps before it ran and handed something over — a predecessor that completed with
    // nothing booked has nothing to hand on, and since F-04 the API says so (J2).
    completeOperationsBefore($final);

    $final->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();

    // Booked, because an operation that closes empty is refused on its own account (J3).
    $this->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => 1,
        'waste_qty' => 0,
        'input_qty' => 1,
    ], ($this->headers)('finish-final-log'))->assertOk();

    $this->postJson("/api/v1/operations/{$final->id}/finish", [], ($this->headers)('finish-final'))
        ->assertOk();

    expect($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING);
});

it('refuses output that would breach the J5 overrun ceiling at the moment it is booked', function (): void {
    // Not only when the card closes: by then the labels exist. The terminal shows the ceiling
    // on every screen, so a refusal that arrives days later reads as no rule at all.
    // The *final* operation, and one planned large enough that J3 is not what refuses this.
    //
    // The test used to book a piece quantity into the first step — warping, planned in metres —
    // where J3 ("more handed in than this operation is planned for") legitimately fires first
    // and J5 is never reached. Both refuse the write, so the floor was always safe; the test was
    // simply naming a guard its own fixture made unreachable, and it passed or failed depending
    // on which card the unordered `firstOrFail()` returned. J6: only the last operation states
    // the job's output in pieces, so that is the step a piece ceiling belongs to.
    $operation = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $operation->forceFill([
        'status' => JobCardOperation::IN_PROGRESS,
        'planned_qty' => $this->jobCard->overrunCeiling() * 2,
    ])->save();

    // J2/J7 — the steps before it ran and handed enough forward, so the chain is not what
    // refuses this either. The only rule left standing is the one under test.
    completeOperationsBefore($operation);

    $over = $this->jobCard->overrunCeiling() - (float) $this->jobCard->produced_qty_running + 1;

    $this->postJson("/api/v1/operations/{$operation->id}/log", [
        'good_qty' => $over,
        'waste_qty' => 0,
        'input_qty' => $over,
    ], ($this->headers)('j5-over'))
        ->assertStatus(422)
        ->assertSee('J5', escape: false)
        // UX audit H-34 — beside the English sentence, a code and the figures behind it, so the
        // terminal can say the same thing in Bangla without parsing prose.
        ->assertJsonPath('code', 'output_exceeds_ceiling')
        ->assertJsonPath('params.ceiling', fn ($ceiling) => abs((float) $ceiling - $this->jobCard->overrunCeiling()) < 0.001);

    expect((float) $this->jobCard->refresh()->produced_qty_running)
        ->toBeLessThanOrEqual($this->jobCard->overrunCeiling());
});

it('holds an operation whose predecessor still needs a QC verdict', function (): void {
    // `requires_qc` was rendered on the floor and on the job card without anything reading it,
    // so the QC badge was advisory: a web could be cut before the inspector passed it.
    $first = $this->jobCard->operations()->reorder('sequence_no', 'asc')->firstOrFail();
    $second = $this->jobCard->operations()
        ->where('sequence_no', '>', $first->sequence_no)
        ->reorder('sequence_no', 'asc')
        ->firstOrFail();

    $first->forceFill(['status' => JobCardOperation::COMPLETED, 'requires_qc' => true])->save();
    $second->forceFill(['status' => JobCardOperation::READY])->save();

    DB::table('qc_inspections')->where('job_card_operation_id', $first->id)->delete();

    $this->postJson("/api/v1/operations/{$second->id}/start", [], ($this->headers)('qc1-blocked'))
        ->assertStatus(422)
        ->assertSee('QC1', escape: false);

    // An accepted inspection against that operation releases it.
    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail())
        ->post('/qc-inspections', [
            'job_card_id' => $this->jobCard->id,
            'job_card_operation_id' => $first->id,
            'stage' => 'in_process',
            'lot_size' => 200,
            'major_found' => 0,
        ])->assertSessionHasNoErrors();

    $this->postJson("/api/v1/operations/{$second->id}/start", [], ($this->headers)('qc1-cleared'))
        ->assertOk();
});

/*
 * UX audit H-35. The terminal printed bare quantities, so metres and pieces were booked into
 * identical boxes. The operation screen is now told the unit and prints it beside every figure.
 */
it('tells the operation screen which unit its quantities are in', function (): void {
    $operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->actingAs($supervisor)->get("/floor/operations/{$operation->id}")
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->component('Floor/Operation')
            ->where('operation.unit', $operation->unit())
            ->where('operation.unit', fn ($unit) => in_array($unit, ['m', 'pcs'], true)));
});

it('names a refusal that comes from the API controller itself', function (): void {
    $operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    $operation->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();

    $this->postJson("/api/v1/operations/{$operation->id}/log", [
        'good_qty' => 0, 'waste_qty' => 5, 'input_qty' => 5,
    ], ($this->headers)('waste-no-reason'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'waste_reason_needed')
        ->assertJsonStructure(['message', 'code', 'params']);
});

/*
 * UX audit M-34. The queue card shows progress, and progress is a figure with a unit.
 */
it('tells the work queue which unit each step is counted in', function (): void {
    $operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    $operation->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();

    $rows = $this->getJson('/api/v1/floor/queue', ['Authorization' => "Bearer {$this->token}"])
        ->assertOk()
        ->json('operations');

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['unit'])->toBeIn(['m', 'pcs']);
    }
});

/*
 * UX audit H-33. An operator can read the terminal's "Not sent" list; only someone who can book
 * the record by hand at the desk may take it off.
 */
it('lets a supervisor, not an operator, clear a record from the not-sent list', function (): void {
    $operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();

    $flag = fn (string $email, string $url): bool => (bool) $this
        ->actingAs(User::query()->where('email', $email)->firstOrFail())
        ->get($url)
        ->assertOk()
        ->viewData('page')['props']['canClearUnsent'];

    expect($flag('supervisor@octapussolution.com', '/floor/queue'))->toBeTrue()
        ->and($flag('supervisor@octapussolution.com', "/floor/operations/{$operation->id}"))->toBeTrue()
        ->and($flag('operator@octapussolution.com', '/floor/queue'))->toBeFalse()
        ->and($flag('operator@octapussolution.com', "/floor/operations/{$operation->id}"))->toBeFalse();
});
