<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The laboratory: the nine tests from BR-32 with their methods and thresholds, and the test
 * reports that become customer-facing certificates.
 *
 * QC3 — a report is immutable once issued. Reprinting must reproduce the original values
 * byte for byte, so nothing on this screen edits an issued report.
 */
class LabController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Quality/Lab/Index', [
            'tests' => DB::table('lab_tests')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'method', 'scale', 'default_pass_value', 'unit']),
            'reports' => DB::table('test_reports as tr')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'tr.lot_id')
                ->leftJoin('customers as c', 'c.id', '=', 'tr.customer_id')
                ->when($request->query('status'), fn ($q, $s) => $q->where('tr.status', $s))
                ->orderByDesc('tr.id')
                ->paginate(25)
                ->withQueryString(),
            // BR-32 — a brand may impose a stricter threshold than the house default without
            // forking the catalogue.
            'customerRequirements' => DB::table('customer_test_requirements as ctr')
                ->join('customers as c', 'c.id', '=', 'ctr.customer_id')
                ->join('lab_tests as lt', 'lt.id', '=', 'ctr.lab_test_id')
                ->leftJoin('products as p', 'p.id', '=', 'ctr.product_id')
                ->orderBy('c.name')
                ->limit(100)
                ->get([
                    'ctr.id', 'c.name as customer', 'p.code as product_code', 'lt.code as test_code',
                    'lt.name as test_name', 'lt.default_pass_value', 'ctr.pass_value', 'ctr.is_mandatory',
                ]),
            'filters' => $request->only(['status']),
        ]);
    }
}
