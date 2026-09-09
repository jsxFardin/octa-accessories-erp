<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * AD-4 — `factory_unit_id` on every operational table, so a second production unit works from
 * day one without multi-tenancy.
 *
 * That was a schema promise the application did not keep. `User::factoryUnitId()` had exactly
 * two callers: itself, and `HandleInertiaRequests`, which shipped the value to the frontend
 * where nothing read it. The shop-floor terminal scoped properly; every desk list did not, so
 * job cards, sales orders, purchase orders, requisitions and expenses were read across every
 * unit by everyone.
 *
 * Invisible on this seed, which is the point: one factory unit, every employee in it. The bug
 * has no symptom until the day Maheen opens a second unit, and then it has a serious one.
 */
beforeEach(function (): void {
    $this->homeUnit = (int) DB::table('factory_units')->value('id');

    $this->otherUnit = (int) DB::table('factory_units')->insertGetId([
        'code' => 'ML-2',
        'name' => 'Second unit',
        'is_active' => true,
    ]);

    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();

    // A job card that belongs to the other unit and nothing else about it changed.
    $this->foreignCard = JobCard::query()->withoutGlobalScopes()->firstOrFail();
    $this->foreignCard->forceFill(['factory_unit_id' => $this->otherUnit])->save();
});

it('units: hides another unit\'s job card from a list', function (): void {
    $this->actingAs($this->planner);

    expect($this->planner->factoryUnitId())->toBe($this->homeUnit)
        ->and(JobCard::query()->whereKey($this->foreignCard->id)->exists())->toBeFalse();
});

it('units: hides another unit\'s job card from its own URL', function (): void {
    // A list filter that a route-model binding walks straight around is not a boundary.
    $this->actingAs($this->planner)
        ->get("/job-cards/{$this->foreignCard->id}")
        ->assertNotFound();
});

it('units: still shows this unit\'s own work', function (): void {
    $mine = JobCard::query()->withoutGlobalScopes()
        ->where('factory_unit_id', $this->homeUnit)
        ->first();

    if ($mine === null) {
        $mine = JobCard::query()->withoutGlobalScopes()->firstOrFail();
        $mine->forceFill(['factory_unit_id' => $this->homeUnit])->save();
    }

    $this->actingAs($this->planner);

    expect(JobCard::query()->whereKey($mine->id)->exists())->toBeTrue();
});

it('units: scopes sales orders the same way', function (): void {
    $order = SalesOrder::query()->withoutGlobalScopes()->firstOrFail();
    $order->forceFill(['factory_unit_id' => $this->otherUnit])->save();

    $this->actingAs($this->planner);

    expect(SalesOrder::query()->whereKey($order->id)->exists())->toBeFalse();
});

it('units: leaves an auditor with no employee record unscoped', function (): void {
    // `factoryUnitId()` is documented to leave such a user unscoped rather than locked out —
    // an auditor or the implementer has no employee row and therefore no unit.
    $auditor = User::query()->where('email', 'auditor@octapussolution.com')->firstOrFail();

    DB::table('employees')->where('user_id', $auditor->id)->update(['user_id' => null]);
    $auditor->refresh();

    expect($auditor->factoryUnitId())->toBeNull();

    $this->actingAs($auditor);

    expect(JobCard::query()->whereKey($this->foreignCard->id)->exists())->toBeTrue();
});

it('units: shows a row that belongs to no unit to everyone', function (): void {
    // `expenses.factory_unit_id` is nullable — it is the one scoped table where that is true,
    // and the seed carries such a row. Hiding it would make it unreachable from any account,
    // which is a worse failure than showing it.
    $expense = Expense::query()->withoutGlobalScopes()->first();

    if ($expense === null) {
        $expense = Expense::query()->create([
            'number' => 'EXP-SCOPE-'.uniqid('', false),
            'expense_category_id' => DB::table('expense_categories')->value('id'),
            'payee' => 'Scoping fixture',
            'expense_date' => now()->toDateString(),
            'amount' => 100,
            'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
            'status' => 'draft',
        ]);
    }

    $expense->forceFill(['factory_unit_id' => null])->save();

    $this->actingAs($this->planner);

    expect(Expense::query()->whereKey($expense->id)->exists())->toBeTrue();
});

it('units: does not scope when nothing is authenticated', function (): void {
    // Console commands, queued jobs and seeders run without a user. A scope that filtered them
    // to nothing would break every one of them.
    auth()->logout();

    expect(JobCard::query()->whereKey($this->foreignCard->id)->exists())->toBeTrue();
});

/**
 * The global scope closed the lists and the detail pages, because those go through Eloquent.
 * A large part of what a user actually reads does not: the dashboard tiles, the work queue and
 * the reports are `DB::table()` for the joins and aggregates they need, and no model scope can
 * reach any of them. Without the same filter there, a job card was hidden from the list it
 * belongs to and counted in the tile above it.
 */
it('units: does not count another unit\'s work in the dashboard tiles', function (): void {
    $order = SalesOrder::query()->withoutGlobalScopes()->firstOrFail();
    $order->forceFill([
        'factory_unit_id' => $this->otherUnit,
        'status' => 'confirmed',
    ])->save();

    $this->actingAs($this->planner)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where(
            'tiles.open_orders',
            fn ($count): bool => (int) $count === SalesOrder::query()
                ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
                ->count(),
        ));
});

it('units: does not count another unit\'s work in the work queue', function (): void {
    $card = JobCard::query()->withoutGlobalScopes()->firstOrFail();
    $card->forceFill([
        'factory_unit_id' => $this->otherUnit,
        'status' => JobCard::MATERIAL_PENDING,
    ])->save();

    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    // The row is offered to anyone holding `job_card.view_any`, and then dropped if its count
    // is zero — "an entry with nothing in it is not reassurance, it is a row to scan past".
    // So its absence is the assertion: the only job card waiting on material is in a unit this
    // supervisor cannot reach, and it must not appear as a count they cannot clear.
    expect($supervisor->hasPermission('job_card.view_any'))->toBeTrue();

    $queue = collect(app(App\Support\Platform\WorkQueue::class)->for($supervisor->fresh()));

    expect($queue->firstWhere('key', 'material_pending'))->toBeNull();

    // And the control: moved back into this supervisor's own unit, it shows up.
    $card->forceFill(['factory_unit_id' => $this->homeUnit])->save();

    $again = collect(app(App\Support\Platform\WorkQueue::class)->for($supervisor->fresh()));

    expect($again->firstWhere('key', 'material_pending'))->not->toBeNull();
});
