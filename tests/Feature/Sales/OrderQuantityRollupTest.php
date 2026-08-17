<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * P0-2 — the order line's produced total is fed by the final operation's good output, in the
 * same transaction as the operation log, and the S2 cancellation guard reads it.
 *
 * Before this, `sales_order_lines.produced_qty` had no writer: a confirmed order with 40,000
 * labels on the floor could be cancelled without a reason because the guard summed zeros.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@maheenlabel.test')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');
});

/** Log output against one operation of the shared demo job card, as the floor terminal would. */
function logOutput(object $test, JobCardOperation $operation, float $good, string $key): Illuminate\Testing\TestResponse
{
    return $test->postJson("/api/v1/operations/{$operation->id}/log", [
        'good_qty' => $good,
        'waste_qty' => 0,
        'input_qty' => $good, // J3 — output cannot exceed what was handed to the operation
    ], [
        'Authorization' => "Bearer {$test->token}",
        'Idempotency-Key' => $key,
    ]);
}

it('rolls final-operation good output up to the sales order line', function (): void {
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $before = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    logOutput($this, $final, 1000, 'p02-final-1')->assertOk();

    $after = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    expect($after)->toBeQty($before + 1000)
        // The job card's own totals move too, in the same transaction.
        ->and((float) $this->jobCard->refresh()->good_qty)->toBeGreaterThanOrEqual(1000.0);
});

it('does not count intermediate operations as production', function (): void {
    $first = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    $before = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    // 50,000 labels woven, cut and folded is 50,000 produced — not 150,000.
    logOutput($this, $first, 2500, 'p02-first-1')->assertOk();

    $after = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    expect($after)->toBeQty($before);
});

it('does not double-count a replayed idempotent log', function (): void {
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $before = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    logOutput($this, $final, 800, 'p02-replay')->assertOk();
    // The offline queue drains the same event twice; the ledger must move once.
    logOutput($this, $final, 800, 'p02-replay')->assertOk()->assertJson(['replayed' => true]);

    $after = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    expect($after)->toBeQty($before + 800);
});

it('blocks cancelling an order with production against it unless a reason is documented', function (): void {
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    logOutput($this, $final, 500, 'p02-cancel-guard')->assertOk();

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    /** @var SalesOrder $order */
    $order = SalesOrder::query()->findOrFail(
        DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('sales_order_id'),
    );

    expect((float) $order->lines()->sum('produced_qty'))->toBeGreaterThan(0.0);

    // S2 — the guard now reads a real number. No reason, no cancellation.
    expect(fn () => app(SalesOrderStateMachine::class)->transition($order, 'cancelled'))
        ->toThrow(TransitionDenied::class, 'documented reason');

    // With a documented reason the cancellation is a signed decision, and goes through.
    app(SalesOrderStateMachine::class)->transition($order->refresh(), 'cancelled', [
        'close_reason' => 'Customer withdrew the programme; produced quantity moves to stock.',
    ]);

    expect($order->refresh()->status)->toBe('cancelled');
});
