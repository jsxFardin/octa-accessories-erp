<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * The second door onto production.
 *
 * Output, downtime and waste could only reach the system through the floor terminal's device
 * API — badge and PIN, no web session, no permission that substitutes for one. A signed-in
 * supervisor, or an admin, could not record a figure at all. That default is right: output is
 * the shop-floor truth, and a desk form invites yesterday's numbers being typed off paper,
 * which is the habit G1 exists to end.
 *
 * It is not a workable *only* option. A kiosk that dies mid-shift stranded that shift's output
 * with no route in, and the tablet is the thing on a factory floor guaranteed to break on the
 * day it matters.
 */
beforeEach(function (): void {
    $this->supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    $this->operation = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    $this->jobCard->operations()
        ->where('sequence_no', '<', $this->operation->sequence_no)
        ->get()
        ->each(fn (JobCardOperation $step) => $step->forceFill([
            'status' => JobCardOperation::COMPLETED,
            'input_qty' => 60000,
            'good_qty' => 60000,
            'requires_qc' => false,
        ])->save());

    $this->operation->forceFill([
        'status' => JobCardOperation::PENDING,
        'input_qty' => 0,
        'good_qty' => 0,
        'waste_qty' => 0,
    ])->save();

    $this->operatorId = DB::table('employees')->where('is_active', true)->value('id');

    $this->book = fn (array $overrides = []) => $this->actingAs($this->supervisor)
        ->post("/job-cards/{$this->jobCard->id}/book-output", [
            'job_card_operation_id' => $this->operation->id,
            'good_qty' => 500,
            'waste_qty' => 0,
            'input_qty' => 500,
            'operator_id' => $this->operatorId,
            'occurred_at' => now()->subHours(6)->format('Y-m-d H:i:s'),
            'manual_reason' => 'Terminal at the loom would not start; figures from the shift sheet.',
            ...$overrides,
        ]);
});

it('desk: books output without a badge', function (): void {
    ($this->book)()->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(500.0, 0.0001);
});

it('desk: marks the booking as keyed at a desk, with its reason', function (): void {
    ($this->book)();

    $log = DB::table('operation_logs')
        ->where('job_card_operation_id', $this->operation->id)
        ->orderByDesc('id')->first();

    // An exception that cannot be told apart from the norm stops being one.
    expect($log->manual_reason)->toBe('Terminal at the loom would not start; figures from the shift sheet.')
        // Who typed it, and who the work belongs to, are two different people and two columns.
        ->and((int) $log->created_by)->toBe($this->supervisor->id)
        ->and((int) $log->operator_id)->toBe((int) $this->operatorId);
});

it('desk: records the shift it belongs to, not the moment it was typed', function (): void {
    $when = now()->subDay()->setTime(22, 30);

    ($this->book)(['occurred_at' => $when->format('Y-m-d H:i:s')]);

    $log = DB::table('operation_logs')
        ->where('job_card_operation_id', $this->operation->id)
        ->orderByDesc('id')->first();

    // Utilisation is measured from this. A night shift keyed the next morning that stamped
    // itself "now" would move the output into the wrong day.
    expect($log->ended_at)->toStartWith($when->format('Y-m-d H:i'));
});

it('desk: refuses a booking with no stated reason', function (): void {
    ($this->book)(['manual_reason' => ''])->assertSessionHasErrors('manual_reason');

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(0.0, 0.0001);
});

it('desk: refuses a booking dated in the future', function (): void {
    ($this->book)(['occurred_at' => now()->addDay()->format('Y-m-d H:i:s')])
        ->assertSessionHasErrors('occurred_at');
});

it('desk: applies J3 exactly as the terminal does', function (): void {
    // The guards live in `OperationBookingService`, not in either caller — a rule that exists
    // on one door and not the other makes the weaker door the one people learn to use.
    ($this->book)(['good_qty' => 900, 'input_qty' => 100])->assertSessionHasErrors();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(0.0, 0.0001);
});

it('desk: applies J5 exactly as the terminal does', function (): void {
    $ceiling = $this->jobCard->overrunCeiling();

    ($this->book)([
        'good_qty' => $ceiling + 1000,
        'input_qty' => $ceiling + 1000,
    ])->assertSessionHasErrors();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(0.0, 0.0001);
});

it('desk: demands a cause for waste, exactly as the terminal does', function (): void {
    ($this->book)(['good_qty' => 480, 'waste_qty' => 20, 'input_qty' => 500])
        ->assertSessionHasErrors('waste_type');

    ($this->book)([
        'good_qty' => 480, 'waste_qty' => 20, 'input_qty' => 500, 'waste_type' => 'setup',
    ])->assertSessionHasNoErrors();

    expect(DB::table('waste_logs')->where('job_card_operation_id', $this->operation->id)->count())->toBe(1);
});

it('desk: moves the order line like the terminal does', function (): void {
    $lineId = $this->jobCard->sales_order_line_id;
    $before = (float) DB::table('sales_order_lines')->where('id', $lineId)->value('produced_qty');

    ($this->book)(['good_qty' => 300, 'input_qty' => 300]);

    expect((float) DB::table('sales_order_lines')->where('id', $lineId)->value('produced_qty'))
        ->toEqualWithDelta($before + 300, 0.0001);
});

it('desk: refuses an operation belonging to another job card', function (): void {
    // A second card, cloned from the first so every NOT NULL foreign key is satisfied. Built
    // rather than found: skipping when the walkthrough holds one card would leave the
    // read-around this names — booking against another card's step by id — never attempted.
    $otherCard = $this->jobCard->replicate(['number']);
    $otherCard->number = 'JC-DESK-'.uniqid('', false);
    $otherCard->save();

    $foreignId = DB::table('job_card_operations')->insertGetId([
        'job_card_id' => $otherCard->id,
        'sequence_no' => 1,
        'code' => 'FOREIGN',
        'name' => 'A step on somebody else\'s card',
        'planned_qty' => 100,
        'status' => JobCardOperation::PENDING,
    ]);

    ($this->book)(['job_card_operation_id' => $foreignId])->assertNotFound();

    // And it stayed untouched, which is the part that matters.
    expect((float) DB::table('job_card_operations')->where('id', $foreignId)->value('good_qty'))
        ->toEqualWithDelta(0.0, 0.0001);
});

it('desk: keeps the door shut to someone without the permission', function (): void {
    $store = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();

    expect($store->hasPermission('operation.log'))->toBeFalse();

    $this->actingAs($store)
        ->post("/job-cards/{$this->jobCard->id}/book-output", [
            'job_card_operation_id' => $this->operation->id,
            'good_qty' => 100,
            'operator_id' => $this->operatorId,
            'occurred_at' => now()->format('Y-m-d H:i:s'),
            'manual_reason' => 'Trying it on.',
        ])->assertForbidden();
});

it('desk: a manual booking can be reversed like any other', function (): void {
    ($this->book)();

    $logId = (int) DB::table('operation_logs')
        ->where('job_card_operation_id', $this->operation->id)
        ->orderByDesc('id')->value('id');

    $this->actingAs($this->supervisor)
        ->post("/job-cards/{$this->jobCard->id}/reverse-log", [
            'operation_log_id' => $logId,
            'reason' => 'Shift sheet was misread.',
        ])->assertRedirect();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(0.0, 0.0001);
});

/**
 * J1 — production may not be recorded against a card that was never released.
 *
 * On the terminal this was never a write-side check: `FloorQueueController` only offers
 * operations whose card is released, in production or on hold, so an unreleased card could not
 * be reached to book against. The desk door has no queue in front of it, and without the gate
 * it booked output against a `planned` card and opened its first step — production running on
 * a card the release gate had not passed.
 */
it('desk: refuses to book against a card that has not been released', function (): void {
    $this->jobCard->forceFill(['status' => JobCard::PLANNED])->save();

    ($this->book)()->assertSessionHasErrors('job_card_operation_id');

    $this->operation->refresh();

    // Neither the figures nor the step's status moved.
    expect((float) $this->operation->good_qty)->toEqualWithDelta(0.0, 0.0001)
        ->and($this->operation->status)->toBe(JobCardOperation::PENDING);
});

it('desk: refuses a draft card too, and accepts a held one', function (): void {
    $this->jobCard->forceFill(['status' => JobCard::DRAFT])->save();
    ($this->book)()->assertSessionHasErrors('job_card_operation_id');

    // A hold stops new work starting; it does not stop the shift that already ran from being
    // written down. The floor queue has always included held cards, so both doors do.
    $this->jobCard->forceFill(['status' => JobCard::ON_HOLD])->save();
    ($this->book)()->assertSessionHasNoErrors();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(500.0, 0.0001);
});

it('terminal: refuses the same unreleased card, so neither door is the softer one', function (): void {
    $this->jobCard->forceFill(['status' => JobCard::PLANNED])->save();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => 'j1-terminal',
    ])->postJson("/api/v1/operations/{$this->operation->id}/log", [
        'good_qty' => 500,
        'waste_qty' => 0,
        'input_qty' => 500,
    ])->assertStatus(422);

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(0.0, 0.0001);
});
