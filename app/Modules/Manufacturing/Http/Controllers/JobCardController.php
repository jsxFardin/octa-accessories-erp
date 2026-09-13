<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\Services\JobCardPlanningGuard;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use App\Modules\Manufacturing\Services\OperationBookingService;
use App\Modules\Manufacturing\Services\ProductionCorrectionService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\States\TransitionDenied;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Job cards. Gate 1 lives on the release button (J1) and the consumption plan is snapshotted
 * at planning so a mid-run spec revision cannot change what the floor is producing to.
 */
class JobCardController extends Controller
{
    use ContextualId;
    use ListsResources;

    public function __construct(
        private readonly JobCardStateMachine $states,
        private readonly JobCardReleaseGate $gate,
        private readonly ConsumptionCalculator $consumption,
        private readonly CapacityCalculator $capacity,
        private readonly FgReceiptService $fgReceipts,
        private readonly JobCardPlanningGuard $planning,
        private readonly ProductionCorrectionService $corrections,
        private readonly OperationBookingService $bookings,
    ) {}

    public function index(Request $request): Response
    {
        // J6 — the list prints the job's output, and the job's output is the final
        // operation's. `job_cards.good_qty_running` is a running total across operations that
        // share a unit: 407 m woven + 30,050 pcs folded + 30,000 pcs packed once read as
        // "60,457 good" against a plan of 30,000 for a job that made exactly 30,000 labels.
        $query = JobCard::query()
            ->withFinalOutput()
            ->with(['product:id,code,name,product_type', 'factoryUnit:id,code']);

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
                function (JobCard $card): array {
                    $output = $card->reportedOutput();

                    return [
                        ...$card->only([
                            'id', 'number', 'colourway', 'planned_qty',
                            'due_date', 'priority', 'status', 'gross_metres', 'ends',
                        ]),
                        'good_qty' => $output['good'],
                        'waste_qty' => $output['waste'],
                        'produced_qty' => $output['produced'],
                        'product' => $card->product?->only(['id', 'code', 'name', 'product_type']),
                        'unit' => $card->factoryUnit?->code,
                    ];
                },
            ),
            'filters' => $this->listingFilters($request, ['status', 'product', 'unit', 'open']),
        ]);
    }

    /**
     * `?sales_order=` / `?sales_order_line=` carry the context from the order the planner was
     * just looking at. The full list of eligible lines is still offered — planning genuinely
     * raises cards against other orders from here — but the one that was asked for is chosen
     * and sorted to the top rather than searched for again.
     */
    public function create(Request $request): Response
    {
        $lines = DB::table('sales_order_lines as sol')
            ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->join('products as p', 'p.id', '=', 'sol.product_id')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
            ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
            ->orderBy('sol.promised_date')
            ->get([
                'sol.id', 'sol.line_no', 'sol.ordered_qty', 'sol.produced_qty', 'sol.promised_date',
                'sol.product_id', 'sol.product_spec_id', 'sol.sales_order_id',
                'sol.over_tolerance_pct', 'sol.under_tolerance_pct',
                'so.number as so_number', 'p.code as product_code', 'p.name as product_name',
                'c.name as customer_name',
            ]);

        // BR-49 — every eligible line carries the quantity it can still take, so the form can
        // cap the input and say why instead of letting the planner discover it on submit.
        // The figures are the guard's own, not a second calculation that could drift from it.
        $capacities = $this->planning->capacities($lines);

        $lines = $lines->map(function (object $line) use ($capacities): object {
            $line->capacity = $capacities[(int) $line->id] ?? null;

            return $line;
        });

        $orderId = $this->contextualId($request, 'sales_order', ['sales_order.view_any', 'sales_order.view']);
        $lineId = $this->contextualId($request, 'sales_order_line', ['sales_order.view_any', 'sales_order.view']);

        // A line id that names an order the planner did not ask for is still honoured; an id
        // that is not on the eligible list at all is not, because nothing can be done with it.
        $preselect = $lineId !== null && $lines->contains(fn ($line): bool => (int) $line->id === $lineId)
            ? $lineId
            : null;

        $fromOrder = $lines->filter(
            fn ($line): bool => $orderId !== null && (int) $line->sales_order_id === $orderId,
        );

        if ($preselect === null && $fromOrder->count() === 1) {
            $preselect = (int) $fromOrder->first()->id;
        }

        return Inertia::render('Manufacturing/JobCards/Form', [
            // The asked-for order's lines first: on a factory with fifty open lines the one the
            // planner came in for was below the fold.
            'orderLines' => $orderId === null
                ? $lines->values()
                : $fromOrder->concat($lines->reject(
                    fn ($line): bool => (int) $line->sales_order_id === $orderId,
                ))->values(),
            'units' => DB::table('factory_units')->where('is_active', true)->get(['id', 'code', 'name']),
            'preselectLineId' => $preselect,
            'context' => $orderId === null ? null : $this->orderContext($orderId, $fromOrder->count()),
            // More than one card per line is legitimate — a line is often split across
            // colourways or runs (BR-28) — so this is stated rather than blocked. Raising a
            // second card by accident, because the first was invisible here, is not.
            'existingCards' => $preselect === null ? [] : DB::table('job_cards')
                ->where('sales_order_line_id', $preselect)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('id')
                ->get(['id', 'number', 'status', 'planned_qty', 'colourway']),
        ]);
    }

    /**
     * What the planner arrived from, so the form can say so instead of silently ticking a row.
     *
     * @return array<string, mixed>|null
     */
    private function orderContext(int $orderId, int $eligibleLines): ?array
    {
        $order = DB::table('sales_orders as so')
            ->join('customers as c', 'c.id', '=', 'so.customer_id')
            ->where('so.id', $orderId)
            ->first(['so.id', 'so.number', 'so.status', 'so.delivery_date', 'c.name as customer_name']);

        if ($order === null) {
            return null;
        }

        return [
            'id' => (int) $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'customer_name' => $order->customer_name,
            'delivery_date' => $order->delivery_date,
            'eligible_lines' => $eligibleLines,
        ];
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
            // BR-49 — the ceiling the form draws is also the one the server holds. A POST that
            // never saw the form gets the same refusal, and a form whose outstanding figure
            // went stale while it sat open is corrected here rather than trusted.
            //
            // Decided under a lock on the order line, inside the transaction that writes the
            // card. The check used to run before the transaction against an unlocked
            // `SUM(planned_qty)`, so two planners submitting at once both read the same
            // headroom, both passed, and both inserted — the order ended up over-committed by
            // the exact rule that exists to prevent it, and neither request did anything
            // wrong. The lock is taken on `sales_order_lines` because that row is what the
            // aggregate is grouped by: every competing card for this line serialises on it.
            $locked = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->id);

            $this->planning->assert($locked, (float) $data['planned_qty']);

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
            ->with('success', 'Job card created as a draft. Schedule its operations on the planning board to plan it.');
    }

    public function show(JobCard $jobCard): Response
    {
        $jobCard->load([
            'product.customer', 'spec', 'artworkVersion.artwork', 'bom.lines.item', 'routing',
            'operations.machine', 'operations.machineGroup', 'operations.tool', 'operations.routingOperation',
            'salesOrderLine.salesOrder:id,number,status',
        ]);

        // P0-2 — output is the final operation's, in pieces. The row's own running totals add
        // metres to pieces across operations and read as a wild overrun on a job that made
        // exactly what was planned.
        $output = $jobCard->finalOperationOutput();

        return Inertia::render('Manufacturing/JobCards/Show', [
            'jobCard' => [
                ...$jobCard->only([
                    'id', 'number', 'colourway', 'planned_qty',
                    'overrun_tolerance_pct', 'planned_start', 'planned_finish',
                    'actual_start', 'actual_finish', 'due_date', 'priority', 'gross_metres',
                    'ends', 'labels_per_metre', 'status', 'hold_reason', 'material_waiver_reason',
                ]),
                'good_qty' => $output['good'],
                'waste_qty' => $output['waste'],
                'produced_qty' => $output['produced'],
                // A boolean, never the figure. `produced_qty_running` sums metres and pieces
                // across operations, so as a quantity it is nonsense — 407 m + 30,050 pcs +
                // 30,000 pcs reading as "60,457 good" is the misreading J6 exists to prevent,
                // and `JobCardOutputReportingTest` asserts the column reaches no screen,
                // export or report. The one honest question it answers is "has anything been
                // booked at all", which is exactly what `guardCancelled()` asks — so the
                // screen that offers cancelling gets the answer, not the number.
                'has_booked_production' => (float) $jobCard->produced_qty_running > 0,
                'product' => $jobCard->product?->only(['id', 'code', 'name', 'product_type']),
                'customer' => $jobCard->product?->customer?->only(['id', 'name']),
                // Where this card sits in the order it is making — the way back up the chain.
                'sales_order' => $jobCard->salesOrderLine?->salesOrder?->only(['id', 'number', 'status']),
                'sales_order_line_no' => $jobCard->salesOrderLine?->line_no,
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
                // Metres or pieces (`consumes_web`). Without it the operations table reads as
                // one column of comparable numbers, which is exactly the misreading that made
                // 407 look like a catastrophic shortfall against 30,000.
                'unit' => $op->unit(),
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
                    // The lot's id as well as its number: the strip named the lot in plain
                    // text, so the one screen that knows which lot this job made offered no
                    // way to open it.
                    'sl.id as lot_id', 'sl.lot_no', 'sl.status as lot_status', 'sl.balance_qty']),
            'fgWarehouses' => DB::table('warehouses')
                ->where('is_active', true)->where('kind', 'finished_goods')
                ->orderBy('code')->get(['id', 'code', 'name']),
            // G4 — waste with its cause. This was fetched on every page load and rendered
            // nowhere, against a table nothing wrote to, so the query returned an empty set
            // that no one would have missed.
            'wasteLogs' => DB::table('waste_logs as wl')
                ->leftJoin('job_card_operations as jco', 'jco.id', '=', 'wl.job_card_operation_id')
                ->leftJoin('employees as e', 'e.id', '=', 'wl.reported_by')
                ->leftJoin('uoms as u', 'u.id', '=', 'wl.uom_id')
                ->leftJoin('stock_lots as sl', 'sl.id', '=', 'wl.lot_id')
                ->where('wl.job_card_id', $jobCard->id)
                ->orderByDesc('wl.id')
                ->limit(50)
                ->get([
                    'wl.id', 'wl.waste_type', 'wl.qty', 'wl.occurred_at', 'wl.remarks',
                    'jco.sequence_no', 'jco.name as operation',
                    'e.name as reported_by', 'u.code as uom', 'sl.lot_no',
                ]),
            'ncrs' => DB::table('ncrs')->where('job_card_id', $jobCard->id)
                ->orderByDesc('id')->get(['id', 'number', 'status', 'severity', 'raised_on']),
            /*
             * The shift bookings behind the operation totals.
             *
             * `operation_logs` was written once by the terminal and then read by one report
             * and nothing else — not listed on the card the production belongs to, so a
             * supervisor could see that a step held 5,000 and had no way to see which shift
             * booked it, who booked it, or that it was one mis-keyed entry rather than a
             * week's work.
             */
            'operationLogs' => DB::table('operation_logs as ol')
                ->join('job_card_operations as jco', 'jco.id', '=', 'ol.job_card_operation_id')
                ->leftJoin('employees as e', 'e.id', '=', 'ol.operator_id')
                ->leftJoin('machines as m', 'm.id', '=', 'ol.machine_id')
                ->leftJoin('shifts as sh', 'sh.id', '=', 'ol.shift_id')
                ->leftJoin('operation_logs as rev', 'rev.reverses_log_id', '=', 'ol.id')
                // Who keyed it, for the rows that were keyed. A different question from who
                // ran the work, and the reason the desk badge means anything.
                ->leftJoin('users as u', 'u.id', '=', 'ol.created_by')
                ->where('jco.job_card_id', $jobCard->id)
                ->orderByDesc('ol.id')
                ->limit(100)
                ->get([
                    'ol.id', 'ol.input_qty', 'ol.good_qty', 'ol.waste_qty', 'ol.started_at', 'ol.ended_at',
                    'ol.remarks', 'ol.reverses_log_id', 'ol.reversal_reason',
                    'jco.id as operation_id', 'jco.sequence_no', 'jco.name as operation',
                    'e.name as operator', 'm.code as machine', 'sh.name as shift',
                    'ol.manual_reason', 'ol.created_at', 'u.name as entered_by',
                    DB::raw('rev.id IS NOT NULL AS is_reversed'),
                ]),
            // What the manual booking form needs. Operators are the people the work belongs
            // to, not whoever is typing — a booking keyed by a supervisor for Monday's night
            // shift still belongs to the operator who ran it.
            'operators' => DB::table('employees')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'card_no']),
            'shifts' => DB::table('shifts')->orderBy('code')->get(['id', 'code', 'name']),
            'machines' => DB::table('machines')
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    /**
     * Booking output from the desk.
     *
     * The four device endpoints are the only way production reached the system, and they need
     * a badge and a PIN rather than a permission — so a signed-in supervisor, or an admin,
     * could not record a figure at all. That default is right: output is the shop-floor truth,
     * and a desk form invites yesterday's numbers being typed off paper, which is the habit G1
     * exists to end. It is not a workable *only* option — a kiosk that dies mid-shift stranded
     * that shift's output with no route in, and the tablet is the thing on a factory floor
     * guaranteed to break on the day it matters.
     *
     * So this is the second door, and deliberately a narrower one:
     *
     *  - the same J7/J3/J5/QC1 guards, because they live in `OperationBookingService` and not
     *    in either caller;
     *  - `occurred_at` is required, not defaulted to now — a booking keyed on Tuesday for
     *    Monday's shift has to say which shift it belongs to or the utilisation figures lie;
     *  - the operator is named, because the work belongs to whoever did it rather than to
     *    whoever typed it;
     *  - a reason is required, and it stays on the row. An exception that cannot be told apart
     *    from the norm stops being one.
     */
    public function bookOutput(Request $request, JobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'job_card_operation_id' => ['required', 'integer', 'exists:job_card_operations,id'],
            'good_qty' => ['required', 'numeric', 'min:0'],
            'waste_qty' => ['numeric', 'min:0'],
            'input_qty' => ['nullable', 'numeric', 'min:0'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'operator_id' => ['required', 'integer', 'exists:employees,id'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'input_override_reason' => ['nullable', 'string', 'max:255'],
            'waste_type' => ['nullable', Rule::in(OperationBookingService::WASTE_TYPES)],
            // Not defaulted to now. The whole reason this route exists is that the booking is
            // happening after the fact, so when it happened is the one thing it must state.
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'manual_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        if ((float) ($data['waste_qty'] ?? 0) > 0 && blank($data['waste_type'] ?? null)) {
            throw ValidationException::withMessages([
                'waste_type' => 'Waste needs a cause: setup, shade, a print or weave defect, cutting, edge trim, damage, expiry, or other.',
            ]);
        }

        $operation = JobCardOperation::query()
            ->with(['jobCard', 'routingOperation'])
            ->where('job_card_id', $jobCard->id)
            ->findOrFail($data['job_card_operation_id']);

        $this->bookings->book(
            $operation,
            $data,
            CarbonImmutable::parse($data['occurred_at']),
            operatorId: (int) $data['operator_id'],
            enteredBy: $request->user()?->id,
            manualReason: (string) $data['manual_reason'],
        );

        return back()->with('success', "Output booked against {$operation->name}.");
    }

    /**
     * I1, applied to production: a correction is a reversing entry, never an edit.
     *
     * Nothing in the application could undo a shift booking. `operation_logs` was write-once,
     * `job_card_operations.good_qty` only accumulated, and `sales_order_lines.produced_qty`
     * was incremented by the final operation and decremented by nothing — so a mis-keyed
     * 5,000 stayed on the order line's fulfilment position for the life of the order.
     */
    public function reverseLog(Request $request, JobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'operation_log_id' => ['required', 'integer', 'exists:operation_logs,id'],
            // A correction without a stated reason is indistinguishable from tampering.
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $log = DB::table('operation_logs')->where('id', $data['operation_log_id'])->firstOrFail();

        $operation = JobCardOperation::query()
            ->with('jobCard')
            ->where('job_card_id', $jobCard->id)
            ->findOrFail($log->job_card_operation_id);

        $this->corrections->reverse($operation, $log, (string) $data['reason'], $request->user()?->id);

        return back()->with('success', "Booking #{$log->id} reversed.");
    }

    public function transition(Request $request, JobCard $jobCard): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'hold_reason' => ['nullable', 'string', 'max:500'],
            'material_waiver_reason' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
            // P0-3 — why output is being left unreceived on a card that is about to become
            // terminal. Kept on the transition's audit row by `auditContext()`.
            'unreceived_output_reason' => ['nullable', 'string', 'max:500'],
            // P0-3 — and why a closed one is being opened again.
            'reopen_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($jobCard, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Job card {$jobCard->reference()} moved to {$data['to']}.");
    }
}
