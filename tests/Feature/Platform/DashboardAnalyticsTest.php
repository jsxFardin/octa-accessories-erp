<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Platform\DashboardAnalytics;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The dashboard's flow figures: every one is derived from the transactions, so each can be
 * checked against the raw tables, and sections a role may not see are null rather than zero.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->storeKeeper = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();
});

it('counts orders received as non-draft orders dated inside the window', function (): void {
    $from = now()->startOfDay()->subDays(DashboardAnalytics::WINDOW_DAYS - 1)->toDateString();
    $to = now()->toDateString();

    $expected = DB::table('sales_orders')
        ->whereNotIn('status', ['draft', 'cancelled'])
        ->whereBetween('order_date', [$from, $to])
        ->count();

    $analytics = app(DashboardAnalytics::class)->for($this->admin);

    expect($analytics['orders']['count'])->toBe($expected)
        ->and($analytics['orders']['weekly'])->toHaveCount(DashboardAnalytics::WEEKS)
        ->and(array_sum(array_column($analytics['orders']['weekly'], 'count')))->toBeGreaterThanOrEqual($expected);
});

it('spreads the delivery outlook over every owed piece on the order book', function (): void {
    $owed = (float) DB::table('v_order_book')
        ->whereColumn('delivered_qty', '<', 'ordered_qty')
        ->sum(DB::raw('ordered_qty - delivered_qty'));

    $outlook = app(DashboardAnalytics::class)->for($this->admin)['outlook'];

    expect(array_sum(array_column($outlook, 'pieces')))->toEqual($owed)
        ->and($outlook[0]['key'])->toBe('overdue')
        ->and(end($outlook)['key'])->toBe('later');
});

it('puts a line promised yesterday in the overdue bucket and one promised in 10 days in the week after next', function (): void {
    $line = DB::table('v_order_book')->whereColumn('delivered_qty', '<', 'ordered_qty')->firstOrFail();
    $owedOnLine = (float) $line->ordered_qty - (float) $line->delivered_qty;

    $bucket = function (string $date, string $key) use ($line): float {
        DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->update(['promised_date' => $date]);
        $outlook = collect(app(DashboardAnalytics::class)->for($this->admin)['outlook'])->keyBy('key');

        return (float) $outlook[$key]['pieces'];
    };

    $overdueBefore = $bucket(now()->addDays(100)->toDateString(), 'overdue');

    expect($bucket(now()->subDay()->toDateString(), 'overdue'))->toEqual($overdueBefore + $owedOnLine);

    $laterBefore = $bucket(now()->addDays(100)->toDateString(), 'w1');

    expect($bucket(now()->addDays(10)->toDateString(), 'w1'))->toEqual($laterBefore + $owedOnLine);
});

it('reports no on-time rate rather than zero when nothing was delivered in the window', function (): void {
    DB::table('delivery_challans')->update(['challan_date' => now()->subYears(2)->toDateString()]);

    $onTime = app(DashboardAnalytics::class)->for($this->admin)['on_time'];

    expect($onTime['pct'])->toBeNull()
        ->and($onTime['pieces'])->toEqual(0.0)
        ->and($onTime['delta_pts'])->toBeNull();
});

it('scores a delivery on time only when the challan date is on or before the promised date', function (): void {
    $line = DB::table('delivery_challan_lines AS dcl')
        ->join('delivery_challans AS dc', 'dc.id', '=', 'dcl.delivery_challan_id')
        ->whereNotIn('dc.status', ['draft', 'cancelled', 'returned'])
        ->select('dcl.sales_order_line_id', 'dc.id AS challan_id')
        ->first();

    if ($line === null) {
        $this->markTestSkipped('The test data has no issued delivery note line to score.');
    }

    // Only this challan in the window, delivered today.
    DB::table('delivery_challans')->update(['challan_date' => now()->subYears(2)->toDateString()]);
    DB::table('delivery_challans')->where('id', $line->challan_id)->update(['challan_date' => now()->toDateString()]);

    DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->update(['promised_date' => now()->toDateString()]);
    expect(app(DashboardAnalytics::class)->for($this->admin)['on_time']['pct'])->toEqual(100.0);

    DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->update(['promised_date' => now()->subDay()->toDateString()]);
    expect(app(DashboardAnalytics::class)->for($this->admin)['on_time']['pct'])->toEqual(0.0);
});

it('leaves the delta empty when the previous window had nothing', function (): void {
    DB::table('sales_orders')->whereNotIn('status', ['draft', 'cancelled'])->update(['order_date' => now()->toDateString()]);

    $orders = app(DashboardAnalytics::class)->for($this->admin)['orders'];

    expect($orders['previous_count'])->toBe(0)
        ->and($orders['delta_pct'])->toBeNull()
        ->and($orders['count'])->toBeGreaterThan(0);
});

it('hides whole sections from a role that may not see them, as null rather than zero', function (): void {
    $this->actingAs($this->storeKeeper)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $analytics = $page->toArray()['props']['analytics'];

            expect($analytics['quotations'])->toBeNull()
                ->and($analytics['receivables'])->toBeNull()
                ->and($analytics['orders'])->toBeNull();
        });

    $this->actingAs($this->admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('analytics.orders.weekly', DashboardAnalytics::WEEKS)
            ->has('analytics.outlook', DashboardAnalytics::OUTLOOK_WEEKS + 2)
            ->has('analytics.quotations.win_rate')
            ->has('analytics.receivables.overdue_amount')
            ->has('analytics.quality.inspections'));
});
