<?php

declare(strict_types=1);

namespace App\Modules\Sampling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sampling\Models\SampleApproval;
use App\Modules\Sampling\Models\SampleDispatch;
use App\Modules\Sampling\Models\SampleRequest;
use App\Modules\Sampling\Models\SampleRequestLine;
use App\Modules\Sampling\Services\SampleProduction;
use App\Modules\Sampling\States\SampleRequestStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use App\Support\Text\Plain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Samples (AS-5 … AS-7): what a merchandiser asks the sample room to make for a customer's
 * approval, the run that makes it, the parcel it leaves in, and the customer's verdict per line.
 */
class SampleRequestController extends Controller
{
    use ContextualId;
    use ListsResources;

    public function __construct(
        private readonly SampleRequestStateMachine $states,
        private readonly NumberAllocator $numbers,
        private readonly DocumentTrail $trail,
        private readonly SampleProduction $production,
    ) {}

    public function index(Request $request): Response
    {
        $query = SampleRequest::query()
            ->with(['customer:id,code,name', 'creator:id,name'])
            ->withCount('lines')
            // AS-6 AC2 — the tracking number sits on the follow-up list, not two clicks away.
            ->addSelect([
                'tracking_no' => SampleDispatch::query()->select('tracking_no')
                    ->whereColumn('sample_request_id', 'sample_requests.id')->orderByDesc('id')->limit(1),
                'dispatched_on' => SampleDispatch::query()->select('dispatched_on')
                    ->whereColumn('sample_request_id', 'sample_requests.id')->orderByDesc('id')->limit(1),
                'decided_lines' => SampleRequestLine::query()->selectRaw('COUNT(*)')
                    ->whereColumn('sample_request_id', 'sample_requests.id')
                    ->whereIn('status', [SampleRequestLine::APPROVED, SampleRequestLine::REJECTED]),
            ]);

        $term = trim((string) $request->string('q'));

        if ($term !== '') {
            $query->where(fn ($where) => $where
                ->where('number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")));
        }

        $this->applyListing(
            $query,
            $request,
            filters: ['status' => 'status', 'type' => 'sample_type', 'customer' => 'customer_id'],
            sortable: ['number', 'requested_on', 'required_by', 'status', 'sample_type'],
            defaultSort: '-id',
        );

        $today = now()->startOfDay();

        return Inertia::render('Sales/SampleRequests/Index', [
            'requests' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SampleRequest $row): array => [
                    ...$row->only(['id', 'number', 'sample_type', 'requested_on', 'required_by', 'status', 'is_chargeable', 'charge_amount']),
                    'customer' => $row->customer?->name,
                    'raised_by' => $row->creator?->name,
                    'lines_count' => $row->lines_count,
                    'decided_lines' => (int) $row->getAttribute('decided_lines'),
                    'tracking_no' => $row->getAttribute('tracking_no'),
                    'days_waiting' => $row->status === SampleRequest::DISPATCHED && $row->getAttribute('dispatched_on')
                        ? (int) \Illuminate\Support\Carbon::parse($row->getAttribute('dispatched_on'))->startOfDay()->diffInDays($today)
                        : null,
                    'overdue' => $row->required_by !== null
                        && $row->required_by->lt($today)
                        && in_array($row->status, [SampleRequest::REQUESTED, SampleRequest::IN_DEVELOPMENT, SampleRequest::IN_PRODUCTION, SampleRequest::READY], true),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'type', 'customer']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'types' => SampleRequest::TYPES,
            'counts' => SampleRequest::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        $customers = Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']);

        $inquiryId = $this->contextualId($request, 'inquiry', ['inquiry.view_any', 'inquiry.view']);
        $orderId = $this->contextualId($request, 'sales_order', ['sales_order.view_any', 'sales_order.view']);
        $customerId = $this->contextualId($request, 'customer', ['customer.view_any', 'customer.view']);

        // Arriving from an inquiry or an order names the customer too.
        $inquiry = $inquiryId ? Inquiry::query()->find($inquiryId) : null;
        $order = $orderId ? SalesOrder::query()->find($orderId) : null;
        $preselect = $inquiry->customer_id ?? $order->customer_id ?? $customerId;

        return Inertia::render('Sales/SampleRequests/Form', [
            'sampleRequest' => null,
            'customers' => $customers,
            'preselect' => [
                'customer_id' => $customers->contains('id', $preselect) ? $preselect : null,
                'inquiry_id' => $inquiry?->id,
                'sales_order_id' => $order?->id,
            ],
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $sampleRequest = DB::transaction(function () use ($data, $request): SampleRequest {
            $sampleRequest = SampleRequest::query()->create([
                ...collect($data)->except('lines')->all(),
                // Requested is the first real status, so the number is given now (BR-34 has
                // no draft to wait through here).
                'number' => $this->numbers->next('sample_request'),
                'status' => SampleRequest::REQUESTED,
                'merchandiser_id' => DB::table('employees')->where('user_id', $request->user()->id)->value('id'),
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($sampleRequest, $data['lines']);

            return $sampleRequest;
        });

        return redirect()
            ->route('sample-requests.show', $sampleRequest)
            ->with('success', "Sample request {$sampleRequest->number} raised. Raise a job card on each line the sample room has to make.");
    }

    public function show(Request $request, SampleRequest $sampleRequest): Response
    {
        $sampleRequest->load([
            'customer:id,code,name', 'inquiry:id,number,status', 'salesOrder:id,number,status', 'creator:id,name',
            'lines.product.item:id,code,name', 'lines.artworkVersion:id,artwork_id,version_no,status',
            'lines.spec:id,product_id,version_no,status', 'lines.approvals.recorder:id,name', 'lines.jobCards:id,number,status,sample_request_line_id,planned_qty',
            'dispatches',
        ]);

        return Inertia::render('Sales/SampleRequests/Show', [
            'sampleRequest' => [
                ...$sampleRequest->only(['id', 'number', 'sample_type', 'requested_on', 'required_by', 'is_chargeable', 'charge_amount', 'status', 'notes']),
                'customer' => $sampleRequest->customer?->only(['id', 'code', 'name']),
                'inquiry' => $sampleRequest->inquiry?->only(['id', 'number', 'status']),
                'sales_order' => $sampleRequest->salesOrder?->only(['id', 'number', 'status']),
                'raised_by' => $sampleRequest->creator?->name,
                // AS-5 AC3 — a charge captured here is invoiced later; an approved chargeable
                // request with no invoice is the revenue leak the catalogue report is about.
                'charge_invoiced' => false,
            ],
            'lines' => $sampleRequest->lines->map(fn (SampleRequestLine $line): array => [
                ...$line->only(['id', 'line_no', 'description', 'qty', 'colourway', 'status', 'product_id']),
                'product' => $line->product ? ['id' => $line->product->id, 'code' => $line->product->code, 'name' => $line->product->name] : null,
                'artwork_version' => $line->artworkVersion?->only(['id', 'version_no', 'status']),
                'spec' => $line->spec?->only(['id', 'version_no', 'status']),
                'job_cards' => $line->jobCards->map(fn ($card) => $card->only(['id', 'number', 'status', 'planned_qty']))->values(),
                'decisions' => $line->approvals->map(fn (SampleApproval $approval): array => [
                    ...$approval->only(['id', 'decision', 'decided_on', 'customer_ref', 'comments']),
                    'recorded_by' => $approval->recorder?->name,
                ])->values(),
            ]),
            'dispatches' => $sampleRequest->dispatches->map(fn (SampleDispatch $dispatch) => $dispatch->only(['id', 'dispatched_on', 'courier_name', 'tracking_no', 'recipient', 'delivered_on', 'remarks'])),
            'factoryUnits' => FactoryUnit::query()->orderBy('name')->get(['id', 'code', 'name']),
            'decisions' => SampleApproval::DECISIONS,
            'availableTransitions' => $this->states->available($sampleRequest),
            'trail' => $this->trail->for($sampleRequest, $request->user()),
        ]);
    }

    public function edit(SampleRequest $sampleRequest): Response
    {
        if (! in_array($sampleRequest->status, SampleRequest::EDITABLE, true)) {
            abort(403, 'Once production has started, the request is what the sample room is making; raise another for a change.');
        }

        $sampleRequest->load('lines');

        return Inertia::render('Sales/SampleRequests/Form', [
            'sampleRequest' => [
                ...$sampleRequest->only(['id', 'number', 'customer_id', 'inquiry_id', 'sales_order_id', 'sample_type', 'requested_on', 'required_by', 'is_chargeable', 'charge_amount', 'notes', 'status']),
                'lines' => $sampleRequest->lines->map(fn (SampleRequestLine $line): array => $line->only(['id', 'line_no', 'product_id', 'product_spec_id', 'artwork_version_id', 'description', 'qty', 'colourway']))->all(),
            ],
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'preselect' => ['customer_id' => null, 'inquiry_id' => null, 'sales_order_id' => null],
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, SampleRequest $sampleRequest): RedirectResponse
    {
        if (! in_array($sampleRequest->status, SampleRequest::EDITABLE, true)) {
            return back()->with('error', 'Once production has started, the request is what the sample room is making; raise another for a change.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($sampleRequest, $data): void {
            $sampleRequest->update(collect($data)->except('lines')->all());
            $this->syncLines($sampleRequest, $data['lines']);
        });

        return redirect()->route('sample-requests.show', $sampleRequest)->with('success', 'Sample request updated.');
    }

    public function transition(Request $request, SampleRequest $sampleRequest): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([SampleRequest::IN_DEVELOPMENT, SampleRequest::IN_PRODUCTION, SampleRequest::READY, SampleRequest::CANCELLED])],
            'reason' => ['nullable', 'string', 'max:255', 'required_if:status,cancelled'],
        ]);

        try {
            $this->states->transition($sampleRequest, $data['status'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sample request is now '.Plain::status($data['status']).'.');
    }

    /** AS-5 AC4 — the sample run, from the line. */
    public function raiseJobCard(Request $request, SampleRequest $sampleRequest, SampleRequestLine $line): RedirectResponse
    {
        $this->assertLineBelongs($sampleRequest, $line);

        $data = $request->validate([
            'factory_unit_id' => ['required', 'integer', 'exists:factory_units,id'],
            'planned_qty' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        if (in_array($sampleRequest->status, [SampleRequest::DISPATCHED, SampleRequest::APPROVED, SampleRequest::REJECTED, SampleRequest::CANCELLED], true)) {
            return back()->with('error', 'This request is '.Plain::status($sampleRequest->status).'; nothing more is made for it.');
        }

        $jobCard = $this->production->raiseJobCard($line, $data, $request->user());

        return redirect()
            ->route('job-cards.show', $jobCard)
            ->with('success', "Sample job card raised for line {$line->line_no} of {$sampleRequest->number}. Schedule its operations to plan it.");
    }

    /** AS-6 — the parcel: courier, tracking number and recipient; the status follows. */
    public function dispatch(Request $request, SampleRequest $sampleRequest): RedirectResponse
    {
        $data = $request->validate([
            'dispatched_on' => ['required', 'date'],
            'courier_name' => ['required', 'string', 'max:80'],
            'tracking_no' => ['nullable', 'string', 'max:80'],
            'recipient' => ['required', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        if ($sampleRequest->status !== SampleRequest::READY) {
            return back()->with('error', 'Only a ready request is dispatched; mark it ready once the pieces exist.');
        }

        try {
            DB::transaction(function () use ($sampleRequest, $data): void {
                $sampleRequest->dispatches()->create($data);
                $sampleRequest->lines()
                    ->whereIn('status', [SampleRequestLine::PENDING, SampleRequestLine::PRODUCED])
                    ->update(['status' => SampleRequestLine::DISPATCHED]);
                $this->states->transition($sampleRequest, SampleRequest::DISPATCHED, $data);
            });
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dispatched. The tracking number is on the samples list for follow-up.');
    }

    /** AS-6 AC3 — when the courier says it arrived. */
    public function delivered(Request $request, SampleRequest $sampleRequest, SampleDispatch $dispatch): RedirectResponse
    {
        if ($dispatch->sample_request_id !== $sampleRequest->id) {
            abort(404);
        }

        $data = $request->validate(['delivered_on' => ['required', 'date', 'after_or_equal:'.$dispatch->dispatched_on->toDateString()]]);

        $dispatch->update($data);

        return back()->with('success', 'Delivery date recorded.');
    }

    /** AS-7 — the customer's decision, per line. */
    public function decide(Request $request, SampleRequest $sampleRequest, SampleRequestLine $line): RedirectResponse
    {
        $this->assertLineBelongs($sampleRequest, $line);

        $data = $request->validate([
            'decision' => ['required', Rule::in(SampleApproval::DECISIONS)],
            'decided_on' => ['required', 'date'],
            'customer_ref' => ['nullable', 'string', 'max:180'],
            // AC2 — a qualified approval and a rejection both say why.
            'comments' => ['nullable', 'string', 'max:2000', 'required_unless:decision,approved'],
        ], [
            'comments.required_unless' => 'Say what the customer said: their comments are what the next sample or the bulk run is corrected from.',
        ]);

        if ($sampleRequest->status !== SampleRequest::DISPATCHED) {
            return back()->with('error', 'A decision is recorded once the samples have been dispatched to the customer.');
        }

        if (in_array($line->status, [SampleRequestLine::APPROVED, SampleRequestLine::REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => "Line {$line->line_no} is already ".Plain::status($line->status).'.']);
        }

        DB::transaction(function () use ($sampleRequest, $line, $data, $request): void {
            $line->approvals()->create([...$data, 'recorded_by' => $request->user()->id]);
            $line->forceFill(['status' => $data['decision'] === 'rejected' ? SampleRequestLine::REJECTED : SampleRequestLine::APPROVED])->save();

            $undecided = $sampleRequest->lines()->whereNotIn('status', [SampleRequestLine::APPROVED, SampleRequestLine::REJECTED])->exists();

            if (! $undecided) {
                $verdict = $sampleRequest->lines()->where('status', SampleRequestLine::REJECTED)->exists()
                    ? SampleRequest::REJECTED
                    : SampleRequest::APPROVED;

                StateMachine::asSystem(fn () => $this->states->transition($sampleRequest, $verdict, ['because' => 'every line decided']));
            }
        });

        return back()->with('success', "Line {$line->line_no} recorded as ".Plain::status($data['decision']).'.');
    }

    private function assertLineBelongs(SampleRequest $sampleRequest, SampleRequestLine $line): void
    {
        if ($line->sample_request_id !== $sampleRequest->id) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'types' => SampleRequest::TYPES,
            'inquiries' => Inquiry::query()->whereIn('status', ['open', 'quoted', 'won'])->orderByDesc('id')->limit(300)->get(['id', 'number', 'customer_id', 'status']),
            'salesOrders' => SalesOrder::query()->whereNotIn('status', ['cancelled', 'closed'])->orderByDesc('id')->limit(300)->get(['id', 'number', 'customer_id', 'status']),
            'products' => Product::query()->active()->with('item:id,code,name')->get(['id', 'item_id', 'customer_id', 'product_type'])
                ->map(fn (Product $product): array => ['id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'customer_id' => $product->customer_id, 'product_type' => $product->product_type])
                ->sortBy('code')->values(),
            // AS-5 AC2 — a line names the version it samples; any version but a superseded one,
            // since the sample is usually how a version gets approved.
            'artworkVersions' => DB::table('artwork_versions as v')
                ->join('artworks as a', 'a.id', '=', 'v.artwork_id')
                ->whereNotIn('v.status', ['superseded', 'rejected'])
                ->orderBy('a.product_id')->orderByDesc('v.version_no')
                ->get(['v.id', 'a.product_id', 'v.version_no', 'v.status', 'a.title']),
            'specs' => DB::table('product_specs')->whereIn('status', ['current', 'draft', 'approved'])
                ->orderBy('product_id')->orderByDesc('version_no')
                ->get(['id', 'product_id', 'version_no', 'status']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'inquiry_id' => ['nullable', 'integer', Rule::exists('inquiries', 'id')->where('customer_id', $request->integer('customer_id'))],
            'sales_order_id' => ['nullable', 'integer', Rule::exists('sales_orders', 'id')->where('customer_id', $request->integer('customer_id'))],
            'sample_type' => ['required', Rule::in(SampleRequest::TYPES)],
            'requested_on' => ['required', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:requested_on'],
            'is_chargeable' => ['boolean'],
            'charge_amount' => ['nullable', 'numeric', 'min:0', 'required_if:is_chargeable,true'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'lines.*.artwork_version_id' => ['nullable', 'integer', 'exists:artwork_versions,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.colourway' => ['nullable', 'string', 'max:80'],
        ], [
            'lines.required' => 'Add at least one line.',
            'lines.min' => 'Add at least one line.',
            'charge_amount.required_if' => 'A chargeable sample needs the amount that will be invoiced.',
            'inquiry_id.exists' => 'That inquiry belongs to another customer.',
            'sales_order_id.exists' => 'That order belongs to another customer.',
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(SampleRequest $sampleRequest, array $lines): void
    {
        // Only a requested or in-development request is edited, and neither has a job card or
        // a decision on any line yet, so the lines are simply rewritten.
        $sampleRequest->lines()->delete();

        foreach ($lines as $index => $line) {
            $sampleRequest->lines()->create([
                'line_no' => $index + 1,
                'product_id' => $line['product_id'] ?? null,
                'product_spec_id' => $line['product_spec_id'] ?? null,
                'artwork_version_id' => $line['artwork_version_id'] ?? null,
                'description' => $line['description'],
                'qty' => $line['qty'],
                'colourway' => $line['colourway'] ?? null,
                'status' => SampleRequestLine::PENDING,
            ]);
        }
    }
}
