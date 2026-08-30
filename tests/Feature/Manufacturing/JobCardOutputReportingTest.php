<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * F-03 / P0-2 — one definition of "what this job made", read the same way everywhere.
 *
 * A job card's operations do not share a unit: weaving books metres, packing books pieces.
 * `job_cards.good_qty` is a running total across all of them, so on a card that wove 407 m
 * and packed 30,000 labels it read 60,457 — against a plan of 30,000. The job card screen,
 * the FG receipt ceiling, the order-line rollup and the production report all already read
 * the final operation; the list, the export, the order screen and the AQL lot size did not.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
});

/**
 * Book output straight onto the operations, the way the floor's shifts accumulate it, and
 * let the job card's own running totals accumulate the same way they do in production.
 *
 * @param  list<float>  $goodPerOperation
 */
function bookAcrossOperations(object $test, array $goodPerOperation): void
{
    $operations = $test->jobCard->operations()->get();

    foreach ($operations->values() as $index => $operation) {
        $good = $goodPerOperation[$index] ?? 0.0;

        DB::table('job_card_operations')->where('id', $operation->id)->update([
            'input_qty' => $good,
            'good_qty' => $good,
            'status' => JobCardOperation::COMPLETED,
        ]);
    }

    // The card's running totals accumulate the same way the floor API accumulates them:
    // every operation added together, across units. That is what they are for, and it is why
    // they are not the job's output.
    DB::table('job_cards')->where('id', $test->jobCard->id)->update([
        'good_qty_running' => array_sum($goodPerOperation),
        'produced_qty_running' => array_sum($goodPerOperation),
    ]);
}

it('reports the final operation output on the list, not the sum of every operation', function (): void {
    // The shape of JC-26-000017: metres through the web operations, pieces at the end.
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    // The running total really does read 60,457 — the point is that nothing shows it.
    expect((float) $this->jobCard->fresh()->good_qty_running)->toBeQty(60457.0);

    $this->actingAs($this->planner)
        ->get('/job-cards')
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['jobCards']['data'])
                ->firstWhere('id', $this->jobCard->id);

            expect((float) $row['good_qty'])->toBeQty(30000.0)
                ->and((float) $row['produced_qty'])->toBeQty(30000.0);
        });
});

it('agrees between the list and the job card screen', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $fromList = null;

    $this->actingAs($this->planner)->get('/job-cards')->assertInertia(function (AssertableInertia $page) use (&$fromList): void {
        $fromList = collect($page->toArray()['props']['jobCards']['data'])->firstWhere('id', $this->jobCard->id);
    });

    $this->actingAs($this->planner)
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(function (AssertableInertia $page) use ($fromList): void {
            $card = $page->toArray()['props']['jobCard'];

            expect((float) $card['good_qty'])->toBeQty((float) $fromList['good_qty'])
                ->and((float) $card['produced_qty'])->toBeQty((float) $fromList['produced_qty'])
                ->and((float) $card['good_qty'])->toBeQty(30000.0);
        });
});

it('agrees with the FG receipt ceiling and the production report', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $position = app(FgReceiptService::class)->positionFor($this->jobCard->fresh());

    expect($position['produced'])->toBeQty(30000.0);

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/reports/production')
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['rows']['data'])
                ->firstWhere('id', $this->jobCard->id);

            expect((float) $row['produced_qty'])->toBeQty(30000.0);
        });
});

it('never adds an operation measured in metres to one measured in pieces', function (): void {
    $operations = $this->jobCard->operations()->with('routingOperation')->get();

    $units = $operations->map(fn (JobCardOperation $op): string => $op->unit())->unique();

    // The fixture is only interesting if the routing really does mix units.
    expect($units->count())->toBeGreaterThan(1);

    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $webOperation = $operations->firstWhere(fn (JobCardOperation $op): bool => $op->unit() === 'm');

    expect($final->unit())->toBe('pcs')
        ->and($final->sharesUnitWith($webOperation))->toBeFalse();

    // And the reported figure is the one in pieces, alone.
    expect($this->jobCard->fresh()->finalOperationOutput()['good'])->toBeQty(30000.0);
});

it('states the unit of every operation on the job card screen', function (): void {
    $this->actingAs($this->planner)
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $operations = collect($page->toArray()['props']['operations']);

            expect($operations)->not->toBeEmpty()
                ->and($operations->every(fn (array $op): bool => in_array($op['unit'], ['m', 'pcs'], true)))->toBeTrue();
        });
});

it('offers the AQL inspector the final operation output as the lot size', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);
    DB::table('job_cards')->where('id', $this->jobCard->id)->update(['status' => JobCard::IN_PRODUCTION]);

    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail())
        ->get("/qc-inspections/create?job_card={$this->jobCard->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselect.lot_size', 30000));
});

it('shows the same figure on the sales order the job card belongs to', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $orderId = DB::table('sales_order_lines')
        ->where('id', $this->jobCard->sales_order_line_id)
        ->value('sales_order_id');

    $this->actingAs(User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail())
        ->get("/sales-orders/{$orderId}")
        ->assertInertia(function (AssertableInertia $page): void {
            $card = collect($page->toArray()['props']['jobCards'])
                ->firstWhere('id', $this->jobCard->id);

            expect((float) $card['good_qty'])->toBeQty(30000.0);
        });
});

it('exports the final operation output rather than the running total', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $csv = $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/exports/job-cards?format=csv')
        ->streamedContent();

    $row = collect(explode("\n", $csv))
        ->first(fn (string $line): bool => str_contains($line, (string) $this->jobCard->number));

    expect($row)->toContain('30000')->not->toContain('60457');
});

it('reports zero for a job card that has booked nothing', function (): void {
    $this->actingAs($this->planner)
        ->get('/job-cards')
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['jobCards']['data'])
                ->firstWhere('id', $this->jobCard->id);

            expect((float) $row['good_qty'])->toBeQty(0.0);
        });
});

it('spells the running totals so they cannot be read as the job output', function (): void {
    // The rename is the point: `SELECT good_qty FROM job_cards` used to read exactly like
    // "the good quantity" and was a cross-unit sum. The bare names must be gone from the
    // table, and the explicit ones present.
    $columns = collect(DB::select(
        'SELECT column_name AS name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?',
        ['job_cards'],
    ))->pluck('name')->map(fn (string $n): string => strtolower($n));

    expect($columns)->toContain('planned_qty')
        ->toContain('good_qty_running')
        ->toContain('waste_qty_running')
        ->toContain('produced_qty_running')
        ->not->toContain('good_qty')
        ->not->toContain('waste_qty')
        ->not->toContain('produced_qty');

    // And the canonical source is still a first-class object of its own.
    expect(collect(DB::select(
        'SELECT column_name AS name FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ?',
        ['v_job_card_output'],
    ))->pluck('name')->map(fn (string $n): string => strtolower($n)))->toContain('good_qty');
});

it('keeps the running total and the job output apart on the same card', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $card = $this->jobCard->fresh();

    $running = (float) $card->good_qty_running;
    $output = $card->finalOperationOutput()['good'];
    $canonical = (float) DB::table('v_job_card_output')->where('job_card_id', $card->id)->value('good_qty');

    expect($running)->toBeQty(60457.0)
        ->and($output)->toBeQty(30000.0)
        ->and($canonical)->toBeQty(30000.0)
        // The two are genuinely different numbers on this card, which is what makes the
        // distinction worth enforcing rather than assuming.
        ->and($running)->not->toBe($output);
});

it('never lets the running total reach a screen, an export or a report', function (): void {
    bookAcrossOperations($this, [0, 407, 0, 30050, 30000]);

    $surfaces = [];

    $this->actingAs($this->planner)->get('/job-cards')->assertInertia(function ($page) use (&$surfaces): void {
        $surfaces['list'] = collect($page->toArray()['props']['jobCards']['data'])->firstWhere('id', $this->jobCard->id);
    });

    $this->actingAs($this->planner)->get("/job-cards/{$this->jobCard->id}")->assertInertia(function ($page) use (&$surfaces): void {
        $surfaces['detail'] = $page->toArray()['props']['jobCard'];
    });

    foreach (['list', 'detail'] as $surface) {
        expect((float) $surfaces[$surface]['good_qty'])->toBeQty(30000.0)
            ->and($surfaces[$surface])->not->toHaveKey('good_qty_running')
            ->and($surfaces[$surface])->not->toHaveKey('produced_qty_running');
    }

    // The report and the export read the view, not the column.
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/reports/production')
        ->assertInertia(function ($page): void {
            $row = collect($page->toArray()['props']['rows']['data'])->firstWhere('id', $this->jobCard->id);
            expect((float) $row['produced_qty'])->toBeQty(30000.0);
        });

    $csv = $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/exports/job-cards?format=csv')->streamedContent();

    expect($csv)->not->toContain('60457')->not->toContain('60,457');
});

it('answers "has anything been booked" from the running total, which is all it is for', function (): void {
    expect((float) $this->jobCard->fresh()->produced_qty_running)->toBeQty(0.0);

    bookAcrossOperations($this, [0, 407, 0, 0, 0]);

    // Waste-only or metres-only work still counts as production for the cancellation guard,
    // which is the one question a cross-unit sum answers honestly.
    expect((float) $this->jobCard->fresh()->produced_qty_running)->toBeGreaterThan(0.0);

    // J1's cancellation guard is the one reader of the column, and it asks exactly that.
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post("/job-cards/{$this->jobCard->id}/transition", ['to' => 'cancelled'])
        ->assertSessionHas('error');

    expect(session('error'))->toContain('logged production')
        ->and($this->jobCard->fresh()->status)->not->toBe('cancelled');

    // With a documented reason it goes through: the running total gates the reason, not the act.
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post("/job-cards/{$this->jobCard->id}/transition", [
            'to' => 'cancelled', 'reason' => 'Customer pulled the style mid-run.',
        ])->assertSessionHasNoErrors();

    expect($this->jobCard->fresh()->status)->toBe('cancelled');
});
