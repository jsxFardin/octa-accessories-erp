<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\Services\PreProductionCosting;
use App\Modules\Sales\Models\SalesOrder;
use Inertia\Testing\AssertableInertia;

/**
 * Spec §4 — costing is its own function. The costing clerk has a list of every sheet across
 * the three stages and the rates they draw on, and a role that fits the job: build, submit,
 * explain; never approve.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->costing = User::query()->where('email', 'costing@octapussolution.com')->firstOrFail();
});

it('lists every sheet with its stage, document and margin, and filters by stage', function (): void {
    $order = SalesOrder::query()->whereNotNull('number')->with('lines')->firstOrFail();
    app(PreProductionCosting::class)->snapshot($order);

    $this->actingAs($this->costing)
        ->get('/costing/sheets')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            $props = $page->toArray()['props'];
            $rows = collect($props['sheets']['data']);
            $pre = $rows->firstWhere('stage', 'pre_production');

            expect($pre)->not->toBeNull()
                ->and($pre['document']['href'])->toBe("/sales-orders/{$order->id}")
                ->and($pre['margin'])->not->toBeNull()
                ->and($props['counts']['pre_production'])->toBeGreaterThanOrEqual(1);
        });

    $this->actingAs($this->costing)
        ->get('/costing/sheets?stage=post_production')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(collect($page->toArray()['props']['sheets']['data'])->pluck('stage')->unique()->all())->toBeIn([[], ['post_production']]));
});

it('shows the rate master: factory rates from settings, machine rates, material rates with the last price paid', function (): void {
    $this->actingAs($this->costing)
        ->get('/costing/rates')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect(collect($props['factory'])->pluck('key')->all())->toContain('labour_rate_per_hour', 'margin_floor_pct')
                ->and($props['machines'])->not->toBeEmpty()
                ->and($props['materials']['data'])->not->toBeEmpty();

            $yarn = collect($props['materials']['data'])->first(fn ($row) => $row['last_rate'] !== null);
            expect($yarn)->not->toBeNull('the demo goods receipt gives a last price paid');
        });
});

it('gives the costing role its screens and keeps approval away from it', function (): void {
    expect($this->costing->hasPermission('cost_sheet.view_any'))->toBeTrue()
        ->and($this->costing->hasPermission('cost_sheet.submit'))->toBeTrue()
        ->and($this->costing->hasPermission('cost_sheet.explain'))->toBeTrue()
        ->and($this->costing->hasPermission('rate_master.view_any'))->toBeTrue()
        ->and($this->costing->hasPermission('cost_sheet.approve'))->toBeFalse()
        ->and($this->costing->hasPermission('cost_sheet.override_margin'))->toBeFalse();

    // A wildcard never confers approval: the merchandiser holds cost_sheet.* and still cannot.
    $merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    expect($merchandiser->hasPermission('cost_sheet.approve'))->toBeFalse()
        ->and($merchandiser->hasPermission('cost_sheet.submit'))->toBeTrue();

    // Reads the settings the sheets draw on; changes them only through someone who may.
    $this->actingAs($this->costing)->get('/admin/settings')->assertOk();
    $this->actingAs($this->costing)->put('/admin/settings', ['settings' => [['key' => 'labour_rate_per_hour', 'value' => 90]]])->assertForbidden();
});

it('exports the sheet list', function (): void {
    $this->actingAs($this->admin)->get('/exports/cost-sheets')->assertOk();
});
