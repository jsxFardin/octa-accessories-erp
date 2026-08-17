<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job cards. Gate 1 lives on the release button (J1) and the consumption plan is snapshotted
 * at planning so a mid-run spec revision cannot change what the floor is producing to.
 */
class JobCardController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly JobCardStateMachine $states,
        private readonly JobCardReleaseGate $gate,
        private readonly ConsumptionCalculator $consumption,
        private readonly CapacityCalculator $capacity,
        private readonly FgReceiptService $fgReceipts,
    ) {}

    public function index(Request $request): Response
    {
        $query = JobCard::query()->with(['product:id,code,name,product_type', 'factoryUnit:id,code']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'colourway'],
            filters: ['status' => 'status', 'product' => 'product_id', 'unit' => 'factory_unit_id'],
            sortable: ['number', 'due_date', 'priority', 'planned_qty', 'status'],
            defaultSort: '-id',
        );

        if ($request->query('open') === '1') {
            $query->open();
        }

        return Inertia::render('Manufacturing/JobCards/Index', [
            'jobCards' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (JobCard $card): array => [
                    ...$card->only([
                        'id', 'number', 'colourway', 'planned_qty', 'produced_qty', 'good_qty',
                        'waste_qty', 'due_date', 'priority', 'status', 'gross_metres', 'ends',
                    ]),
                    'product' => $card->product?->only(['id', 'code', 'name', 'product_type']),
                    'unit' => $card->factoryUnit?->code,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'product', 'unit', 'open']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Manufacturing/JobCards/Form', [
            'orderLines' => DB::table('sales_order_lines as sol')
                ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
                ->join('products as p', 'p.id', '=', 'sol.product_id')
                ->join('customers as c', 'c.id', '=', 'so.customer_id')
                ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
                ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
                ->orderBy('sol.promised_date')
                ->get([
                    'sol.id', 'sol.line_no', 'sol.ordered_qty', 'sol.produced_qty', 'sol.promised_date',
                    'sol.product_id', 'sol.product_spec_id', 'so.number as so_number',
                    'p.code as product_code', 'p.name as product_name', 'c.name as customer_name',
                ]),
            'units' => DB::table('factory_units')->where('is_active', true)->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Gate 1 is structural: `artwork_version_id` is NOT NULL, so a job card cannot even be
     * created without naming an approved version. This resolves it rather than asking for it.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sales_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id'],
            'factory_unit_id' => ['required', 'integer', 'exists:factory_units,id'],
            'planned_qty' => ['required', 'numeric', 'gt:0'],
            'colourway' => ['nullable', 'string', 'max:80'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $line = SalesOrderLine::query()->findOrFail($data['sales_order_line_id']);

        /** @var Product $product */
        $product = Product::query()->with(['routing.operations', 'activeBom'])->findOrFail($line->product_id);

        $approvedVersion = ArtworkVersion::query()
            ->whereIn('artwork_id', $product->artworks()->select('id'))
            ->where('status', ArtworkVersion::APPROVED)
            ->first();

        if ($approvedVersion === null) {
            throw ValidationException::withMessages([
                'sales_order_line_id' => 'Gate 1: this product has no approved artwork version. '
                    .'A job card cannot exist without one.',
            ]);
        }

        if ($product->routing === null) {
            throw ValidationException::withMessages([
                'sales_order_line_id' => 'This product has no routing, so there are no operations to schedule.',
            ]);
        }

        $jobCard = DB::transaction(function () use ($data, $line, $product, $approvedVersion, $request): JobCard {
            $spec = $product->currentSpec;

            // The snapshot (02-database-schema §3.8): these three figures follow the card,
            // not the spec, for the rest of its life.
            $plan = $this->consumption->plan(
                $spec->toCalculatorInput($product->product_type),
                (int) $data['planned_qty'],
                $product->routing->toCalculatorSteps(),
                $spec->colourWeights(),
            );

            $jobCard = JobCard::query()->create([
                'factory_unit_id' => $data['factory_unit_id'],
                'sales_order_line_id' => $line->id,
                'product_id' => $product->id,
                'product_spec_id' => $spec->id,
                'artwork_version_id' => $approvedVersion->id,
                'bom_id' => $product->activeBom?->id,
                'routing_id' => $product->routing_id,
                'colourway' => $data['colourway'] ?? null,
                'planned_qty' => $data['planned_qty'],
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 50,
                'gross_metres' => $plan->grossMetres,
                'ends' => $plan->ends,
                'labels_per_metre' => $plan->labelsPerMetre,
                'status' => JobCard::DRAFT,
                'created_by' => $request->user()->id,
            ]);

            foreach ($product->routing->operations as $operation) {
                JobCardOperation::query()->create([
                    'job_card_id' => $jobCard->id,
                    'routing_operation_id' => $operation->id,
                    'sequence_no' => $operation->sequence_no,
                    'code' => $operation->code,
                    'name' => $operation->name,
                    'machine_group_id' => $operation->machine_group_id,
                    'planned_qty' => $operation->consumes_web ? $plan->grossMetres : $data['planned_qty'],
                    'planned_minutes' => $this->capacity->loadMinutes(
                        $operation->consumes_web ? $plan->grossMetres : (float) $data['planned_qty'],
                        (float) ($operation->std_rate_per_hour ?? 0),
                        (float) $operation->setup_minutes,
                    ),
                    'requires_qc' => $operation->requires_qc,
                    'status' => JobCardOperation::PENDING,
                ]);
            }

            return $jobCard;
        });

        return redirect()
            ->route('job-cards.show', $jobCard)
            ->with('success', 'Job card created as a draft. Schedule its operations to plan it.');
    }

    public function show(JobCard $jobCard): Response
    {
        $jobCard->load([
            'product.customer', 'spec', 'artworkVersion.artwork', 'bom.lines.item', 'routing',
            'operations.machine', 'operations.machineGroup', 'operations.tool', 'operations.routingOperation',
            'salesOrderLine',
        ]);

        return Inertia::render('Manufacturing/JobCards/Show', [
            'jobCard' => [
                ...$jobCard->only([
                    'id', 'number', 'colourway', 'planned_qty', 'produced_qty', 'good_qty',
                    'waste_qty', 'overrun_tolerance_pct', 'planned_start', 'planned_finish',
                    'actual_start', 'actual_finish', 'due_date', 'priority', 'gross_metres',
                    'ends', 'labels_per_metre', 'status', 'hold_reason', 'material_waiver_reason',
                ]),
                'product' => $jobCard->product?->only(['id', 'code', 'name', 'product_type']),
                'customer' => $jobCard->product?->customer?->only(['id', 'name']),
                'spec_version' => $jobCard->spec?->version_no,
                'artwork' => [
                    'id' => $jobCard->artworkVersion?->artwork_id,
                    'code' => $jobCard->artworkVersion?->artwork?->code,
                    'version_no' => $jobCard->artworkVersion?->version_no,
                    'status' => $jobCard->artworkVersion?->status,
                    'checksum' => $jobCard->artworkVersion?->checksum_sha256,
                ],
                'overrun_ceiling' => $jobCard->overrunCeiling(),
            ],
            'operations' => $jobCard->operations->map(fn (JobCardOperation $op): array => [
                ...$op->only([
                    'id', 'sequence_no', 'code', 'name', 'planned_qty', 'input_qty', 'good_qty',
                    'waste_qty', 'planned_minutes', 'actual_minutes', 'scheduled_start',
                    'scheduled_finish', 'started_at', 'finished_at', 'requires_qc', 'status',
                ]),
                'machine' => $op->machine?->only(['id', 'code', 'name']),
                'machine_group' => $op->machineGroup?->name,
                'tool' => $op->tool?->only(['id', 'code', 'kind']),
                'predecessors_complete' => $op->predecessorsComplete(),
            ]),
            // J1 — the four checks, always visible, not only when release fails.
            'releaseGate' => $this->gate->evaluate($jobCard),
            'availableTransitions' => $this->states->available($jobCard),
            'bomRequirement' => $jobCard->bom?->lines->map(fn ($line): array => [
                'item' => $line->item?->only(['id', 'code', 'name']),
                'qty_per_base' => $line->qty_per_base,
                'required' => $jobCard->bom->scaleTo((float) $line->qty_per_base, (float) $jobCard->planned_qty),
                'formula_ref' => $line->formula_ref,
            ]) ?? [],
            'issues' => DB::table('material_issues')->where('job_card_id', $jobCard->id)
                ->orderByDesc('id')->get(['id', 'number', 'issued_on', 'status']),
            // P0-3 — produced vs received-to-FG vs available, gap stated, never smoothed over.
            'fgPosition' => $this->fgReceipts->positionFor($jobCard),
            'fgReceipts' => DB::table('fg_receipts as fr')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'fr.lot_id')
                ->where('fr.job_card_id', $jobCard->id)
                ->orderByDesc('fr.id')
                ->get(['fr.id', 'fr.number', 'fr.received_on', 'fr.qty', 'fr.grade', 'fr.status',
                    'sl.lot_no', 'sl.status as lot_status', 'sl.balance_qty']),
            'fgWarehouses' => DB::table('warehouses')
                ->where('is_active', true)->where('kind', 'finished_goods')
                ->orderBy('code')->get(['id', 'code', 'name']),
            'wasteLogs' => DB::table('waste_logs')->where('job_card_id', $jobCard->id)
                ->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function transition(Request $request, JobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'hold_reason' => ['nullable', 'string', 'max:500'],
            'material_waiver_reason' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($jobCard, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Job card {$jobCard->reference()} moved to {$data['to']}.");
    }
}
