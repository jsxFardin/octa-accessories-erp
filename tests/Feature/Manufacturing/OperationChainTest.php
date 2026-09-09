<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * F-04 / J2 — an operation's quantities may not exist independently of the step before it.
 *
 * Only `start` used to ask whether the predecessor was done, so a client that posted straight
 * to `log` skipped the question entirely. That is how three `pending` steps came to hold
 * 5,000 good apiece behind a step that had produced nothing, and how a packing step was handed
 * 21,500 by a folding step that reported zero.
 *
 * The chain is a quantity rule only where the two steps count the same thing. Weaving makes
 * metres and folding makes pieces out of them; 407 m does not cap 30,100 pcs, and a rule that
 * pretended otherwise would refuse every real job on the floor.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'Chain test walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    // BR-48 — a job that produced finished goods consumed material to do it. The store's
    // issue is the fixture; F-08 is why the FG receipt now insists on it.
    issueMaterialFor($this->jobCard->refresh(), 60000);

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card, 'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->headers = fn (string $key): array => [
        'Authorization' => "Bearer {$this->token}",
        'Idempotency-Key' => $key,
    ];

    $this->operations = $this->jobCard->operations()->with('routingOperation')->get()->values();
});

function chainLog(object $test, JobCardOperation $operation, array $payload, string $key): Illuminate\Testing\TestResponse
{
    return $test->postJson("/api/v1/operations/{$operation->id}/log", $payload, ($test->headers)($key));
}

/** Pass the in-process inspection a `requires_qc` step needs before its successor may run. */
function acceptInProcessQc(object $test, JobCardOperation $operation): void
{
    $test->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail())
        ->post('/qc-inspections', [
            'job_card_id' => $operation->job_card_id,
            'job_card_operation_id' => $operation->id,
            'stage' => 'in_process',
            'lot_size' => 500,
            'major_found' => 0, 'minor_found' => 0, 'critical_found' => 0,
        ])->assertSessionHasNoErrors();
}

it('refuses production on a step whose predecessor is still open, and names it', function (): void {
    $folding = $this->operations->firstWhere('code', 'fold');
    // The nearest open step, not the first one — that is the one the floor has to chase.
    $cutting = $this->operations->firstWhere('code', 'cut');

    $response = chainLog($this, $folding, [
        'good_qty' => 5000, 'waste_qty' => 0, 'input_qty' => 5000,
    ], 'chain-blocked');

    $response->assertStatus(422);

    expect($response->json('message'))
        ->toContain('Production cannot be recorded for Folding')
        ->toContain($cutting->name)
        ->toContain('(step 3) is still pending')
        ->toContain('has produced nothing');

    // And nothing was written — not on the operation, not in the log, not on the card.
    expect((float) $folding->refresh()->good_qty)->toBeQty(0.0)
        ->and($folding->status)->toBe(JobCardOperation::PENDING)
        ->and(DB::table('operation_logs')->where('job_card_operation_id', $folding->id)->count())->toBe(0)
        ->and((float) $this->jobCard->refresh()->good_qty)->toBeQty(0.0);
});

it('lets the first operation of a routing record production with no predecessor at all', function (): void {
    $warping = $this->operations->firstWhere('code', 'warp');

    chainLog($this, $warping, [
        'good_qty' => 300, 'waste_qty' => 4, 'input_qty' => 324.3, 'waste_type' => 'setup',
    ], 'chain-first')->assertOk();

    expect((float) $warping->refresh()->good_qty)->toBeQty(300.0)
        // Booking output on a queued step opens it, rather than leaving production against
        // a step the floor still calls `pending`.
        ->and($warping->status)->toBe(JobCardOperation::IN_PROGRESS);
});

it('refuses to hand a step more than the step before it produced, in the same unit', function (): void {
    $warping = $this->operations->firstWhere('code', 'warp');
    $weaving = $this->operations->firstWhere('code', 'weave');

    chainLog($this, $warping, ['good_qty' => 300, 'waste_qty' => 0, 'input_qty' => 324.3], 'chain-warp')->assertOk();
    $this->postJson("/api/v1/operations/{$warping->id}/finish", [], ($this->headers)('chain-warp-finish'))->assertOk();

    expect($warping->refresh()->unit())->toBe('m')
        ->and($weaving->unit())->toBe('m');

    $response = chainLog($this, $weaving, [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 500,
    ], 'chain-weave-over');

    $response->assertStatus(422);

    expect($response->json('message'))
        ->toContain('Warping has produced 300 m')
        ->toContain('500 m would already have been handed to Weaving');

    expect((float) $weaving->refresh()->input_qty)->toBeQty(0.0);
});

it('accepts exactly what the step before it produced', function (): void {
    $warping = $this->operations->firstWhere('code', 'warp');
    $weaving = $this->operations->firstWhere('code', 'weave');

    chainLog($this, $warping, ['good_qty' => 300, 'waste_qty' => 0, 'input_qty' => 324.3], 'chain-warp-2')->assertOk();
    $this->postJson("/api/v1/operations/{$warping->id}/finish", [], ($this->headers)('chain-warp-2-finish'))->assertOk();

    chainLog($this, $weaving, ['good_qty' => 290, 'waste_qty' => 10, 'input_qty' => 300, 'waste_type' => 'weave_defect'], 'chain-weave-exact')->assertOk();

    expect((float) $weaving->refresh()->input_qty)->toBeQty(300.0)
        ->and((float) $weaving->good_qty)->toBeQty(290.0);
});

it('does not cap a piece-counting step by a metre-counting one', function (): void {
    $cutting = $this->operations->firstWhere('code', 'cut');
    $folding = $this->operations->firstWhere('code', 'fold');

    expect($cutting->unit())->toBe('m')
        ->and($folding->unit())->toBe('pcs')
        ->and($folding->inputAvailableFromPredecessor())->toBeNull();

    completeOperationsBefore($folding, 324.3);

    // 324 m of web becomes tens of thousands of labels. The chain rule has nothing to say
    // about the conversion — that is the consumption plan's job — so this must pass.
    chainLog($this, $folding, [
        'good_qty' => 49000, 'waste_qty' => 100, 'input_qty' => 49100, 'waste_type' => 'cutting',
    ], 'chain-unit-change')->assertOk();

    expect((float) $folding->refresh()->good_qty)->toBeQty(49000.0);
});

it('runs a whole routing end to end through the terminal', function (): void {
    $expected = [
        ['warp', 324.3, 320.0, 4.3],
        ['weave', 320.0, 316.0, 4.0],
        ['cut', 316.0, 312.0, 4.0],
        ['fold', 49000.0, 48900.0, 100.0],
        ['pack', 48900.0, 48800.0, 100.0],
    ];

    foreach ($expected as $index => [$code, $input, $good, $waste]) {
        $operation = $this->operations->firstWhere('code', $code);

        chainLog($this, $operation, [
            'good_qty' => $good, 'waste_qty' => $waste, 'input_qty' => $input,
            // G4 — waste is booked with a cause now, so a booking that has waste names one.
            'waste_type' => $waste > 0 ? 'setup' : null,
        ], "chain-full-{$code}")->assertOk();

        if ($operation->requires_qc) {
            acceptInProcessQc($this, $operation);
        }

        $this->postJson("/api/v1/operations/{$operation->id}/finish", [], ($this->headers)("chain-full-finish-{$code}"))
            ->assertOk();

        expect((float) $operation->refresh()->good_qty)->toBeQty($good);
    }

    // The job's output is the last step's, in pieces — not the sum of five steps in two units.
    expect($this->jobCard->refresh()->finalOperationOutput()['good'])->toBeQty(48800.0)
        ->and($this->jobCard->status)->toBe(JobCard::QC_PENDING);
});

it('refuses production on an operation that is already closed', function (): void {
    $warping = $this->operations->firstWhere('code', 'warp');
    $warping->forceFill(['status' => JobCardOperation::COMPLETED])->save();

    $response = chainLog($this, $warping, [
        'good_qty' => 10, 'waste_qty' => 0, 'input_qty' => 10,
    ], 'chain-closed');

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('the step is completed')
        ->and((float) $warping->refresh()->good_qty)->toBeQty(0.0);
});

it('refuses production downstream of an uninspected step that requires QC', function (): void {
    $weaving = $this->operations->firstWhere('code', 'weave');
    $cutting = $this->operations->firstWhere('code', 'cut');

    expect((bool) $weaving->requires_qc)->toBeTrue();

    // Weaving is finished and has output; what it has not had is an inspection.
    DB::table('job_card_operations')->whereIn('id', [
        $this->operations->firstWhere('code', 'warp')->id,
        $weaving->id,
    ])->update(['input_qty' => 320, 'good_qty' => 320, 'status' => JobCardOperation::COMPLETED]);

    $response = chainLog($this, $cutting, [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 320,
    ], 'chain-qc1');

    $response->assertStatus(422);

    expect($response->json('message'))->toContain('needs an accepted inspection first (QC1)')
        ->and((float) $cutting->refresh()->input_qty)->toBeQty(0.0);

    acceptInProcessQc($this, $weaving);

    chainLog($this, $cutting, [
        'good_qty' => 0, 'waste_qty' => 0, 'input_qty' => 320,
    ], 'chain-qc1-cleared')->assertOk();
});

it('leaves no operation log behind when the chain refuses the write', function (): void {
    $packing = $this->operations->firstWhere('code', 'pack');

    $logsBefore = DB::table('operation_logs')->count();
    $cardBefore = $this->jobCard->refresh()->only(['good_qty', 'waste_qty', 'produced_qty']);
    $lineBefore = DB::table('sales_order_lines')
        ->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    chainLog($this, $packing, [
        'good_qty' => 21500, 'waste_qty' => 0, 'input_qty' => 21500,
    ], 'chain-rollback')->assertStatus(422);

    expect(DB::table('operation_logs')->count())->toBe($logsBefore)
        ->and($this->jobCard->refresh()->only(['good_qty', 'waste_qty', 'produced_qty']))->toBe($cardBefore)
        ->and(DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty'))
        ->toBe($lineBefore);
});

it('shows the operator which step is holding theirs up before they try', function (): void {
    $this->actingAs(User::query()->where('email', 'planner@octapussolution.com')->firstOrFail())
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(function (Inertia\Testing\AssertableInertia $page): void {
            $operations = collect($page->toArray()['props']['operations']);

            expect($operations->firstWhere('code', 'warp')['predecessors_complete'])->toBeTrue()
                ->and($operations->firstWhere('code', 'pack')['predecessors_complete'])->toBeFalse();
        });
});
