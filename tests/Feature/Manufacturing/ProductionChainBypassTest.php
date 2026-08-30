<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * J3 / J5 / J7 / QC1 — attacked through the device API rather than through the screen.
 *
 * The audit reported job cards produced past their ceiling, operations consuming more than
 * their predecessor made, and steps completed with nothing booked. Those records exist. What
 * they are *not* is proof of a live hole: a fresh seed of this database produces none of them,
 * and every one of them predates the guards below. The rules are enforced where production is
 * recorded, which is the only place production can be recorded.
 *
 * These tests hold that door shut from the outside. Every request here is the raw HTTP the
 * floor terminal makes — no Vue, no disabled button, no form validation — because a rule that
 * only exists in the client is not a rule.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()
        ->whereNotNull('sales_order_line_id')
        ->whereHas('operations')
        ->firstOrFail();

    $this->jobCard->forceFill([
        'status' => JobCard::IN_PRODUCTION,
        'planned_qty' => 1000,
        'overrun_tolerance_pct' => 3,
    ])->save();

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

    $this->operations = $this->jobCard->operations()->reorder('sequence_no', 'asc')->get();
    $this->first = $this->operations->first();
    $this->second = $this->operations->skip(1)->first();

    // Everything upstream done and clear, so each test isolates the one rule it names.
    $this->openAll = function (): void {
        foreach ($this->operations as $op) {
            $op->forceFill([
                'status' => JobCardOperation::IN_PROGRESS,
                'requires_qc' => false,
                'input_qty' => 0, 'good_qty' => 0, 'waste_qty' => 0,
            ])->save();
        }
    };
});

// --- J7: the chain ----------------------------------------------------------------------

it('j7: refuses production on a step whose predecessor has not finished', function (): void {
    if ($this->second === null) {
        $this->markTestSkipped('The chosen job card has a single operation.');
    }

    ($this->openAll)();
    $this->first->forceFill(['status' => JobCardOperation::IN_PROGRESS])->save();

    $this->postJson("/api/v1/operations/{$this->second->id}/log", [
        'good_qty' => 100, 'waste_qty' => 0, 'input_qty' => 100,
    ], ($this->headers)('j7-pending'))
        ->assertStatus(422)
        ->assertSee('is still in progress', escape: false);

    expect((float) $this->second->refresh()->good_qty)->toBe(0.0);
});

it('j7: refuses an input larger than the predecessor produced, in the same unit', function (): void {
    if ($this->second === null) {
        $this->markTestSkipped('The chosen job card has a single operation.');
    }

    // Only meaningful where the two steps count the same way.
    if (! $this->second->sharesUnitWith($this->first)) {
        $this->markTestSkipped('These two operations do not share a unit; J7 deliberately does not compare them.');
    }

    ($this->openAll)();
    $this->first->forceFill([
        'status' => JobCardOperation::COMPLETED, 'input_qty' => 200, 'good_qty' => 100,
    ])->save();

    $this->postJson("/api/v1/operations/{$this->second->id}/log", [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 5000,
    ], ($this->headers)('j7-over'))
        ->assertStatus(422)
        ->assertSee('has produced', escape: false);

    expect((float) $this->second->refresh()->input_qty)->toBe(0.0);
});

it('j7: allows an input the predecessor genuinely covers', function (): void {
    if ($this->second === null || ! $this->second->sharesUnitWith($this->first)) {
        $this->markTestSkipped('Needs two operations sharing a unit.');
    }

    ($this->openAll)();
    $this->first->forceFill([
        'status' => JobCardOperation::COMPLETED, 'input_qty' => 200, 'good_qty' => 100,
    ])->save();

    $this->postJson("/api/v1/operations/{$this->second->id}/log", [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 100,
    ], ($this->headers)('j7-ok'))->assertOk();

    expect((float) $this->second->refresh()->input_qty)->toBe(100.0);
});

it('j7: does not compare quantities across different units', function (): void {
    // 407 m does not cap 30,100 pcs. Comparing them as bare numbers would block every real
    // job, which is why the rule asks about units before it asks about quantities.
    //
    // The boundary is found rather than assumed: it is wherever the routing stops consuming
    // the web and starts counting pieces, which on this routing is the cut after the weave.
    $boundary = null;

    foreach ($this->operations as $index => $op) {
        $previous = $index > 0 ? $this->operations[$index - 1] : null;

        if ($previous !== null && ! $op->sharesUnitWith($previous)) {
            $boundary = [$previous, $op];
            break;
        }
    }

    if ($boundary === null) {
        $this->markTestSkipped('This routing counts every step in the same unit.');
    }

    [$web, $pieces] = $boundary;

    ($this->openAll)();

    foreach ($this->operations as $op) {
        if ($op->sequence_no > $web->sequence_no) {
            continue;
        }

        $op->forceFill([
            'status' => JobCardOperation::COMPLETED, 'requires_qc' => false,
            'input_qty' => 500, 'good_qty' => 200,
        ])->save();
    }

    // Far more pieces than the predecessor made metres — legitimate, and allowed.
    $this->postJson("/api/v1/operations/{$pieces->id}/log", [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 900,
    ], ($this->headers)('j7-units'))->assertOk();

    expect((float) $pieces->refresh()->input_qty)->toBe(900.0);
});

// --- QC1: the gate ----------------------------------------------------------------------

it('qc1: refuses production downstream of an operation still awaiting inspection', function (): void {
    if ($this->second === null) {
        $this->markTestSkipped('The chosen job card has a single operation.');
    }

    ($this->openAll)();
    $this->first->forceFill([
        'status' => JobCardOperation::COMPLETED, 'requires_qc' => true,
        'input_qty' => 1000, 'good_qty' => 1000,
    ])->save();

    DB::table('qc_inspections')->where('job_card_id', $this->jobCard->id)->delete();

    $this->postJson("/api/v1/operations/{$this->second->id}/log", [
        'good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10,
    ], ($this->headers)('qc1-block'))
        ->assertStatus(422)
        ->assertSee('QC1', escape: false);
});

// --- J5: the job ceiling ----------------------------------------------------------------

it('j5: refuses output that would take the job past its overrun ceiling', function (): void {
    ($this->openAll)();

    $final = $this->operations->last();

    foreach ($this->operations as $op) {
        if ($op->id === $final->id) {
            continue;
        }

        $op->forceFill([
            'status' => JobCardOperation::COMPLETED, 'requires_qc' => false,
            'input_qty' => 100000, 'good_qty' => 100000,
        ])->save();
    }

    $final->forceFill(['status' => JobCardOperation::IN_PROGRESS, 'input_qty' => 100000])->save();

    // 1,000 planned at 3% is a ceiling of 1,030.
    $this->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => 1500, 'waste_qty' => 0, 'input_qty' => 0,
    ], ($this->headers)('j5-over'))
        ->assertStatus(422)
        ->assertSee('J5', escape: false);

    expect((float) $final->refresh()->good_qty)->toBe(0.0);
});

it('j5: allows output inside the overrun ceiling', function (): void {
    ($this->openAll)();

    $final = $this->operations->last();

    foreach ($this->operations as $op) {
        if ($op->id === $final->id) {
            continue;
        }

        $op->forceFill([
            'status' => JobCardOperation::COMPLETED, 'requires_qc' => false,
            'input_qty' => 100000, 'good_qty' => 100000,
        ])->save();
    }

    $final->forceFill(['status' => JobCardOperation::IN_PROGRESS, 'input_qty' => 100000])->save();

    $this->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => 1020, 'waste_qty' => 0, 'input_qty' => 0,
    ], ($this->headers)('j5-ok'))->assertOk();

    expect((float) $final->refresh()->good_qty)->toBe(1020.0);
});

// --- J3: output against input -----------------------------------------------------------

it('j3: refuses booking more good and waste than the operation was handed', function (): void {
    ($this->openAll)();

    $final = $this->operations->last();

    foreach ($this->operations as $op) {
        if ($op->id === $final->id) {
            continue;
        }

        $op->forceFill([
            'status' => JobCardOperation::COMPLETED, 'requires_qc' => false,
            'input_qty' => 100000, 'good_qty' => 100000,
        ])->save();
    }

    $final->forceFill(['status' => JobCardOperation::IN_PROGRESS, 'input_qty' => 10])->save();

    $this->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => 500, 'waste_qty' => 0, 'input_qty' => 0,
    ], ($this->headers)('j3-over'))->assertStatus(422);

    expect((float) $final->refresh()->good_qty)->toBe(0.0);
});

// --- the operation must belong to a job that can take production -------------------------

it('refuses production against a closed operation', function (): void {
    ($this->openAll)();
    $this->first->forceFill(['status' => JobCardOperation::COMPLETED])->save();

    $this->postJson("/api/v1/operations/{$this->first->id}/log", [
        'good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10,
    ], ($this->headers)('closed-op'))
        ->assertStatus(422)
        ->assertSee('because the step is', escape: false);
});

it('refuses an operation id that does not exist', function (): void {
    $this->postJson('/api/v1/operations/99999999/log', [
        'good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10,
    ], ($this->headers)('missing-op'))->assertNotFound();
});

it('refuses production without a device session at all', function (): void {
    // No token: the floor API is not open to anyone who can reach the port.
    $this->postJson("/api/v1/operations/{$this->first->id}/log", [
        'good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10,
    ])->assertUnauthorized();
});
