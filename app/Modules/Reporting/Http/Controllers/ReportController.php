<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\ReportCatalogue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P2-3 — read-only operational reports. Every figure is the same query the transactional
 * screen already uses; this controller never writes.
 */
class ReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'reports' => ReportCatalogue::menu(),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $query = ReportCatalogue::make($report);

        $filterKeys = array_merge(
            ['q', 'from', 'to', 'per_page'],
            array_column($query->filterFields(), 'key'),
        );

        return Inertia::render('Reports/Show', [
            'report' => [
                'key' => $query->key(),
                'title' => $query->title(),
                'subtitle' => $query->subtitle(),
                'columns' => $query->columns(),
                'filters' => $query->filters(),
                'document_path' => $query->documentPath(),
            ],
            'rows' => $query->paginate($request),
            'totals' => $query->totals($request),
            'extras' => $query->extras($request),
            'applied' => $request->only($filterKeys),
        ]);
    }
}
