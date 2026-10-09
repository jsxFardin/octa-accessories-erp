<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Periods\PeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month-end close. A closed month takes no stock movement, invoice, bill, receipt or payment;
 * reopening it is recorded, with who and why, because that is the question an auditor asks.
 */
class AccountingPeriodController extends Controller
{
    public function __construct(private readonly PeriodLock $periods) {}

    public function index(): Response
    {
        $rows = DB::table('accounting_periods as p')
            ->leftJoin('users as c', 'c.id', '=', 'p.closed_by')
            ->leftJoin('users as r', 'r.id', '=', 'p.reopened_by')
            ->get(['p.*', 'c.name as closed_by_name', 'r.name as reopened_by_name'])
            ->keyBy(fn ($row): string => sprintf('%04d-%02d', $row->period_year, $row->period_month));

        // Every month from the first dated document to this one, so a month with nothing
        // recorded against it still shows as open rather than vanishing from the list.
        $first = collect([
            DB::table('stock_ledger')->min('occurred_at'),
            DB::table('sales_invoices')->min('invoice_date'),
            DB::table('grns')->min('received_on'),
        ])->filter()->map(fn ($d) => CarbonImmutable::parse($d)->startOfMonth())->min()
            ?? CarbonImmutable::now()->startOfMonth();

        $months = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        while ($cursor->greaterThanOrEqualTo($first) && count($months) < 36) {
            $key = $cursor->format('Y-m');
            $row = $rows->get($key);

            $months[] = [
                'key' => $key,
                'year' => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'label' => $cursor->format('F Y'),
                'is_closed' => $row !== null && $row->status === 'closed',
                'closed_at' => $row?->closed_at,
                'closed_by' => $row?->closed_by_name,
                'reopened_at' => $row?->reopened_at,
                'reopened_by' => $row?->reopened_by_name,
                'note' => $row?->note,
                'is_current' => $cursor->isSameMonth(CarbonImmutable::now()),
            ];

            $cursor = $cursor->subMonth();
        }

        return Inertia::render('Admin/AccountingPeriods', ['months' => $months]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->periods->close((int) $data['year'], (int) $data['month'], $request->user(), $data['note'] ?? null);

        return back()->with('success', sprintf('%s is closed. Nothing can be dated into it now.', CarbonImmutable::create((int) $data['year'], (int) $data['month'], 1)->format('F Y')));
    }

    public function reopen(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $this->periods->reopen((int) $data['year'], (int) $data['month'], $request->user(), $data['note']);

        return back()->with('success', sprintf('%s is open again. The reopening is on the audit log.', CarbonImmutable::create((int) $data['year'], (int) $data['month'], 1)->format('F Y')));
    }
}
