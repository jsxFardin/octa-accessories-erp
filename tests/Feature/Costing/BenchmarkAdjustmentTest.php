<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostStages;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\SalesOrderStateMachine;

/**
 * Spec §4 — pre-production costing is the costing clerk's call: before the order is
 * confirmed a material's rate may be set by hand with a reason, and the benchmark struck at
 * confirm reads it. After confirm the benchmark is locked and the rates cannot move.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    // A draft copy of the first order, so the benchmark is still to be struck.
    $source = SalesOrder::query()->whereNotNull('number')->with('lines')->firstOrFail();
    $this->order = SalesOrder::query()->create([
        ...collect($source->getAttributes())->except(['id', 'number', 'status', 'created_at', 'updated_at', 'confirmed_at', 'confirmed_by'])->all(),
        'status' => 'draft',
    ]);
    $this->line = SalesOrderLine::query()->create([
        ...collect($source->lines->first()->getAttributes())->except(['id', 'sales_order_id', 'created_at', 'updated_at'])->all(),
        'sales_order_id' => $this->order->id,
        'produced_qty' => 0, 'delivered_qty' => 0, 'invoiced_qty' => 0,
    ]);
});

it('lets the clerk re-rate a material before confirm, and the benchmark reads that rate', function (): void {
    $rows = app(CostStages::class)->forOrder($this->order->fresh()->load('lines'));
    $row = collect($rows)->firstWhere('line_id', $this->line->id);

    expect($row['expected'])->not->toBeNull()
        ->and($row['materials'])->not->toBeEmpty()
        ->and($row['overrides'])->toBeNull();

    $material = $row['materials'][0];
    $newRate = round($material['rate'] * 1.1, 4);

    $this->from("/sales-orders/{$this->order->id}")
        ->post("/sales-orders/{$this->order->id}/lines/{$this->line->id}/cost-overrides", [
            'rates' => [$material['key'] => $newRate],
            'reason' => 'Supplier quoted 10% up for November.',
        ])
        ->assertRedirect("/sales-orders/{$this->order->id}")
        ->assertSessionHasNoErrors();

    $stored = $this->line->fresh()->cost_overrides;
    expect($stored['rates'][$material['key']])->toBe($newRate)
        ->and($stored['reason'])->toBe('Supplier quoted 10% up for November.');

    // The preview moves with the rate, and names the material as adjusted.
    $after = collect(app(CostStages::class)->forOrder($this->order->fresh()->load('lines')))->firstWhere('line_id', $this->line->id);
    expect($after['expected']['unit_cost'])->toBeGreaterThan($row['expected']['unit_cost'])
        ->and(collect($after['materials'])->firstWhere('key', $material['key'])['override'])->toBe($newRate);

    // Confirm: the benchmark carries the hand-set rate, and the sheet page says so.
    app(SalesOrderStateMachine::class)->transition($this->order->fresh(), 'confirmed');
    $pre = CostSheet::query()->with('lines')->where('sales_order_line_id', $this->line->id)->where('stage', CostSheet::PRE_PRODUCTION)->firstOrFail();
    $line = $pre->lines->first(fn ($l) => str_starts_with((string) $l->cost_type, 'material')
        && ($material['key'] === 'item:'.$l->item_id || $material['key'] === 'type:'.$l->cost_type));

    expect($line)->not->toBeNull()
        ->and(round((float) $line->rate, 4))->toBe($newRate);

    $this->get("/costing/sheets/{$pre->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('sheet.adjustment.reason', 'Supplier quoted 10% up for November.'));

    // Locked now: the rates cannot be moved under a struck benchmark.
    $this->from("/sales-orders/{$this->order->id}")
        ->post("/sales-orders/{$this->order->id}/lines/{$this->line->id}/cost-overrides", ['rates' => [$material['key'] => 1], 'reason' => 'Too late.'])
        ->assertRedirect("/sales-orders/{$this->order->id}")
        ->assertSessionHas('error');

    expect($this->line->fresh()->cost_overrides['rates'][$material['key']])->toBe($newRate);
});

it('needs a reason, and clearing every rate clears the adjustment', function (): void {
    $this->post("/sales-orders/{$this->order->id}/lines/{$this->line->id}/cost-overrides", ['rates' => ['item:1' => 5]])
        ->assertSessionHasErrors(['reason']);

    $this->post("/sales-orders/{$this->order->id}/lines/{$this->line->id}/cost-overrides", ['rates' => ['item:1' => ''], 'reason' => 'Back to the master.'])
        ->assertSessionHasNoErrors();

    expect($this->line->fresh()->cost_overrides)->toBeNull();
});
