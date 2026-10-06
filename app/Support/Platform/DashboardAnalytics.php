<?php

declare(strict_types=1);

namespace App\Support\Platform;

use App\Models\User;
use App\Support\Scoping\FactoryUnitFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's flow figures: what came in, what went out, how well, over a rolling window.
 *
 * Every figure here is computed from the transactions themselves — order dates, challan
 * dates, decision dates — so each one can be compared with the window before it without a
 * nightly snapshot. The tiles in `DashboardController` are a photograph of now; this is the
 * film of the last month.
 *
 * Nothing is cached: the demo factory runs a few dozen orders a month and every query is one
 * aggregate over an indexed date column. When a factory outgrows that, cache this for a
 * minute, not longer — a dashboard that lags a delivery by an hour gets ignored.
 */
class DashboardAnalytics
{
    /** Rolling window for orders, deliveries, invoices and quality. */
    public const WINDOW_DAYS = 30;

    /** Quotations take weeks to be decided; a month is too short a sample. */
    public const QUOTATION_WINDOW_DAYS = 90;

    /** Weekly buckets on the flow chart and sparklines. */
    public const WEEKS = 12;

    /** Promised-week buckets on the delivery outlook, after the overdue one. */
    public const OUTLOOK_WEEKS = 6;

    public function __construct(private readonly FactoryUnitFilter $units) {}

    /**
     * Sections the user may not see are null, not zero — zero would read as a fact.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $today = now()->startOfDay();

        $sales = $user->can('sales_order.view_any');

        return [
            'window_days' => self::WINDOW_DAYS,
            'quotation_window_days' => self::QUOTATION_WINDOW_DAYS,
            'weeks' => self::WEEKS,
            'orders' => $sales ? $this->orders($today) : null,
            'delivered' => $sales ? $this->delivered($today) : null,
            'on_time' => $sales ? $this->onTime($today) : null,
            'outlook' => $sales ? $this->outlook($today) : null,
            'quotations' => $user->can('quotation.view_any') ? $this->quotations($today) : null,
            'receivables' => $user->can('sales_invoice.view_any') ? $this->receivables($today) : null,
            'quality' => $user->can('qc_inspection.view_any') ? $this->quality($today) : null,
        ];
    }

    /**
     * Orders received: confirmed or further, dated in the window. Drafts are not orders yet
     * and cancellations never were.
     *
     * @return array<string, mixed>
     */
    private function orders(Carbon $today): array
    {
        // Columns qualified throughout: the weekly series joins the lines, which have a status too.
        $base = fn (): Builder => $this->units->apply(
            DB::table('sales_orders')->whereNotIn('sales_orders.status', ['draft', 'cancelled']),
            'sales_orders.factory_unit_id',
        );

        [$from, $to, $prevFrom, $prevTo] = $this->windows($today, self::WINDOW_DAYS);

        $current = $base()->whereBetween('sales_orders.order_date', [$from, $to])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(sales_orders.total * sales_orders.exchange_rate), 0) AS value')
            ->first();
        $previous = $base()->whereBetween('sales_orders.order_date', [$prevFrom, $prevTo])->count();

        $weekly = $this->weekly(
            $base()
                ->join('sales_order_lines', 'sales_order_lines.sales_order_id', '=', 'sales_orders.id')
                ->where('sales_orders.order_date', '>=', $this->weeksFrom($today)),
            'sales_orders.order_date',
            'COUNT(DISTINCT sales_orders.id) AS count, COALESCE(SUM(sales_order_lines.ordered_qty), 0) AS pieces',
            $today,
        );

        return [
            'count' => (int) ($current->n ?? 0),
            'value' => round((float) ($current->value ?? 0), 2),
            'previous_count' => $previous,
            'delta_pct' => $this->deltaPct((float) ($current->n ?? 0), (float) $previous),
            'weekly' => $weekly,
        ];
    }

    /**
     * Pieces that left the factory on an issued delivery note (challan), by challan date.
     *
     * @return array<string, mixed>
     */
    private function delivered(Carbon $today): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($today, self::WINDOW_DAYS);

        $current = (float) $this->challanLines()->whereBetween('dc.challan_date', [$from, $to])->sum('dcl.qty');
        $previous = (float) $this->challanLines()->whereBetween('dc.challan_date', [$prevFrom, $prevTo])->sum('dcl.qty');

        $weekly = $this->weekly(
            $this->challanLines()->where('dc.challan_date', '>=', $this->weeksFrom($today)),
            'dc.challan_date',
            'COUNT(DISTINCT dc.id) AS count, COALESCE(SUM(dcl.qty), 0) AS pieces',
            $today,
        );

        return [
            'pieces' => $current,
            'previous_pieces' => $previous,
            'delta_pct' => $this->deltaPct($current, $previous),
            'weekly' => $weekly,
        ];
    }

    /**
     * Of the pieces delivered in the window, the share that left on or before the line's
     * promised date. Null when nothing was delivered: 0% would say every delivery was late.
     *
     * @return array<string, mixed>
     */
    private function onTime(Carbon $today): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($today, self::WINDOW_DAYS);

        $rate = function (string $from, string $to): array {
            $row = $this->challanLines()
                ->join('sales_order_lines AS sol', 'sol.id', '=', 'dcl.sales_order_line_id')
                ->whereBetween('dc.challan_date', [$from, $to])
                ->selectRaw('COALESCE(SUM(CASE WHEN dc.challan_date <= sol.promised_date THEN dcl.qty ELSE 0 END), 0) AS on_time, COALESCE(SUM(dcl.qty), 0) AS total')
                ->first();

            $total = (float) ($row->total ?? 0);

            return [
                'pct' => $total > 0 ? round((float) $row->on_time / $total * 100, 1) : null,
                'pieces' => $total,
            ];
        };

        $current = $rate($from, $to);
        $previous = $rate($prevFrom, $prevTo);

        return [
            'pct' => $current['pct'],
            'pieces' => $current['pieces'],
            'previous_pct' => $previous['pct'],
            'delta_pts' => $current['pct'] !== null && $previous['pct'] !== null
                ? round($current['pct'] - $previous['pct'], 1)
                : null,
        ];
    }

    /**
     * Decided quotations in the window: won against lost. Sent-and-waiting ones are not a
     * result yet, so they are counted beside the rate rather than inside it.
     *
     * @return array<string, mixed>
     */
    private function quotations(Carbon $today): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($today, self::QUOTATION_WINDOW_DAYS);

        $decided = fn (string $from, string $to): object => DB::table('quotations')
            ->whereIn('status', ['accepted', 'rejected'])
            ->whereBetween(DB::raw('DATE(COALESCE(decided_at, created_at))'), [$from, $to])
            ->selectRaw("COALESCE(SUM(status = 'accepted'), 0) AS won, COALESCE(SUM(status = 'rejected'), 0) AS lost")
            ->first();

        $current = $decided($from, $to);
        $previous = $decided($prevFrom, $prevTo);

        $rate = fn (object $row): ?float => ($row->won + $row->lost) > 0
            ? round((float) $row->won / ((float) $row->won + (float) $row->lost) * 100, 1)
            : null;

        $currentRate = $rate($current);
        $previousRate = $rate($previous);

        return [
            'won' => (int) $current->won,
            'lost' => (int) $current->lost,
            'awaiting' => DB::table('quotations')->where('status', 'sent')->count(),
            'win_rate' => $currentRate,
            'previous_win_rate' => $previousRate,
            'delta_pts' => $currentRate !== null && $previousRate !== null ? round($currentRate - $previousRate, 1) : null,
        ];
    }

    /**
     * Money owed by customers, in the base currency: everything unpaid, and the part of it
     * past its due date.
     *
     * @return array<string, mixed>
     */
    private function receivables(Carbon $today): array
    {
        $open = fn (): Builder => DB::table('sales_invoices')->whereNotIn('status', ['draft', 'cancelled', 'paid']);

        $outstanding = (float) $open()->sum(DB::raw('(total - received_amount) * exchange_rate'));

        $overdue = $open()->whereDate('due_date', '<', $today)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM((total - received_amount) * exchange_rate), 0) AS amount')
            ->first();

        return [
            'outstanding' => round($outstanding, 2),
            'overdue_amount' => round((float) ($overdue->amount ?? 0), 2),
            'overdue_count' => (int) ($overdue->n ?? 0),
        ];
    }

    /**
     * Inspections in the window: how many, how many accepted first time, and the average
     * defects per hundred units (DHU).
     *
     * @return array<string, mixed>
     */
    private function quality(Carbon $today): array
    {
        [$from, $to, $prevFrom, $prevTo] = $this->windows($today, self::WINDOW_DAYS);

        $window = fn (string $from, string $to): object => DB::table('qc_inspections')
            ->whereBetween('inspected_on', [$from, $to])
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(result = 'accepted'), 0) AS accepted, AVG(dhu) AS dhu")
            ->first();

        $current = $window($from, $to);
        $previous = $window($prevFrom, $prevTo);

        $pct = fn (object $row): ?float => (int) $row->n > 0 ? round((float) $row->accepted / (int) $row->n * 100, 1) : null;

        $currentPct = $pct($current);
        $previousPct = $pct($previous);

        return [
            'inspections' => (int) $current->n,
            'accepted_pct' => $currentPct,
            'previous_accepted_pct' => $previousPct,
            'delta_pts' => $currentPct !== null && $previousPct !== null ? round($currentPct - $previousPct, 1) : null,
            'avg_dhu' => $current->dhu === null ? null : round((float) $current->dhu, 2),
        ];
    }

    /**
     * Pieces still owed on the order book, by the week they were promised for: what is
     * already late, what is due this week, and the weeks after. The planner's horizon.
     *
     * @return list<array{key: string, label: string, starts_on: string|null, pieces: float, lines: int}>
     */
    private function outlook(Carbon $today): array
    {
        $rows = $this->units->apply(
            DB::table('v_order_book AS ob')
                ->join('sales_orders', 'sales_orders.id', '=', 'ob.sales_order_id')
                ->whereColumn('ob.delivered_qty', '<', 'ob.ordered_qty'),
            'sales_orders.factory_unit_id',
        )
            ->selectRaw(
                'CASE WHEN ob.promised_date < ? THEN -1 ELSE FLOOR(DATEDIFF(ob.promised_date, ?) / 7) END AS wk,'
                .' COALESCE(SUM(ob.ordered_qty - ob.delivered_qty), 0) AS pieces, COUNT(*) AS line_count',
                [$today->toDateString(), $today->toDateString()],
            )
            ->groupBy('wk')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->wk);

        $bucket = fn (int $from, ?int $to = null): array => $rows
            ->filter(fn (object $row, int $wk): bool => $wk >= $from && ($to === null || $wk <= $to))
            ->reduce(fn (array $carry, object $row): array => [
                'pieces' => $carry['pieces'] + (float) $row->pieces,
                'lines' => $carry['lines'] + (int) $row->line_count,
            ], ['pieces' => 0.0, 'lines' => 0]);

        $buckets = [
            ['key' => 'overdue', 'label' => 'Overdue', 'starts_on' => null, ...$bucket(-1, -1)],
            ['key' => 'w0', 'label' => 'This week', 'starts_on' => $today->toDateString(), ...$bucket(0, 0)],
            ['key' => 'w1', 'label' => 'Next week', 'starts_on' => $today->copy()->addDays(7)->toDateString(), ...$bucket(1, 1)],
        ];

        for ($week = 2; $week < self::OUTLOOK_WEEKS; $week++) {
            $buckets[] = [
                'key' => "w{$week}",
                'label' => null, // the page labels it by date, in the organisation's format
                'starts_on' => $today->copy()->addDays($week * 7)->toDateString(),
                ...$bucket($week, $week),
            ];
        }

        $buckets[] = ['key' => 'later', 'label' => 'Later', 'starts_on' => null, ...$bucket(self::OUTLOOK_WEEKS)];

        return $buckets;
    }

    /** Issued delivery notes with their lines, joined to the order for unit scoping. */
    private function challanLines(): Builder
    {
        return $this->units->apply(
            DB::table('delivery_challans AS dc')
                ->join('delivery_challan_lines AS dcl', 'dcl.delivery_challan_id', '=', 'dc.id')
                ->join('sales_orders', 'sales_orders.id', '=', 'dc.sales_order_id')
                ->whereNotIn('dc.status', ['draft', 'cancelled', 'returned']),
            'sales_orders.factory_unit_id',
        );
    }

    /**
     * Twelve weekly buckets counted back from today, oldest first, every week present even
     * when empty — a sparkline with gaps lies about the shape.
     *
     * @return list<array{starts_on: string, ends_on: string, count: int, pieces: float}>
     */
    private function weekly(Builder $query, string $dateColumn, string $aggregates, Carbon $today): array
    {
        $rows = $query
            ->selectRaw("FLOOR(DATEDIFF(?, {$dateColumn}) / 7) AS weeks_ago, {$aggregates}", [$today->toDateString()])
            ->groupBy('weeks_ago')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->weeks_ago);

        $weeks = [];

        for ($ago = self::WEEKS - 1; $ago >= 0; $ago--) {
            $row = $rows->get($ago);

            $weeks[] = [
                'starts_on' => $today->copy()->subDays($ago * 7 + 6)->toDateString(),
                'ends_on' => $today->copy()->subDays($ago * 7)->toDateString(),
                'count' => (int) ($row->count ?? 0),
                'pieces' => (float) ($row->pieces ?? 0),
            ];
        }

        return $weeks;
    }

    private function weeksFrom(Carbon $today): string
    {
        return $today->copy()->subDays(self::WEEKS * 7 - 1)->toDateString();
    }

    /**
     * The current window and the one before it, as inclusive date strings.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function windows(Carbon $today, int $days): array
    {
        return [
            $today->copy()->subDays($days - 1)->toDateString(),
            $today->toDateString(),
            $today->copy()->subDays(2 * $days - 1)->toDateString(),
            $today->copy()->subDays($days)->toDateString(),
        ];
    }

    /** Change against the previous window, or null when there was nothing to compare with. */
    private function deltaPct(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
