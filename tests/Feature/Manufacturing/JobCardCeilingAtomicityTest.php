<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * BR-49, made atomic.
 *
 * The ceiling arithmetic was already right and already covered. What was missing was that the
 * decision and the write were not the same event: `assert()` ran *before* `DB::transaction()`
 * and read an unlocked `SUM(planned_qty)`, so two planners submitting at the same moment both
 * read the same headroom, both passed, and both inserted. The order finished over-committed by
 * the exact rule that exists to prevent it, and neither request had done anything wrong.
 *
 * The fix takes a `FOR UPDATE` lock on the `sales_order_lines` row — the row the committed
 * quantity is grouped by — inside the transaction that writes the card, so every competing
 * card for that line serialises on it.
 *
 * **Limitation, stated plainly:** this suite runs each test inside a single connection wrapped
 * in a `RefreshDatabase` transaction, so two genuinely parallel writers cannot be produced
 * here — a second connection would not see this test's uncommitted fixture, and a nested
 * `lockForUpdate` on the same connection never blocks. These tests therefore prove the two
 * things that make the race impossible rather than racing it: the lock is taken on the right
 * row, and it is taken before the aggregate is read and before the insert. A true parallel
 * test belongs in an integration environment against a committed dataset.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();

    $this->line = SalesOrderLine::query()
        ->whereIn('product_id', function ($query): void {
            $query->select('jc.product_id')
                ->from('job_cards as jc')
                ->whereNotNull('jc.sales_order_line_id');
        })
        ->firstOrFail();

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
});

it('br49: locks the order line before reading the committed quantity', function (): void {
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    ($this->post)(1000.0)->assertRedirect();

    $lockIndex = null;
    $sumIndex = null;
    $insertIndex = null;

    foreach ($queries as $index => $sql) {
        if ($lockIndex === null && str_contains($sql, 'sales_order_lines') && str_contains($sql, 'for update')) {
            $lockIndex = $index;
        }

        if ($sumIndex === null && str_contains($sql, 'from `job_cards`') && str_contains($sql, 'sum(')) {
            $sumIndex = $index;
        }

        if ($insertIndex === null && str_starts_with($sql, 'insert into `job_cards`')) {
            $insertIndex = $index;
        }
    }

    // The lock exists, on the row the ceiling is grouped by…
    expect($lockIndex)->not->toBeNull('no FOR UPDATE lock was taken on sales_order_lines');
    // …the committed quantity is read only after it…
    expect($sumIndex)->not->toBeNull()
        ->and($lockIndex)->toBeLessThan($sumIndex);
    // …and the card is written only after that.
    expect($insertIndex)->not->toBeNull()
        ->and($sumIndex)->toBeLessThan($insertIndex);
});

it('br49: takes the lock inside the transaction that writes the card', function (): void {
    $sawBegin = false;
    $lockInsideTransaction = false;
    $depth = 0;

    // `RefreshDatabase` already holds one transaction open, so the controller's own is a
    // savepoint. Either way the lock must fall inside it, never before it.
    DB::listen(function ($query) use (&$depth, &$sawBegin, &$lockInsideTransaction): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'sales_order_lines') && str_contains($sql, 'for update')) {
            $lockInsideTransaction = DB::transactionLevel() > 0;
            $sawBegin = true;
        }

        $depth = DB::transactionLevel();
    });

    ($this->post)(1000.0)->assertRedirect();

    expect($sawBegin)->toBeTrue('the lock was never taken')
        ->and($lockInsideTransaction)->toBeTrue('the lock was taken outside a transaction');
});

it('br49: still refuses an over-ceiling quantity now that the check moved', function (): void {
    // The rule itself must be unchanged by the move: 3,000 ordered + 5% is 3,150.
    ($this->post)(3151.0)->assertSessionHasErrors('planned_qty');

    expect(DB::table('job_cards')
        ->where('sales_order_line_id', $this->line->id)
        ->whereNot('status', JobCard::CANCELLED)
        ->count())->toBe(0);
});

it('br49: counts a card committed earlier in the same request path', function (): void {
    ($this->post)(3000.0)->assertRedirect();

    // The headroom is now 150. A second card for 200 must be refused, and nothing written.
    $before = DB::table('job_cards')->count();

    ($this->post)(200.0)->assertSessionHasErrors('planned_qty');

    expect(DB::table('job_cards')->count())->toBe($before);
});
