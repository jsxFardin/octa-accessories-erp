<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Costing\Services\CostStages;
use App\Modules\Costing\Services\PostProductionCosting;
use App\Modules\Costing\Services\PreProductionCosting;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4–5 — three costings: marketing before the order, pre-production locked at confirm,
 * post-production from actuals at close, and the variance between them.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();
    $this->sales = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
});

it('costs a non-label product from its bill and routing: material, conversion, overhead, margin', function (): void {
    $this->actingAs($this->admin);
    $families = DB::table('production_families')->pluck('id', 'code');
    $uoms = DB::table('uoms')->pluck('id', 'code');

    $yarn = Item::query()->where('make_or_buy', 'buy')->where('std_rate', '>', 0)->firstOrFail();

    $cord = app(ItemMasterService::class)->create([
        'name' => 'Drawcord 5 mm',
        'item_type' => 'finished_good',
        'production_family_id' => $families['06'],
        'garment_type' => 'knit',
        'material_base' => 'textile',
        'base_uom_id' => $uoms['pcs'],
        'order_uom_id' => $uoms['pcs'],
        'status' => Item::ACTIVE,
    ], ['product_type' => 'other'], $this->admin->id);

    $this->post("/products/{$cord->product->id}/boms", [
        'base_qty' => 1000,
        'lines' => [['item_id' => $yarn->id, 'uom_id' => $yarn->base_uom_id, 'qty_per_base' => 12, 'wastage_pct' => 2]],
    ])->assertSessionHasNoErrors();
    DB::table('boms')->where('product_id', $cord->product->id)->update(['status' => 'active']);

    $sheet = app(CostSheetService::class)->estimate($cord->product->fresh(), 10000, ['marginPct' => 12]);

    $types = collect($sheet->lines)->pluck('costType')->unique()->values()->all();

    // 12 per 1,000 × 10,000 × 1.02 wastage, at the item's rate.
    expect($sheet->materialCost)->toBe(round(120 * 1.02 * (float) ($yarn->avg_rate > 0 ? $yarn->avg_rate : $yarn->std_rate), 4))
        ->and($types)->toContain('machine', 'labour', 'overhead')
        ->and($sheet->totalCost)->toBeGreaterThan($sheet->materialCost)
        ->and($sheet->unitCost)->toBe(round($sheet->totalCost / 10000, 6))
        ->and($sheet->ratePerM)->toBe(round($sheet->unitCost / 0.88, 4));
});

it('locks a pre-production benchmark per line when an order is confirmed, with the expected margin', function (): void {
    $this->actingAs($this->admin);
    $order = SalesOrder::query()->where('status', 'draft')->with('lines')->first()
        ?? SalesOrder::query()->whereNotNull('number')->with('lines')->firstOrFail();

    // A confirmed order already carries its benchmark from the seed; a draft gets one now.
    if ($order->status === 'draft') {
        app(SalesOrderStateMachine::class)->transition($order, 'confirmed');
    }

    $line = $order->lines->first();
    $pre = CostSheet::query()->where('sales_order_line_id', $line->id)->where('stage', CostSheet::PRE_PRODUCTION)->first();

    expect($pre)->not->toBeNull()
        ->and($pre->is_locked)->toBeTrue()
        ->and((float) $pre->basis_qty)->toBe((float) $line->ordered_qty)
        ->and((float) $pre->revenue_per_unit)->toBe(round((float) $line->line_total * (float) $order->exchange_rate / (float) $line->ordered_qty, 6))
        ->and((float) $pre->unit_cost)->toBeGreaterThan(0);

    $rows = app(CostStages::class)->forOrder($order->fresh(['lines']));
    expect($rows[0]['pre']['margin_pct'])->not->toBeNull();
});

it('asks for margin approval when the expected margin is below the floor', function (): void {
    // The seed confirms its order; put one back to draft so the confirm guard runs again.
    $order = SalesOrder::query()->whereNotNull('number')->with('lines')->firstOrFail();
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'draft', 'confirmed_at' => null]);
    $order = $order->fresh(['lines']);

    // A floor no order could meet: the guard must fire for whoever cannot approve it.
    app(Settings::class)->set('margin_floor_pct', 95);
    app(Settings::class)->flush();

    $short = app(PreProductionCosting::class)->belowFloor($order);
    expect($short)->not->toBeEmpty()
        ->and($short[0])->toContain('below the 95% floor');

    $this->actingAs($this->sales);
    expect(fn () => app(SalesOrderStateMachine::class)->transition($order, 'confirmed'))
        ->toThrow(TransitionDenied::class, 'margin approval');

    expect($order->fresh()->status)->toBe('draft');

    // The Managing Director may accept the margin.
    $this->actingAs($this->md);
    app(SalesOrderStateMachine::class)->transition($order->fresh(), 'confirmed');

    expect($order->fresh()->status)->toBe('confirmed');
});

it('strikes the actuals against the benchmark, element by element, with a reason the clerk gives', function (): void {
    $this->actingAs($this->admin);
    $job = JobCard::query()->whereNotNull('sales_order_line_id')->with('operations')->firstOrFail();

    // Let the job have run: minutes on each operation, output at the last one, and a benchmark
    // on its order line.
    $order = $job->salesOrderLine->salesOrder;
    app(PreProductionCosting::class)->snapshot($order);

    $machine = DB::table('machines')->first();
    DB::table('machines')->where('id', $machine->id)->update(['hourly_rate' => 600, 'kw_rating' => 10]);

    foreach ($job->operations as $operation) {
        DB::table('job_card_operations')->where('id', $operation->id)->update([
            'machine_id' => $machine->id,
            'actual_minutes' => 120,
            'input_qty' => 50000,
            'good_qty' => 50000,
            'status' => 'completed',
        ]);
    }

    $sheet = app(PostProductionCosting::class)->snapshot($job->fresh());

    expect($sheet->stage)->toBe(CostSheet::POST_PRODUCTION)
        ->and($sheet->is_locked)->toBeTrue()
        ->and((float) $sheet->basis_qty)->toBe(50000.0)
        ->and((float) $sheet->machine_cost)->toBe(round(5 * 2 * 600, 4))   // five operations, two hours each
        ->and((float) $sheet->labour_cost)->toBeGreaterThan(0)
        ->and((float) $sheet->revenue_per_unit)->toBeGreaterThan(0);

    $stages = app(CostStages::class)->forJobCard($job->fresh());
    $total = collect($stages['variance'])->firstWhere('key', 'total_cost');

    expect($stages['pre'])->not->toBeNull()
        ->and($stages['post']['unit_cost'])->toBe((float) $sheet->unit_cost)
        ->and($total['per_unit'])->toBe(round($total['post'] - $total['pre'], 6));

    // The tab answers, and the reason sticks to the locked sheet.
    $this->get("/job-cards/{$job->id}?tab=costing")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('costing.post_sheet_id', $sheet->id));

    $this->post("/cost-sheets/{$sheet->id}/reason", ['variance_reason' => 'Yarn price up; 1.5 kg extra at setup.'])
        ->assertSessionHasNoErrors();

    expect($sheet->fresh()->variance_reason)->toBe('Yarn price up; 1.5 kg extra at setup.');

    // The actuals feed the standard: no waste logged, so the item's standard wastage reads 0.
    $this->post("/cost-sheets/{$sheet->id}/adopt-wastage")->assertSessionHasNoErrors();

    expect((float) $job->product->item->fresh()->standard_wastage_pct)->toBe(0.0);
});
