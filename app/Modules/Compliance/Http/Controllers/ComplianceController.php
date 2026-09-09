<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Calculators\ClaimDilutionCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gate 2. FSC and GRS both require certified input to be reconciled against certified output,
 * and most competing systems cannot produce that reconciliation at all.
 *
 * Here it is a report (BR-42), computed from `v_coc_reconciliation`, which is the exact figure
 * an auditor asks for: conversion factor per scheme per period.
 */
class ComplianceController extends Controller
{
    public function __construct(
        private readonly ClaimDilutionCalculator $coc,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Compliance/Index', [
            'certifications' => DB::table('certifications as c')
                ->leftJoin('certification_scopes as s', 's.certification_id', '=', 'c.id')
                ->orderBy('c.expires_on')
                ->get([
                    'c.id', 'c.scheme', 'c.certificate_no', 'c.issuing_body', 'c.issued_on',
                    'c.expires_on', 'c.status', 'c.reminder_days',
                    's.min_claim_pct', 's.labelled_claim_pct', 's.max_conversion_factor',
                ])
                ->map(function ($row): array {
                    // BR-43 — validity is evaluated against today, so an expired certificate
                    // is visibly expired rather than merely dated in the past.
                    $valid = $this->coc->certificateValidOn(
                        CarbonImmutable::now(),
                        CarbonImmutable::parse($row->issued_on),
                        CarbonImmutable::parse($row->expires_on),
                    );

                    return [
                        ...(array) $row,
                        'is_valid_today' => $valid,
                        'days_to_expiry' => (int) CarbonImmutable::now()->diffInDays(
                            CarbonImmutable::parse($row->expires_on),
                            false,
                        ),
                    ];
                }),
            'certifiedStock' => DB::table('stock_balances')
                ->whereNotNull('cert_scheme')
                ->where('balance_qty', '>', 0)
                ->groupBy('cert_scheme')
                ->get(['cert_scheme', DB::raw('SUM(balance_qty) as qty'), DB::raw('COUNT(*) as lots')]),
            'recentTransactions' => DB::table('coc_transactions')
                ->orderByDesc('id')->limit(25)
                ->get(['id', 'scheme', 'direction', 'qty', 'claim_pct', 'period_year', 'period_month', 'is_locked']),
        ]);
    }

    /**
     * BR-42 — the reconciliation itself. The view does the SUM(CASE …) because MySQL has no
     * FILTER clause; the flag is computed here against the scheme's maximum conversion factor.
     */
    public function reconciliation(Request $request): Response
    {
        $scopes = DB::table('certifications as c')
            ->join('certification_scopes as s', 's.certification_id', '=', 'c.id')
            ->pluck('s.max_conversion_factor', 'c.scheme');

        $rows = DB::table('v_coc_reconciliation')
            ->when($request->query('scheme'), fn ($q, $scheme) => $q->where('scheme', $scheme))
            ->when($request->query('year'), fn ($q, $year) => $q->where('period_year', $year))
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->get()
            ->map(function ($row) use ($scopes): array {
                $max = (float) ($scopes[$row->scheme] ?? 1);

                return [
                    'scheme' => $row->scheme,
                    'period' => sprintf('%04d-%02d', $row->period_year, $row->period_month),
                    // Receipts stay on the row as context; the balance is struck against what
                    // was actually consumed, which is the question a GRS auditor asks.
                    'certified_received_qty' => round((float) $row->certified_input_qty, 6),
                    'certified_consumed_qty' => round((float) $row->certified_consumed_qty, 6),
                    // C3 — the period's own year and month, and whether it is already closed.
                    // The screen needs both to offer the close and to stop offering it twice.
                    'period_year' => (int) $row->period_year,
                    'period_month' => (int) $row->period_month,
                    'is_closed' => DB::table('coc_transactions')
                        ->where('scheme', $row->scheme)
                        ->where('period_year', $row->period_year)
                        ->where('period_month', $row->period_month)
                        ->where('is_locked', false)
                        ->doesntExist(),
                    ...$this->coc->reconcile(
                        (float) $row->certified_basis_qty,
                        (float) $row->certified_output_qty,
                        $max,
                    ),
                ];
            });

        return Inertia::render('Compliance/Reconciliation', [
            'rows' => $rows,
            'filters' => $request->only(['scheme', 'year']),
            'schemes' => DB::table('certifications')->distinct()->orderBy('scheme')->pluck('scheme'),
        ]);
    }

    /**
     * CP-5 AC3 / C3 — close a period.
     *
     * `is_locked` was rendered on the compliance screen and set by nothing: the column existed,
     * the `coc.lock_period` permission existed, and there was no route behind either. A chain
     * of custody an auditor can still be adding rows to after the fact is not a chain of
     * custody, and G5 turns on being able to say "this is what that month was".
     *
     * The lock is on the period, not on individual rows, because that is the unit a scheme
     * certifies and the unit `v_coc_reconciliation` groups by.
     */
    public function closePeriod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scheme' => ['required', 'string', 'max:20'],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $rows = DB::table('coc_transactions')
            ->where('scheme', $data['scheme'])
            ->where('period_year', $data['period_year'])
            ->where('period_month', $data['period_month']);

        $total = (clone $rows)->count();

        if ($total === 0) {
            return back()->with('error', 'That period has no transactions to close.');
        }

        $open = (clone $rows)->where('is_locked', false)->count();

        if ($open === 0) {
            return back()->with('error', 'That period is already closed.');
        }

        // Closing a period whose conversion factor is impossible would sign off the number an
        // auditor is most likely to challenge. It is allowed — the figure may be right and the
        // ceiling wrong — but not silently.
        $reconciliation = DB::table('v_coc_reconciliation')
            ->where('scheme', $data['scheme'])
            ->where('period_year', $data['period_year'])
            ->where('period_month', $data['period_month'])
            ->first();

        $ceiling = DB::table('certifications as c')
            ->join('certification_scopes as s', 's.certification_id', '=', 'c.id')
            ->where('c.scheme', $data['scheme'])
            ->value('s.max_conversion_factor');

        $breach = $reconciliation !== null
            && $ceiling !== null
            && $reconciliation->conversion_factor !== null
            && (float) $reconciliation->conversion_factor > (float) $ceiling;

        if ($breach && ! $request->boolean('acknowledge_breach')) {
            return back()->with('error', sprintf(
                'This period converts at %s against a ceiling of %s. Review the transactions behind it, or close it again confirming the figure is correct.',
                rtrim(rtrim(number_format((float) $reconciliation->conversion_factor, 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float) $ceiling, 4, '.', ''), '0'), '.'),
            ));
        }

        DB::transaction(function () use ($data, $rows, $total, $open, $breach, $request): void {
            $rows->update(['is_locked' => true]);

            $this->audit->recordTable('coc_transactions', 0, 'status_changed', null, [
                'action' => 'period_closed',
                'scheme' => $data['scheme'],
                'period_year' => $data['period_year'],
                'period_month' => $data['period_month'],
                'rows_locked' => $open,
                'rows_in_period' => $total,
                'conversion_ceiling_breached' => $breach,
                'closed_by' => $request->user()?->id,
            ]);
        });

        return back()->with('success', sprintf(
            '%s %04d-%02d closed. %d transaction(s) locked.',
            $data['scheme'],
            $data['period_year'],
            $data['period_month'],
            $open,
        ));
    }
}
