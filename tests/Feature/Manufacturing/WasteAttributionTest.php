<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * G4 — "wastage % per machine tracked and trending down" needs a cause, not a number.
 *
 * Waste reached the system as a bare quantity on the operation row. `waste_logs` — the table
 * built to hold the cause, the item, the lot and the value — was queried by the job card's own
 * page and written by nothing in the codebase, not even the seeder, so its panel was
 * permanently empty and no report could say whether a loom was losing metres to setup, to
 * shade or to a weave defect. Each of those is a different fix.
 */
beforeEach(function (): void {
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

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->log = fn (array $payload) => $this->withHeaders([
        'Authorization' => "Bearer {$this->token}",
        'Idempotency-Key' => 'waste-'.uniqid('', true),
    ])->postJson("/api/v1/operations/{$this->operation->id}/log", $payload);
});

it('waste: books the cause alongside the quantity', function (): void {
    ($this->log)([
        'good_qty' => 480,
        'waste_qty' => 20,
        'input_qty' => 500,
        'waste_type' => 'setup',
    ])->assertOk();

    $waste = DB::table('waste_logs')->where('job_card_operation_id', $this->operation->id)->first();

    expect($waste)->not->toBeNull()
        ->and($waste->waste_type)->toBe('setup')
        ->and((float) $waste->qty)->toEqualWithDelta(20.0, 0.0001)
        ->and($waste->reported_by)->not->toBeNull()
        // The unit the step counts in, so metres wasted are never added to pieces wasted.
        ->and($waste->uom_id)->not->toBeNull();
});

it('waste: refuses a quantity with no cause', function (): void {
    ($this->log)([
        'good_qty' => 480,
        'waste_qty' => 20,
        'input_qty' => 500,
    ])->assertStatus(422);

    expect(DB::table('waste_logs')->where('job_card_operation_id', $this->operation->id)->exists())
        ->toBeFalse();
});

it('waste: refuses a cause outside the vocabulary the table allows', function (): void {
    // `waste_logs_type_chk` would throw a 500 on the insert; the refusal has to arrive first.
    ($this->log)([
        'good_qty' => 480,
        'waste_qty' => 20,
        'input_qty' => 500,
        'waste_type' => 'operator_was_tired',
    ])->assertStatus(422);
});

it('waste: leaves an ordinary booking with no waste alone', function (): void {
    // `required_with` fires on a present-but-zero field, which every ordinary booking sends.
    // The reason is owed when there is waste, not when the key is in the payload.
    ($this->log)([
        'good_qty' => 500,
        'waste_qty' => 0,
        'input_qty' => 500,
    ])->assertOk();

    expect(DB::table('waste_logs')->where('job_card_operation_id', $this->operation->id)->exists())
        ->toBeFalse();
});

it('waste: takes the waste back out when its booking is reversed', function (): void {
    ($this->log)([
        'good_qty' => 480,
        'waste_qty' => 20,
        'input_qty' => 500,
        'waste_type' => 'shade',
    ])->assertOk();

    $logId = (int) DB::table('operation_logs')
        ->where('job_card_operation_id', $this->operation->id)
        ->orderByDesc('id')->value('id');

    expect(DB::table('waste_logs')->where('operation_log_id', $logId)->count())->toBe(1);

    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->actingAs($supervisor)
        ->post("/job-cards/{$this->jobCard->id}/reverse-log", [
            'operation_log_id' => $logId,
            'reason' => 'Operator recorded the wrong shade figure.',
        ])->assertRedirect();

    // Derived from the booking, so it goes out with it — otherwise a reversed 20 would stand
    // in the waste report for good. `waste_logs_qty_chk` forbids a negating entry.
    expect(DB::table('waste_logs')->where('operation_log_id', $logId)->exists())->toBeFalse();
});

it('waste: shows the causes on the job card', function (): void {
    // The prop was declared, queried on every page load and rendered nowhere, against a table
    // nothing wrote to.
    ($this->log)([
        'good_qty' => 480,
        'waste_qty' => 20,
        'input_qty' => 500,
        'waste_type' => 'edge_trim',
    ])->assertOk();

    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->actingAs($supervisor)
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(fn ($page) => $page
            ->has('wasteLogs', 1)
            ->where('wasteLogs.0.waste_type', 'edge_trim'));
});
