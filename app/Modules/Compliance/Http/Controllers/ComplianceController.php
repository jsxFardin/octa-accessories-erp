<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Calculators\ClaimDilutionCalculator;
use Carbon\CarbonImmutable;
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
    public function __construct(private readonly ClaimDilutionCalculator $coc) {}

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
                    ...$this->coc->reconcile(
                        (float) $row->certified_input_qty,
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
}
