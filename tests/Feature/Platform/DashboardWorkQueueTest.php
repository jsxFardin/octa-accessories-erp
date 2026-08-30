<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * F-12 / F-08 — the dashboard's counts, and whether a queue helps clear itself.
 *
 * **F-12** was reported as "Completed job cards are wrongly counted as Open". They are not.
 * `completed → closed` is a real transition behind the `job_card.close` permission, so a
 * completed card is work waiting for someone to close it and belongs in the count. The genuine
 * defect was next to it: the status breakdown counted *every* job card ever raised while
 * sitting under a tile counting only open ones. The two agreed at the time of the audit purely
 * because nothing had been closed or cancelled yet, and the first closed card would have made
 * the breakdown outnumber the tile above it with nothing on screen to explain why.
 *
 * **F-08** was that "Confirmed orders with no job card" identified the work and then offered
 * Open and Edit — everything except the thing the queue is named after.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();
});

it('counts a completed job card as open, because closing it is still someone job', function (): void {
    $card = JobCard::query()->firstOrFail();
    $card->forceFill(['status' => JobCard::COMPLETED])->save();

    expect(JobCard::query()->open()->pluck('id'))->toContain($card->id);
});

it('excludes closed and cancelled cards from the open count', function (): void {
    $card = JobCard::query()->firstOrFail();

    foreach ([JobCard::CLOSED, JobCard::CANCELLED] as $terminal) {
        $card->forceFill(['status' => $terminal])->save();

        expect(JobCard::query()->open()->pluck('id'))->not->toContain($card->id);
    }
});

it('keeps the status breakdown equal to the tile it sits under', function (): void {
    // The regression: close one card and the two figures used to diverge silently.
    $card = JobCard::query()->firstOrFail();
    $card->forceFill(['status' => JobCard::CLOSED])->save();

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            $breakdown = array_sum(array_column($props['jobCardsByStatus'], 'count'));

            expect($breakdown)->toBe($props['tiles']['open_job_cards']);
        });
});

it('never lists a closed or cancelled status in the open breakdown', function (): void {
    JobCard::query()->firstOrFail()->forceFill(['status' => JobCard::CLOSED])->save();

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $statuses = array_column($page->toArray()['props']['jobCardsByStatus'], 'status');

            expect($statuses)->not->toContain('closed')
                ->and($statuses)->not->toContain('cancelled');
        });
});

it('agrees with the rows the open tile links to', function (): void {
    // The tile promises `/job-cards?open=1`; the count and that list must be the same set.
    $expected = JobCard::query()->open()->count();

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tiles.open_job_cards', $expected));

    $this->actingAs($this->admin)
        ->get('/job-cards?open=1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('jobCards.total', $expected));
});

// --- F-08 -------------------------------------------------------------------------------

it('flags the orders the job-card queue is about so the row can offer the action', function (): void {
    // Built rather than assumed: in the seeded walkthrough every confirmed line already
    // carries a card, so the condition the queue exists for has to be created. Cancelling the
    // cards on one order is exactly the real-world case — the work was dropped and nobody
    // raised it again.
    $order = DB::table('sales_orders')->whereIn('status', ['confirmed', 'in_production'])->firstOrFail();

    $lineIds = DB::table('sales_order_lines')->where('sales_order_id', $order->id)->pluck('id');

    DB::table('sales_order_lines')->whereIn('id', $lineIds)->update(['produced_qty' => 0]);
    DB::table('job_cards')->whereIn('sales_order_line_id', $lineIds)->update(['status' => 'cancelled']);

    $this->actingAs($this->planner)
        ->get('/sales-orders?awaiting=job_card')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            $rows = $page->toArray()['props']['orders']['data'];

            expect(collect($rows)->pluck('id'))->toContain((int) $order->id);

            // Every row on this filtered list is by definition awaiting a card; if the flag
            // and the filter ever disagree, the menu offers the wrong thing.
            foreach ($rows as $row) {
                expect($row['awaits_job_card'])->toBeTrue();
            }
        });
});

it('does not flag an order whose lines are all covered by live cards', function (): void {
    $covered = DB::table('sales_orders as so')
        ->whereIn('so.status', ['confirmed', 'in_production'])
        ->whereNotExists(fn ($line) => $line
            ->from('sales_order_lines as sol')
            ->whereColumn('sol.sales_order_id', 'so.id')
            ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
            ->whereNotExists(fn ($card) => $card
                ->from('job_cards as jc')
                ->whereColumn('jc.sales_order_line_id', 'sol.id')
                ->where('jc.status', '!=', 'cancelled')))
        ->value('so.id');

    if ($covered === null) {
        $this->markTestSkipped('Every confirmed order in the walkthrough still awaits a card.');
    }

    $this->actingAs($this->planner)
        ->get('/sales-orders')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($covered): void {
            $row = collect($page->toArray()['props']['orders']['data'])->firstWhere('id', (int) $covered);

            if ($row !== null) {
                expect($row['awaits_job_card'])->toBeFalse();
            }
        });
});

it('opens the job-card form with the order the queue action came from', function (): void {
    $orderId = DB::table('sales_orders as so')
        ->whereIn('so.status', ['confirmed', 'in_production'])
        ->whereExists(fn ($line) => $line
            ->from('sales_order_lines as sol')
            ->whereColumn('sol.sales_order_id', 'so.id')
            ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty'))
        ->value('so.id');

    expect($orderId)->not->toBeNull();

    // The href the row action uses. The context is resolved server-side, which is also where
    // the permission is enforced.
    $this->actingAs($this->planner)
        ->get("/job-cards/create?sales_order={$orderId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manufacturing/JobCards/Form')
            ->where('context.id', (int) $orderId),
        );
});

it('refuses the job-card form to a user without permission to raise one', function (): void {
    $blind = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();

    expect($blind->hasPermission('job_card.create'))->toBeFalse();

    $this->actingAs($blind)->get('/job-cards/create')->assertForbidden();
});
