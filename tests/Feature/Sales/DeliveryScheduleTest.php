<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * GAP P-02 — delivery schedules have a writer, and the order generates its job cards by BR-28:
 * one per dated shipment, none larger than the routing's maximum lot.
 */
beforeEach(function (): void {
    $this->sales = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();

    // A product that can carry a card: one already has.
    $card = JobCard::query()->whereNotNull('sales_order_line_id')->with('product')->orderBy('id')->firstOrFail();
    $this->product = $card->product;
    $this->unit = (int) DB::table('factory_units')->where('is_active', true)->value('id');

    $this->raise = function (array $line = [], array $order = []): SalesOrder {
        $this->actingAs($this->sales)->post('/sales-orders', [
            'customer_id' => $this->product->customer_id,
            'order_date' => now()->toDateString(),
            'delivery_date' => now()->addDays(45)->toDateString(),
            'currency_id' => $this->base->id,
            'priority' => 'normal',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Scheduled line',
                'ordered_qty' => 1000,
                'rate_per_m' => 50,
                'schedules' => [
                    ['qty' => 600, 'due_date' => now()->addDays(20)->toDateString()],
                    ['qty' => 400, 'due_date' => now()->addDays(40)->toDateString()],
                ],
                ...$line,
            ]],
            ...$order,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sales = SalesOrder::query()->latest('id')->firstOrFail();
        DB::table('sales_orders')->where('id', $sales->id)->update(['status' => 'confirmed']);

        return $sales->fresh();
    };
});

it('stores the dated shipments on the line and shows them on the order', function (): void {
    $order = ($this->raise)();
    $line = $order->lines()->firstOrFail();

    expect($line->schedules)->toHaveCount(2)
        ->and((float) $line->schedules[0]->qty)->toBe(600.0)
        ->and($line->schedules[1]->due_date->toDateString())->toBe(now()->addDays(40)->toDateString())
        // The line's promised date follows the first shipment when none was typed.
        ->and($line->promised_date?->toDateString())->toBe(now()->addDays(20)->toDateString());

    $this->get("/sales-orders/{$order->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('lines.0.schedules', 2)->where('lines.0.schedules.0.qty', fn ($q) => (float) $q === 600.0));
});

it('refuses shipments that do not add up to the ordered quantity', function (): void {
    $this->actingAs($this->sales)->post('/sales-orders', [
        'customer_id' => $this->product->customer_id,
        'order_date' => now()->toDateString(),
        'currency_id' => $this->base->id,
        'priority' => 'normal',
        'lines' => [[
            'product_id' => $this->product->id, 'ordered_qty' => 1000, 'rate_per_m' => 50,
            'schedules' => [['qty' => 600, 'due_date' => now()->addDays(20)->toDateString()]],
        ]],
    ])->assertSessionHasErrors('lines.0.schedules');
});

it('generates one job card per shipment, due on the shipment date, and leaves a line with live cards alone', function (): void {
    $order = ($this->raise)();
    $line = $order->lines()->firstOrFail();

    $this->actingAs($this->planner)->post("/sales-orders/{$order->id}/job-cards", ['factory_unit_id' => $this->unit])
        ->assertSessionHasNoErrors()->assertRedirect();

    $cards = JobCard::query()->where('sales_order_line_id', $line->id)->orderBy('id')->get();

    expect($cards)->toHaveCount(2)
        ->and((float) $cards[0]->planned_qty)->toBe(600.0)
        ->and($cards[0]->due_date?->toDateString())->toBe(now()->addDays(20)->toDateString())
        ->and((float) $cards[1]->planned_qty)->toBe(400.0)
        ->and($cards[1]->due_date?->toDateString())->toBe(now()->addDays(40)->toDateString())
        ->and($cards[0]->operations()->count())->toBeGreaterThan(0);

    // Run again: nothing more is raised for a line that already has live cards.
    $this->post("/sales-orders/{$order->id}/job-cards", ['factory_unit_id' => $this->unit])->assertRedirect();
    expect(JobCard::query()->where('sales_order_line_id', $line->id)->count())->toBe(2);
});

it('splits each shipment at the routing maximum lot size', function (): void {
    DB::table('routings')->where('id', $this->product->routing_id)->update(['max_lot_size' => 300]);
    $order = ($this->raise)();
    $line = $order->lines()->firstOrFail();

    $this->actingAs($this->planner)->post("/sales-orders/{$order->id}/job-cards", ['factory_unit_id' => $this->unit])->assertSessionHasNoErrors();

    $qtys = JobCard::query()->where('sales_order_line_id', $line->id)->orderBy('id')->pluck('planned_qty')->map(fn ($q) => (float) $q)->all();

    expect($qtys)->toBe([300.0, 300.0, 300.0, 100.0]);
});

it('a line without a schedule becomes one job card due on its promised date', function (): void {
    $order = ($this->raise)(['schedules' => [], 'promised_date' => now()->addDays(30)->toDateString()]);
    $line = $order->lines()->firstOrFail();

    $this->actingAs($this->planner)->post("/sales-orders/{$order->id}/job-cards", ['factory_unit_id' => $this->unit])->assertSessionHasNoErrors();

    $cards = JobCard::query()->where('sales_order_line_id', $line->id)->get();
    expect($cards)->toHaveCount(1)
        ->and((float) $cards[0]->planned_qty)->toBe(1000.0)
        ->and($cards[0]->due_date?->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('is the planner\'s verb, not the merchandiser\'s', function (): void {
    $order = ($this->raise)();
    $this->actingAs($this->sales)->post("/sales-orders/{$order->id}/job-cards", ['factory_unit_id' => $this->unit])->assertForbidden();
});
