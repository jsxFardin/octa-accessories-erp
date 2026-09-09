<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use Illuminate\Support\Facades\DB;

/**
 * I1, applied to production: a correction is a reversing entry, never an edit.
 *
 * Production had no correction path at all. `operation_logs` rows were written once by the
 * terminal and never listed, edited or undone; `job_card_operations.good_qty` only ever
 * accumulated; and `sales_order_lines.produced_qty` was `increment()`-ed by the final
 * operation and decremented by nothing in the codebase. An operator who typed 5,000 where they
 * meant 500 left a figure no screen could correct, which the order line's fulfilment position
 * then carried for the life of the order — and which the S2 cancellation guard and the job
 * card "remaining to cover" filter both read as fact.
 */
beforeEach(function (): void {
    $this->supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    /*
     * The *final* operation, deliberately. Earlier steps on this routing are measured in
     * metres (warping, weaving, cutting) and the last two in pieces, so a 500-piece booking
     * belongs on the end of the chain — and the final step is also the only one whose good
     * output reaches the order line (P0-2), which is the roll-back this is here to prove.
     */
    // `reorder`, not `orderByDesc`: the relation already sorts by `sequence_no` ascending, so
    // an added descending sort is a secondary key and returns step 1 regardless.
    $this->operation = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    // J2/J7 want the chain behind it settled before it may book anything.
    $this->jobCard->operations()
        ->where('sequence_no', '<', $this->operation->sequence_no)
        ->get()
        ->each(fn (JobCardOperation $step) => $step->forceFill([
            'status' => JobCardOperation::COMPLETED,
            'input_qty' => 60000,
            'good_qty' => 60000,
            // QC1 holds a step until an earlier `requires_qc` one has an accepted inspection.
            // That gate has its own tests; this fixture is about what happens after output is
            // booked, so it clears the flag rather than staging an inspection to satisfy it.
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

    // Book a shift's output the way the floor does, so the reversal has something real to undo.
    $this->book = function (float $good, float $waste = 0): int {
        $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Idempotency-Key' => 'book-'.uniqid('', true),
        ])->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => $good,
            'waste_qty' => $waste,
            'input_qty' => $good + $waste,
            // G4 — waste is booked with a cause; this fixture is about the reversal, not the cause.
            'waste_type' => $waste > 0 ? 'setup' : null,
        ])->assertOk();

        return (int) DB::table('operation_logs')
            ->where('job_card_operation_id', $this->operation->id)
            ->orderByDesc('id')->value('id');
    };

    $this->reverse = fn (int $logId, string $reason = 'Operator keyed the wrong figure.') => $this
        ->actingAs($this->supervisor)
        ->post("/job-cards/{$this->jobCard->id}/reverse-log", [
            'operation_log_id' => $logId,
            'reason' => $reason,
        ]);
});

it('production: a reversal takes the quantities back off the operation', function (): void {
    $before = (float) $this->operation->good_qty;

    $logId = ($this->book)(500, 20);

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta($before + 500, 0.0001);

    ($this->reverse)($logId)->assertRedirect();

    expect((float) $this->operation->refresh()->good_qty)->toEqualWithDelta($before, 0.0001);
});

it('production: the original booking is kept exactly as the operator recorded it', function (): void {
    $logId = ($this->book)(500);

    ($this->reverse)($logId);

    $original = DB::table('operation_logs')->where('id', $logId)->first();

    // Rewriting the row would destroy the evidence that the mistake happened.
    expect((float) $original->good_qty)->toEqualWithDelta(500.0, 0.0001);

    $reversal = DB::table('operation_logs')->where('reverses_log_id', $logId)->first();

    expect($reversal)->not->toBeNull()
        ->and((float) $reversal->good_qty)->toEqualWithDelta(-500.0, 0.0001)
        ->and($reversal->reversal_reason)->toBe('Operator keyed the wrong figure.');
});

it('production: rolls the order line back, which nothing in the application could do', function (): void {
    $lineId = $this->jobCard->sales_order_line_id;
    $before = (float) DB::table('sales_order_lines')->where('id', $lineId)->value('produced_qty');

    $logId = ($this->book)(300);

    expect((float) DB::table('sales_order_lines')->where('id', $lineId)->value('produced_qty'))
        ->toEqualWithDelta($before + 300, 0.0001);

    ($this->reverse)($logId);

    expect((float) DB::table('sales_order_lines')->where('id', $lineId)->value('produced_qty'))
        ->toEqualWithDelta($before, 0.0001);
});

it('production: rolls the job card running totals back too', function (): void {
    $before = (float) $this->jobCard->good_qty_running;

    $logId = ($this->book)(250, 10);

    ($this->reverse)($logId);

    expect((float) $this->jobCard->refresh()->good_qty_running)->toEqualWithDelta($before, 0.0001);
});

it('production: refuses to reverse the same booking twice', function (): void {
    $logId = ($this->book)(100);

    ($this->reverse)($logId)->assertRedirect();
    ($this->reverse)($logId)->assertSessionHasErrors('operation_log_id');

    expect(DB::table('operation_logs')->where('reverses_log_id', $logId)->count())->toBe(1);
});

it('production: refuses to reverse a reversal', function (): void {
    $logId = ($this->book)(100);

    ($this->reverse)($logId);

    $reversalId = (int) DB::table('operation_logs')->where('reverses_log_id', $logId)->value('id');

    ($this->reverse)($reversalId)->assertSessionHasErrors('operation_log_id');
});

it('production: requires a stated reason', function (): void {
    $logId = ($this->book)(100);

    // A correction with no reason cannot be told apart from tampering.
    ($this->reverse)($logId, '')->assertSessionHasErrors('reason');

    expect(DB::table('operation_logs')->where('reverses_log_id', $logId)->exists())->toBeFalse();
});

it('production: refuses a reversal that would drive the operation negative', function (): void {
    $logId = ($this->book)(100);

    // Someone unwound the figures by hand between the booking and the correction.
    $this->operation->forceFill(['good_qty' => 10])->save();

    ($this->reverse)($logId)->assertSessionHasErrors('operation_log_id');
});

it('production: keeps the correction away from the operator who booked it', function (): void {
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    // An operator books their own output; a supervisor un-books it.
    expect($operator->hasPermission('operation.update'))->toBeFalse();

    $logId = ($this->book)(100);

    $this->actingAs($operator)
        ->post("/job-cards/{$this->jobCard->id}/reverse-log", [
            'operation_log_id' => $logId,
            'reason' => 'Trying to undo my own booking.',
        ])->assertForbidden();
});

it('production: shows the shift bookings on the job card they belong to', function (): void {
    // They were written once by the terminal and read by one report — never listed on the card
    // the production belongs to.
    ($this->book)(500);

    $this->actingAs($this->supervisor)
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(fn ($page) => $page
            ->component('Manufacturing/JobCards/Show')
            ->has('operationLogs', 1)
            ->where('operationLogs.0.good_qty', fn ($value): bool => (float) $value === 500.0));
});

/**
 * A reversal has to take back the input it brought, not just the output.
 *
 * `operation_logs` deliberately had no `input_qty`: the running total lived on the operation
 * row and J3 was checked against it. That held while nothing could be undone. A correction
 * takes back what it can read off the row being reversed, and it could not take back a figure
 * nobody wrote down — so a reversed booking left its input behind. The operation showed 6,500
 * handed over with nothing produced from it, and the next legitimate booking of that same
 * 6,500 was refused as "13,000 exceeds its 6,695 plan". The supervisor undid a mistake and the
 * system kept half of it.
 */
it('production: takes the input back with the output', function (): void {
    $before = (float) $this->operation->input_qty;

    $logId = ($this->book)(500, 20);

    expect((float) $this->operation->refresh()->input_qty)->toEqualWithDelta($before + 520, 0.0001);

    ($this->reverse)($logId)->assertRedirect();

    expect((float) $this->operation->refresh()->input_qty)->toEqualWithDelta($before, 0.0001);
});

it('production: lets the same quantity be booked again after a reversal', function (): void {
    // The symptom that exposed it: reverse a booking, key the corrected figure, and be told
    // the operation is over its plan because the first attempt's input never left.
    $logId = ($this->book)(500, 20);
    ($this->reverse)($logId);

    // Same figures again, and no J3 refusal this time.
    $again = ($this->book)(500, 20);

    expect($again)->toBeInt()
        ->and((float) $this->operation->refresh()->good_qty)->toEqualWithDelta(500.0, 0.0001);
});

it('production: records the negated input on the reversing row', function (): void {
    $logId = ($this->book)(300, 0);

    ($this->reverse)($logId);

    $reversal = DB::table('operation_logs')->where('reverses_log_id', $logId)->first();

    expect((float) $reversal->input_qty)->toEqualWithDelta(-300.0, 0.0001);
});
