<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The order list as a delivery board and the order page as a dossier: stage and late counts,
 * delivery progress and lateness on every row, the handler recorded on create, and the
 * quotation, billing and "start production" step on the page.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
});

it('counts every stage and the late orders for the strip', function (): void {
    $order = SalesOrder::query()->where('status', 'confirmed')->firstOrFail();
    DB::table('sales_orders')->where('id', $order->id)->update(['delivery_date' => now()->subDays(4)->toDateString()]);

    $expectedLate = DB::table('sales_orders')
        ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
        ->whereDate('delivery_date', '<', now())
        ->count();

    $this->actingAs($this->admin)
        ->get('/sales-orders?late=1')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($expectedLate, $order): void {
            $props = $page->toArray()['props'];

            expect((int) $props['counts']['late'])->toBe($expectedLate)
                ->and((int) $props['counts']['confirmed'])->toBe(DB::table('sales_orders')->where('status', 'confirmed')->count());

            $row = collect($props['orders']['data'])->firstWhere('id', $order->id);

            expect($row['overdue'])->toBeTrue()
                ->and($row['days_to_due'])->toBe(-4)
                ->and($row)->toHaveKeys(['delivered_pct', 'ordered_qty', 'delivered_qty', 'priority', 'merchandiser']);
        });
});

it('does not call a closed order late because its date has passed', function (): void {
    $order = SalesOrder::query()->firstOrFail();
    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'closed', 'delivery_date' => now()->subDays(30)->toDateString()]);

    $this->actingAs($this->admin)
        ->get('/sales-orders?status=closed')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            $row = collect($page->toArray()['props']['orders']['data'])->firstWhere('id', $order->id);

            expect($row['overdue'])->toBeFalse();
        });
});

it('reports delivery progress per row from the lines', function (): void {
    $order = SalesOrder::query()->has('lines')->firstOrFail();
    $line = $order->lines()->firstOrFail();
    DB::table('sales_order_lines')->where('sales_order_id', $order->id)->update(['delivered_qty' => 0]);
    DB::table('sales_order_lines')->where('id', $line->id)->update(['ordered_qty' => 1000, 'delivered_qty' => 250]);

    $this->actingAs($this->admin)
        ->get('/sales-orders?customer='.$order->customer_id)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            $row = collect($page->toArray()['props']['orders']['data'])->firstWhere('id', $order->id);
            $ordered = (float) DB::table('sales_order_lines')->where('sales_order_id', $order->id)->sum('ordered_qty');

            expect((float) $row['delivered_qty'])->toBe(250.0)
                ->and((float) $row['delivered_pct'])->toBe(round(250 / $ordered * 100, 1));
        });
});

it('finds an order by its customer name', function (): void {
    $order = SalesOrder::query()->with('customer')->firstOrFail();

    $this->actingAs($this->admin)
        ->get('/sales-orders?q='.urlencode(substr($order->customer->name, 0, 5)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(array_column($page->toArray()['props']['orders']['data'], 'id'))->toContain($order->id));
});

it('records who raised the order as its handler', function (): void {
    $source = SalesOrder::query()->has('lines')->with('lines')->firstOrFail();
    $line = $source->lines->first();

    $this->actingAs($this->merchandiser)->post('/sales-orders', [
        'customer_id' => $source->customer_id,
        'order_date' => now()->toDateString(),
        'currency_id' => $source->currency_id,
        'priority' => 'normal',
        'lines' => [[
            'product_id' => $line->product_id,
            'product_spec_id' => $line->product_spec_id,
            'ordered_qty' => 1000,
            'rate_per_m' => 10,
        ]],
    ])->assertSessionHasNoErrors();

    expect(SalesOrder::query()->latest('id')->firstOrFail()->merchandiser_id)->toBe($this->merchandiser->id);
});

it('shows the quotation it came from, the billing so far and the handler on the page', function (): void {
    $order = SalesOrder::query()->whereNotNull('quotation_id')->first() ?? SalesOrder::query()->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/sales-orders/{$order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($order): void {
            $props = $page->toArray()['props'];

            expect($props['billing'])->toHaveKeys(['invoiced', 'received', 'invoices'])
                ->and($props['order'])->toHaveKeys(['merchandiser', 'quotation', 'payment_term', 'delivery_address', 'billing_address']);

            if ($order->quotation_id !== null) {
                expect($props['order']['quotation']['id'])->toBe($order->quotation_id);
            }
        });
});

it('lets a confirmed order be started into production from the page', function (): void {
    $order = SalesOrder::query()->where('status', 'confirmed')->firstOrFail();

    $this->actingAs($this->admin)
        ->get("/sales-orders/{$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect($page->toArray()['props']['availableTransitions'])->toContain('in_production'));

    $this->actingAs($this->admin)
        ->post("/sales-orders/{$order->id}/transition", ['to' => 'in_production'])
        ->assertRedirect();

    expect($order->fresh()->status)->toBe('in_production');
});

it('hands the order form the contract rates from the lists current today', function (): void {
    $this->actingAs($this->admin)
        ->get('/sales-orders/create')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $rates = $page->toArray()['props']['listRates'];
            $expected = DB::table('price_list_lines as l')
                ->join('price_lists as pl', 'pl.id', '=', 'l.price_list_id')
                ->where('pl.is_active', true)
                ->whereDate('pl.valid_from', '<=', now()->toDateString())
                ->where(fn ($q) => $q->whereNull('pl.valid_to')->orWhereDate('pl.valid_to', '>=', now()->toDateString()))
                ->distinct()->count('l.product_id');

            expect(count($rates))->toBe($expected);

            foreach ($rates as $breaks) {
                expect($breaks[0])->toHaveKeys(['min_qty', 'rate_per_m', 'list_code', 'currency']);
            }
        });
});
