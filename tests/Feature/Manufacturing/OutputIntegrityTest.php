<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * Three ways the floor could record a job that never happened that way.
 *
 * Each was silent: an operation closed with nothing booked, an input forty times its plan
 * accepted without comment, and a job card that added metres to pieces and called the sum its
 * output.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@maheenlabel.test')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->headers = fn (string $key): array => [
        'Authorization' => "Bearer {$this->token}",
        'Idempotency-Key' => $key,
    ];

    $this->operation = $this->jobCard->operations()->reorder('sequence_no', 'asc')->firstOrFail();
    $this->operation->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();
});

it('refuses to finish an operation with nothing booked against it', function (): void {
    $this->postJson("/api/v1/operations/{$this->operation->id}/finish", [], ($this->headers)('empty-finish'))
        ->assertStatus(422)
        ->assertSee('nothing has been booked', escape: false);

    expect($this->operation->refresh()->status)->toBe(JobCardOperation::IN_PROGRESS);
});

it('finishes an empty operation once the reason is recorded', function (): void {
    // Pulling a job off the machine is a real thing; doing it silently is what was wrong.
    $this->postJson("/api/v1/operations/{$this->operation->id}/finish", [
        'no_output_reason' => 'Job pulled off the loom for an urgent order.',
    ], ($this->headers)('empty-finish-reason'))->assertOk();

    expect($this->operation->refresh()->status)->toBe(JobCardOperation::COMPLETED);
});

it('refuses an input far beyond the operation plan without a reason', function (): void {
    $absurd = (float) $this->operation->planned_qty * 40;

    $this->postJson("/api/v1/operations/{$this->operation->id}/log", [
        'good_qty' => 0,
        'waste_qty' => 0,
        'input_qty' => $absurd,
    ], ($this->headers)('input-absurd'))
        ->assertStatus(422)
        ->assertSee('exceeds its', escape: false);

    expect((float) $this->operation->refresh()->input_qty)->toBe(0.0);

    $this->postJson("/api/v1/operations/{$this->operation->id}/log", [
        'good_qty' => 0,
        'waste_qty' => 0,
        'input_qty' => $absurd,
        'input_override_reason' => 'Re-fed the web after a beam change.',
    ], ($this->headers)('input-absurd-reason'))->assertOk();

    expect((float) $this->operation->refresh()->input_qty)->toBeQty($absurd);
});

it('reports job output from the final operation, not the sum across units', function (): void {
    $operations = $this->jobCard->operations()->reorder('sequence_no', 'asc')->get();
    $first = $operations->first();
    $final = $operations->last();

    // Metres at the front of the routing, pieces at the end — the two the header used to add.
    $first->forceFill(['input_qty' => 420, 'good_qty' => 407, 'waste_qty' => 13])->save();
    $final->forceFill(['input_qty' => 30050, 'good_qty' => 30000, 'waste_qty' => 50])->save();

    $payload = $this->actingAs(App\Models\User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail())
        ->get("/job-cards/{$this->jobCard->id}")
        ->viewData('page')['props']['jobCard'];

    expect((float) $payload['good_qty'])->toBeQty(30000.0)
        ->and((float) $payload['waste_qty'])->toBeQty(50.0)
        ->and((float) $payload['produced_qty'])->toBeQty(30050.0);
});
