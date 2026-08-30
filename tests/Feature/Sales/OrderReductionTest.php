<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardPlanningGuard;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/**
 * S1 / BR-53 — what an amendment may do to a line the floor is already working on.
 *
 * SO-26-00029 was amended from 30,000 pcs to 20,000 with a proper reason recorded, while a job
 * card for 30,000 was already on the floor and eventually produced 45,883. Two separate holes
 * met there:
 *
 * - **S1** is written in the domain model and quoted in a comment inside `recordAmendments()`
 *   — "quantity may only be reduced as far as what is already produced" — and was enforced
 *   nowhere. `ordered_qty` was validated as `numeric|gt:0` and nothing else, so an order could
 *   be cut below production that had already happened and could never be delivered against.
 * - **BR-53** is the lesser case S1 must *not* refuse: reducing below what job cards have
 *   merely *committed*. That work may not have started and the customer really did cut the
 *   order, so cancelling a card is a planner's decision, not a side effect of an edit. It is
 *   allowed, and made visible.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();

    $this->order = SalesOrder::query()->whereHas('lines')->firstOrFail();
    $this->order->forceFill(['status' => 'confirmed'])->save();

    $this->line = $this->order->lines()->firstOrFail();
    $this->line->forceFill([
        'ordered_qty' => 30000,
        'produced_qty' => 0,
        'over_tolerance_pct' => 5,
        'under_tolerance_pct' => 5,
    ])->save();

    // The payload the edit form posts back, with one line quantity changed.
    $this->amendTo = fn (float $qty) => $this->actingAs($this->merchandiser)
        ->put("/sales-orders/{$this->order->id}", [
            'customer_id' => $this->order->customer_id,
            'order_date' => $this->order->order_date instanceof DateTimeInterface
                ? $this->order->order_date->format('Y-m-d')
                : (string) $this->order->order_date,
            'currency_id' => $this->order->currency_id,
            'exchange_rate' => $this->order->exchange_rate,
            'priority' => $this->order->priority ?? 'normal',
            'amendment_reason' => 'Customer cut the order.',
            'lines' => $this->order->lines->map(fn ($l): array => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'product_spec_id' => $l->product_spec_id,
                'description' => $l->description,
                'ordered_qty' => $l->id === $this->line->id ? $qty : (float) $l->ordered_qty,
                'rate_per_m' => $l->rate_per_m,
            ])->all(),
        ]);
});

it('s1: refuses to reduce a line below what has already been produced', function (): void {
    $this->line->forceFill(['produced_qty' => 25000])->save();

    ($this->amendTo)(20000)->assertSessionHasErrors();

    expect((float) $this->line->refresh()->ordered_qty)->toBe(30000.0);
});

it('s1: names the produced quantity and what to do instead', function (): void {
    $this->line->forceFill(['produced_qty' => 25000])->save();

    // The refusal is a ValidationException, which Pest surfaces rather than swallowing, so it
    // is caught and read directly. What matters is that the message tells the merchandiser the
    // figure that blocks them and what to do about it.
    // Without the handler in the way the guard's own exception arrives here intact, rather
    // than as a redirect carrying a session error bag.
    $this->withoutExceptionHandling();

    try {
        ($this->amendTo)(20000);
        $this->fail('The reduction should have been refused.');
    } catch (ValidationException $e) {
        $message = json_encode($e->errors(), JSON_THROW_ON_ERROR);

        expect($message)->toContain('already produced 25,000')
            ->toContain('S1')
            ->toContain('cancel the job cards first');
    }

    expect((float) $this->line->refresh()->ordered_qty)->toBe(30000.0);
});

it('s1: allows a reduction down to exactly what was produced', function (): void {
    $this->line->forceFill(['produced_qty' => 25000])->save();

    ($this->amendTo)(25000)->assertSessionHasNoErrors();

    expect((float) $this->line->refresh()->ordered_qty)->toBe(25000.0);
});

it('s1: allows an increase', function (): void {
    $this->line->forceFill(['produced_qty' => 25000])->save();

    ($this->amendTo)(40000)->assertSessionHasNoErrors();

    expect((float) $this->line->refresh()->ordered_qty)->toBe(40000.0);
});

it('s1: cannot be bypassed by posting the update directly', function (): void {
    // The edit form is not the rule. This is the same request with no form behind it.
    $this->line->forceFill(['produced_qty' => 25000])->save();

    ($this->amendTo)(1)->assertSessionHasErrors();

    expect((float) $this->line->refresh()->ordered_qty)->toBe(30000.0);
});

it('br53: still allows reducing below what job cards have merely committed', function (): void {
    // Nothing produced yet — the customer genuinely cut the order and the planner will decide
    // what to do with the card. Refusing this would be S1 overreaching.
    $this->line->forceFill(['produced_qty' => 0])->save();

    ($this->amendTo)(20000)->assertSessionHasNoErrors();

    expect((float) $this->line->refresh()->ordered_qty)->toBe(20000.0);
});

it('br53: reports the over-allocation a reduction leaves behind', function (): void {
    $card = JobCard::query()->where('sales_order_line_id', $this->line->id)
        ->whereNotIn('status', [JobCard::CANCELLED])->first();

    if ($card === null) {
        $card = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
        $card->forceFill(['sales_order_line_id' => $this->line->id])->save();
    }

    $card->forceFill(['planned_qty' => 30000, 'status' => JobCard::IN_PRODUCTION])->save();

    // 20,000 at 5% allows 21,000; a live card holds 30,000.
    $this->line->forceFill(['ordered_qty' => 20000, 'produced_qty' => 0])->save();

    $conflict = app(JobCardPlanningGuard::class)->overAllocation($this->line->refresh());

    expect($conflict)->not->toBeNull()
        ->and($conflict['committed'])->toBe(30000.0)
        ->and($conflict['allowance'])->toBe(21000.0)
        ->and($conflict['excess'])->toBe(9000.0);
});

it('br53: shows the conflict on the order screen', function (): void {
    $card = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $card->forceFill([
        'sales_order_line_id' => $this->line->id,
        'planned_qty' => 30000,
        'status' => JobCard::IN_PRODUCTION,
    ])->save();

    $this->line->forceFill(['ordered_qty' => 20000, 'produced_qty' => 0])->save();

    $this->actingAs($this->admin)
        ->get("/sales-orders/{$this->order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $line = collect($page->toArray()['props']['lines'])->firstWhere('id', $this->line->id);

            expect($line['over_allocation'])->not->toBeNull()
                ->and($line['over_allocation']['excess'])->toBeGreaterThan(0.0);
        });
});

it('br53: reports nothing when the cards fit inside the order', function (): void {
    DB::table('job_cards')->where('sales_order_line_id', $this->line->id)
        ->update(['status' => JobCard::CANCELLED]);

    expect(app(JobCardPlanningGuard::class)->overAllocation($this->line->refresh()))->toBeNull();
});
