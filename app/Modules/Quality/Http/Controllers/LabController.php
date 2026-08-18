<?php

declare(strict_types=1);

namespace App\Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Quality\Models\TestReport;
use App\Modules\Quality\Models\TestReportLine;
use App\Modules\Quality\States\TestReportStateMachine;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * QL-5 / QL-6 — lab test worksheet and certificate issuance.
 *
 * QC3 — a report is immutable once issued. Reprinting reproduces the original values
 * byte for byte, so nothing here edits an issued report.
 */
class LabController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly TestReportStateMachine $states,
    ) {}

    public function index(Request $request): Response
    {
        $reportsQuery = TestReport::query()
            ->with(['customer:id,code,name', 'lot:id,lot_no', 'technician:id,name']);

        $this->applyListing(
            $reportsQuery,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status'],
            sortable: ['number', 'tested_on', 'overall_result', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Quality/Lab/Index', [
            'tests' => DB::table('lab_tests')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'method', 'scale', 'default_pass_value', 'unit']),
            'reports' => $reportsQuery->paginate($this->perPage($request))->withQueryString()->through(
                fn (TestReport $r): array => [
                    ...$r->only(['id', 'number', 'tested_on', 'overall_result', 'status']),
                    'customer' => $r->customer?->name,
                    'lot_no' => $r->lot?->lot_no,
                    'technician' => $r->technician?->name,
                ],
            ),
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
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }

    public function create(Request $request): Response
    {
        $customers = DB::table('customers')->where('is_active', true)->orderBy('name')
            ->select(['id', 'code', 'name'])->get();

        $lots = DB::table('stock_lots')->where('status', 'available')->orderByDesc('id')
            ->select(['id', 'lot_no', 'item_id'])->limit(200)->get();

        $labTests = DB::table('lab_tests')->where('is_active', true)->orderBy('code')
            ->get(['id', 'code', 'name', 'method', 'scale', 'default_pass_value', 'unit']);

        $products = DB::table('products')->where('is_active', true)->orderBy('code')
            ->select(['id', 'code', 'name'])->get();

        return Inertia::render('Quality/Lab/Form', [
            'customers' => $customers,
            'lots' => $lots,
            'labTests' => $labTests,
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'tested_on' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'results' => ['required', 'array', 'min:1'],
            'results.*.lab_test_id' => ['required', 'integer', 'exists:lab_tests,id'],
            'results.*.result_value' => ['required', 'string', 'max:40'],
        ]);

        $report = DB::transaction(function () use ($data, $request): TestReport {
            /** @var TestReport $report */
            $report = new TestReport;
            $report->forceFill([
                'lot_id' => $data['lot_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'customer_id' => $data['customer_id'] ?? null,
                'tested_on' => $data['tested_on'],
                'technician_id' => $request->user()->id,
                'overall_result' => 'pending',
                'status' => TestReport::DRAFT,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
            ])->save();

            $anyFail = false;

            foreach ($data['results'] as $result) {
                $labTest = DB::table('lab_tests')->where('id', $result['lab_test_id'])->first();

                if ($labTest === null) {
                    continue;
                }

                $passValue = $this->applicableThreshold($labTest, $data['customer_id'] ?? null, $data['product_id'] ?? null);
                $verdict = $this->computeVerdict($labTest, $result['result_value'], $passValue);

                if ($verdict === 'fail') {
                    $anyFail = true;
                }

                $line = new TestReportLine;
                $line->forceFill([
                    'test_report_id' => $report->id,
                    'lab_test_id' => $result['lab_test_id'],
                    'result_value' => $result['result_value'],
                    'pass_value' => $passValue,
                    'result' => $verdict,
                ])->save();
            }

            $report->forceFill(['overall_result' => $anyFail ? 'fail' : 'pass'])->save();

            return $report;
        });

        return redirect()
            ->route('lab.show', $report)
            ->with('success', 'Test report created.');
    }

    public function show(TestReport $report): Response
    {
        $report->load(['customer:id,code,name', 'lot:id,lot_no', 'technician:id,name', 'lines', 'creator:id,name']);

        $labTests = DB::table('lab_tests')
            ->whereIn('id', $report->lines->pluck('lab_test_id'))
            ->get(['id', 'code', 'name', 'method', 'scale', 'unit'])
            ->keyBy('id');

        return Inertia::render('Quality/Lab/Show', [
            'report' => [
                ...$report->only(['id', 'number', 'tested_on', 'overall_result', 'status', 'issued_at', 'remarks']),
                'customer' => $report->customer?->name,
                'lot_no' => $report->lot?->lot_no,
                'technician' => $report->technician?->name,
                'created_by' => $report->creator?->name,
            ],
            'lines' => $report->lines->map(function (TestReportLine $line) use ($labTests): array {
                $test = $labTests->get($line->lab_test_id);

                return [
                    ...$line->only(['id', 'lab_test_id', 'result_value', 'pass_value', 'result', 'remarks']),
                    'test_code' => $test->code ?? '',
                    'test_name' => $test->name ?? '',
                    'method' => $test->method ?? '',
                    'scale' => $test->scale ?? '',
                    'unit' => $test->unit ?? '',
                ];
            })->all(),
            'availableTransitions' => $this->states->available($report),
        ]);
    }

    public function transition(Request $request, TestReport $report): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($report, $data['to']);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Report moved to {$data['to']}.");
    }

    /**
     * QL-5 AC2 — find the applicable threshold: customer-specific > house default.
     */
    private function applicableThreshold(object $labTest, ?int $customerId, ?int $productId): ?string
    {
        if ($customerId !== null) {
            $override = DB::table('customer_test_requirements')
                ->where('customer_id', $customerId)
                ->where('lab_test_id', $labTest->id)
                ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $productId))
                ->orderByRaw('product_id IS NULL ASC')
                ->value('pass_value');

            if ($override !== null) {
                return $override;
            }
        }

        return $labTest->default_pass_value;
    }

    /**
     * QL-5 AC3 — auto-compute pass/fail based on scale type.
     */
    private function computeVerdict(object $labTest, string $resultValue, ?string $passValue): string
    {
        if ($passValue === null) {
            return 'na';
        }

        return match ($labTest->scale) {
            'grey' => $this->greyScaleVerdict($resultValue, $passValue),
            'percentage' => $this->percentageVerdict($resultValue, $passValue),
            'delta_e' => $this->deltaEVerdict($resultValue, $passValue),
            'pass_fail' => strtolower(trim($resultValue)) === 'pass' ? 'pass' : 'fail',
            default => $this->numericVerdict($resultValue, $passValue),
        };
    }

    private function greyScaleVerdict(string $result, string $pass): string
    {
        return (float) $result >= (float) $pass ? 'pass' : 'fail';
    }

    private function percentageVerdict(string $result, string $pass): string
    {
        return (float) $result <= (float) $pass ? 'pass' : 'fail';
    }

    private function deltaEVerdict(string $result, string $pass): string
    {
        return (float) $result <= (float) $pass ? 'pass' : 'fail';
    }

    private function numericVerdict(string $result, string $pass): string
    {
        return (float) $result >= (float) $pass ? 'pass' : 'fail';
    }
}
