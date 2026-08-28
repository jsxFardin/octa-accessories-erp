<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardPlanningGuard;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * BR-49 — a job card may not plan more than the order line can still absorb.
 *
 * The form drew the outstanding quantity as a hint and defaulted to it, and that was the whole
 * of the rule: `planned_qty` was validated as `numeric|gt:0`, so a line with 3,000 outstanding
 * took a card for 5,000 from the form and just as happily from a POST that never saw it.
 *
 * The ceiling deliberately is not the bare outstanding quantity. Over-production inside the
 * customer's agreed band is legitimate and already has a rule — BR-44 — so these tests pin the
 * allowance to that band rather than to the ordered figure, and pin the committed quantity to
 * every live card on the line rather than to the one on screen.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();

    // A line whose product can actually carry a card: Gate 1 wants approved artwork and a
    // routing, and a test that trips over those is not testing the quantity rule.
    $this->line = SalesOrderLine::query()
        ->whereIn('product_id', function ($query): void {
            $query->select('jc.product_id')
                ->from('job_cards as jc')
                ->whereNotNull('jc.sales_order_line_id');
        })
        ->firstOrFail();

    // A clean slate on this line, so the arithmetic under test is the arithmetic asserted.
    DB::table('job_cards')->where('sales_order_line_id', $this->line->id)->update(['status' => JobCard::CANCELLED]);

    $this->line->forceFill([
        'ordered_qty' => 3000,
        'produced_qty' => 0,
        'over_tolerance_pct' => 5,
        'under_tolerance_pct' => 5,
    ])->save();

    $this->unit = DB::table('factory_units')->where('is_active', true)->value('id');

    $this->post = fn (float $qty) => $this->actingAs($this->planner)->post('/job-cards', [
        'sales_order_line_id' => $this->line->id,
        'factory_unit_id' => $this->unit,
        'planned_qty' => $qty,
    ]);

    $this->guard = app(JobCardPlanningGuard::class);
});

it('accepts a planned quantity equal to the outstanding quantity', function (): void {
    expect($this->guard->refusalReason($this->line, 3000))->toBeNull();
});

it('accepts a planned quantity below the outstanding quantity', function (): void {
    expect($this->guard->refusalReason($this->line, 1500))->toBeNull();
});

it('accepts a planned quantity inside the over-delivery tolerance', function (): void {
    // BR-44 — 3,000 ordered at 5% over-tolerance may ship 3,150, so it may be planned.
    expect($this->guard->refusalReason($this->line, 3150))->toBeNull();
});

it('refuses a planned quantity above the tolerance ceiling', function (): void {
    $refusal = $this->guard->refusalReason($this->line, 5000);

    expect($refusal)->toContain('BR-49')
        ->and($refusal)->toContain('5,000')   // what they entered
        ->and($refusal)->toContain('3,150');  // what the line can take
});

it('refuses an over-ceiling quantity posted straight at the server', function (): void {
    // The manipulated POST: no form, no client-side max, no stale-state excuse.
    ($this->post)(5000)->assertSessionHasErrors('planned_qty');

    expect(DB::table('job_cards')
        ->where('sales_order_line_id', $this->line->id)
        ->where('status', '!=', JobCard::CANCELLED)
        ->count())->toBe(0);
});

it('creates the card when the posted quantity is within the ceiling', function (): void {
    ($this->post)(3000)->assertSessionHasNoErrors();

    expect(DB::table('job_cards')
        ->where('sales_order_line_id', $this->line->id)
        ->where('status', '!=', JobCard::CANCELLED)
        ->count())->toBe(1);
});

it('counts every live card on the line, not only the one on screen', function (): void {
    ($this->post)(3000)->assertSessionHasNoErrors();

    // The line is now fully committed. A second card for the same "outstanding" 3,000 is the
    // same over-commitment as one card for 6,000, and used to be invisible.
    ($this->post)(3000)->assertSessionHasErrors('planned_qty');

    expect($this->guard->capacity($this->line->refresh())['headroom'])->toBe(150.0);
});

it('frees the quantity again when a card is cancelled', function (): void {
    ($this->post)(3000)->assertSessionHasNoErrors();

    $card = JobCard::query()->where('sales_order_line_id', $this->line->id)
        ->where('status', '!=', JobCard::CANCELLED)->latest('id')->firstOrFail();

    $card->forceFill(['status' => JobCard::CANCELLED])->save();

    expect($this->guard->refusalReason($this->line->refresh(), 3000))->toBeNull();
});

it('does not double-count a card that overran its own plan', function (): void {
    DB::table('job_cards')->where('sales_order_line_id', $this->line->id)->update(['status' => JobCard::CANCELLED]);
    $this->line->forceFill(['produced_qty' => 3100])->save();

    // 3,100 produced against a 3,000 plan has consumed 3,100, not 6,200.
    $capacity = $this->guard->capacity($this->line->refresh());

    expect($capacity['committed'])->toBe(3100.0)
        ->and($capacity['headroom'])->toBe(50.0);
});

it('refuses any quantity once the line is fully covered', function (): void {
    $this->line->forceFill(['produced_qty' => 3150])->save();

    $refusal = $this->guard->refusalReason($this->line->refresh(), 1);

    expect($refusal)->toContain('already fully covered')
        ->and($refusal)->toContain('BR-49');
});

it('refuses a stale outstanding quantity that was true when the form opened', function (): void {
    // The planner opened the form when the line was untouched, then someone else raised a
    // card. The figure in their browser is still 3,000 and the server no longer agrees.
    $capacityWhenFormOpened = $this->guard->capacity($this->line)['headroom'];
    expect($capacityWhenFormOpened)->toBe(3150.0);

    ($this->post)(3000)->assertSessionHasNoErrors();

    // Their submit, with the quantity their stale form still holds.
    ($this->post)($capacityWhenFormOpened)->assertSessionHasErrors('planned_qty');
});

it('refuses a zero or negative planned quantity', function (): void {
    expect($this->guard->refusalReason($this->line, 0))->toContain('greater than zero')
        ->and($this->guard->refusalReason($this->line, -5))->toContain('greater than zero');
});

it('hands the form the same ceiling the server enforces', function (): void {
    $this->actingAs($this->planner)
        ->get("/job-cards/create?sales_order={$this->line->sales_order_id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $line = collect($page->toArray()['props']['orderLines'])
                ->firstWhere('id', $this->line->id);

            expect($line)->not->toBeNull()
                ->and((float) $line['capacity']['headroom'])->toBe(3150.0)
                ->and((float) $line['capacity']['ordered'])->toBe(3000.0);
        });
});

it('excludes the card being edited from its own committed quantity', function (): void {
    ($this->post)(3000)->assertSessionHasNoErrors();

    $card = JobCard::query()->where('sales_order_line_id', $this->line->id)
        ->where('status', '!=', JobCard::CANCELLED)->latest('id')->firstOrFail();

    // Re-planning the same card at the same quantity is not a second commitment of it.
    expect($this->guard->refusalReason($this->line->refresh(), 3000, (int) $card->id))->toBeNull();
});
