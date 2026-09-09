<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * An idempotency key means "this write, again" — not "any write, again".
 *
 * The cache key was `sha256($key)` and nothing else: global across every endpoint, every
 * operation and every device. One value reused across two different writes — a client bug, a
 * device whose `crypto.randomUUID` fell back to something weaker, a captured request replayed
 * — returned the *first* call's stored response and skipped the second write entirely. The
 * terminal reads that reply as a success and clears the form, so a shift's output disappears
 * with no error raised anywhere.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    $this->operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    $this->operation->forceFill(['status' => JobCardOperation::PENDING])->save();

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

it('terminal: one key reused across two different endpoints does not swallow the second write', function (): void {
    $key = 'the-same-key-twice';

    $this->withHeaders(($this->headers)($key))
        ->postJson("/api/v1/operations/{$this->operation->id}/start", [])
        ->assertOk();

    $response = $this->withHeaders(($this->headers)($key))
        ->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => 10,
            'waste_qty' => 0,
            'input_qty' => 10,
        ]);

    // The old behaviour: a 200 carrying `start`'s payload, `replayed` set, and no output booked.
    $response->assertOk();
    expect($response->json('replayed'))->toBeNull();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(10.0, 0.0001);
});

it('terminal: one key reused against two different operations books both', function (): void {
    $second = $this->jobCard->operations()->where('id', '!=', $this->operation->id)->first();

    if ($second === null) {
        $this->markTestSkipped('The chosen job card has a single operation.');
    }

    $key = 'one-key-two-operations';
    $reasonId = DB::table('downtime_reasons')->value('id');
    $machineId = DB::table('machines')->insertGetId([
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => DB::table('machine_groups')->value('id'),
        'code' => 'DT-1',
        'name' => 'Downtime test machine',
    ]);

    // Downtime rather than `start`: a machine stopping is not gated on the step before it, so
    // this isolates the idempotency question from J2.
    foreach ([$this->operation, $second] as $operation) {
        $this->withHeaders(($this->headers)($key))
            ->postJson("/api/v1/operations/{$operation->id}/downtime", [
                'downtime_reason_id' => $reasonId,
                'minutes' => 15,
                'machine_id' => $machineId,
            ])->assertOk();
    }

    expect(DB::table('downtime_logs')->where('job_card_operation_id', $this->operation->id)->count())->toBe(1)
        ->and(DB::table('downtime_logs')->where('job_card_operation_id', $second->id)->count())->toBe(1);
});

it('terminal: the same key on the same write is still a replay, not a second booking', function (): void {
    // The behaviour this protects: a four-hour offline queue drained after an outage replays
    // writes the server may already have taken, and a double-posted shift output is silent
    // data corruption.
    $key = 'a-genuine-replay';

    $this->withHeaders(($this->headers)($key))
        ->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => 25,
            'waste_qty' => 0,
            'input_qty' => 25,
        ])->assertOk();

    $replay = $this->withHeaders(($this->headers)($key))
        ->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => 25,
            'waste_qty' => 0,
            'input_qty' => 25,
        ])->assertOk();

    expect($replay->json('replayed'))->toBeTrue()
        ->and((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(25.0, 0.0001);
});

it('terminal: an unreadable occurred_at is a broken clock, not a 500', function (): void {
    // `occurred_at` is in no endpoint's validation rules — it is a queue stamp the client adds
    // — so it arrived unchecked at `CarbonImmutable::parse()`, which throws. The offline queue
    // treats a 5xx as a permanent reject and never retries it, so one malformed stamp silently
    // destroyed the booking attached to it.
    $this->withHeaders(($this->headers)('garbage-stamp'))
        ->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => 5,
            'waste_qty' => 0,
            'input_qty' => 5,
            'occurred_at' => 'not a date at all',
        ])->assertOk();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(5.0, 0.0001);
});

/**
 * The DOWNTIME button never worked.
 *
 * The insert named `logged_by` (the column is `reported_by`) and a `created_at` this table
 * does not have, so every press answered 500. The offline queue files a 5xx as a permanent
 * reject and never retries it, so the stop was lost in silence — and it is why no report
 * anywhere had any downtime to show: none had ever been recorded.
 */
it('terminal: records a downtime stop instead of answering 500', function (): void {
    $machineId = DB::table('machines')->insertGetId([
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => DB::table('machine_groups')->value('id'),
        'code' => 'DT-OK',
        'name' => 'Downtime machine',
    ]);

    $shiftId = DB::table('shifts')->value('id');

    $this->withHeaders(($this->headers)('a-real-stop'))
        ->postJson("/api/v1/operations/{$this->operation->id}/downtime", [
            'downtime_reason_id' => DB::table('downtime_reasons')->value('id'),
            'minutes' => 45,
            'machine_id' => $machineId,
            'shift_id' => $shiftId,
            'remarks' => 'Web break',
        ])->assertOk();

    $log = DB::table('downtime_logs')
        ->where('job_card_operation_id', $this->operation->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and((float) $log->minutes)->toEqualWithDelta(45.0, 0.0001)
        ->and($log->reported_by)->not->toBeNull()
        // The shifts were fetched for the terminal's screen and then never sent, so no report
        // could break a machine's downtime down by shift.
        ->and($log->shift_id)->toBe($shiftId);
});

it('terminal: refuses downtime in words when the step is on no machine', function (): void {
    // `downtime_logs.machine_id` is NOT NULL. A step never started and never scheduled has no
    // machine, and the constraint violation surfaced as a 500 the offline queue threw away.
    $this->operation->forceFill(['machine_id' => null])->save();

    $this->withHeaders(($this->headers)('no-machine'))
        ->postJson("/api/v1/operations/{$this->operation->id}/downtime", [
            'downtime_reason_id' => DB::table('downtime_reasons')->value('id'),
            'minutes' => 10,
        ])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'This step is not on a machine yet, and downtime belongs to a machine. Start the operation first, or ask a planner to schedule it.']);
});
