<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * UX audit M-23. The material plan worked out what was short and how much to order, and the
 * planner retyped it into a requisition. Opened from the plan, the form now arrives filled in.
 */
beforeEach(function (): void {
    $this->purchase = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();

    $this->runId = DB::table('mrp_runs')->insertGetId([
        'factory_unit_id' => (int) DB::table('factory_units')->value('id'),
        'run_at' => now(), 'horizon_from' => now()->toDateString(),
        'horizon_to' => now()->addDays(60)->toDateString(), 'status' => 'completed', 'shortage_count' => 1,
    ]);

    $items = DB::table('items')->limit(2)->get(['id']);

    $row = fn (int $itemId, bool $short, float $suggested): array => [
        'mrp_run_id' => $this->runId, 'item_id' => $itemId, 'gross_req_qty' => 100, 'on_hand_qty' => 10,
        'on_order_qty' => 0, 'reserved_qty' => 0, 'net_req_qty' => $short ? 90 : 0,
        'suggested_po_qty' => $suggested, 'need_date' => now()->addDays(20)->toDateString(),
        'po_place_by' => now()->addDays(5)->toDateString(), 'is_shortage' => $short,
    ];

    DB::table('material_requirements')->insert([
        $row((int) $items[0]->id, true, 120),
        $row((int) $items[1]->id, false, 0),
    ]);

    $this->shortItemId = (int) $items[0]->id;
});

it('opens a requisition with the run\'s shortages already on it', function (): void {
    $this->actingAs($this->purchase)->get("/purchase-requisitions/create?mrp_run={$this->runId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Procurement/Requisitions/Form')
            ->has('prefill.lines', 1)
            ->where('prefill.lines.0.item_id', $this->shortItemId)
            ->where('prefill.lines.0.qty', fn ($qty) => (float) $qty === 120.0));
});

it('opens blank when no run is named or the run does not exist', function (): void {
    $this->actingAs($this->purchase)->get('/purchase-requisitions/create')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));

    $this->actingAs($this->purchase)->get('/purchase-requisitions/create?mrp_run=999999')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});

// GAP P-06 — PR-1 AC1 / PL-5 AC2-3: a requisition raised from the plan is marked as such, its
// lines answer the run's shortage rows, and a covered shortage is not offered again.
it('marks a requisition raised from the plan as MRP-origin, links the shortage, and stops offering it', function (): void {
    $uomId = (int) DB::table('items')->where('id', $this->shortItemId)->value('base_uom_id');

    $this->actingAs($this->purchase)->post('/purchase-requisitions', [
        'mrp_run_id' => $this->runId,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'requested_on' => now()->toDateString(),
        'lines' => [['item_id' => $this->shortItemId, 'uom_id' => $uomId, 'qty' => 120]],
    ])->assertSessionHasNoErrors();

    $requisition = DB::table('purchase_requisitions')->orderByDesc('id')->first();
    $line = DB::table('purchase_requisition_lines')->where('pr_id', $requisition->id)->first();

    expect($requisition->origin)->toBe('mrp')
        ->and((int) DB::table('material_requirements')->where('mrp_run_id', $this->runId)->where('item_id', $this->shortItemId)->value('pr_line_id'))
        ->toBe((int) $line->id);

    $this->actingAs($this->purchase)->get("/purchase-requisitions/create?mrp_run={$this->runId}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('prefill', null));
});
