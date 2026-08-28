<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostSheetPresenter;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationConversionService;
use App\Modules\Sales\States\QuotationStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Http\ListsResources;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class QuotationController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly QuotationStateMachine $states,
        private readonly CostSheetService $costSheets,
        private readonly CostSheetCalculator $calculator,
        private readonly Settings $settings,
        private readonly QuotationConversionService $conversions,
        private readonly CostSheetPresenter $costLines,
        private readonly DocumentTrail $trail,
    ) {}

    public function index(Request $request): Response
    {
        // BR-47 — a quotation in USD sits in the same list as one in BDT, and an unlabelled
        // 3,630,453.60 beside an unlabelled 52.33 reads as corrupted data rather than as two
        // currencies. Every amount on this list says which one it is.
        $query = Quotation::query()->with(['customer:id,code,name', 'currency:id,code'])->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'quotation_date', 'valid_until', 'total', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Sales/Quotations/Index', [
            'quotations' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Quotation $quotation): array => [
                    ...$quotation->only([
                        'id', 'number', 'revision_no', 'quotation_date', 'valid_until',
                        'subtotal', 'total', 'status', 'sent_at',
                    ]),
                    'customer' => $quotation->customer?->name,
                    'currency' => $quotation->currency?->code,
                    'lines_count' => $quotation->lines_count,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $inquiry = $this->prefillInquiry($request);

        // Whether the id names a real inquiry — asked separately from whether this user may
        // *read* one. A merchandiser's assistant who may raise quotations but not browse
        // inquiries still arrives here from a legitimate `Quote it`, and the quotation must
        // still be filed against the inquiry it answers; what they must not get is its
        // contents. That distinction is deliberate and is covered by `DocumentHandoffTest`.
        $requestedId = $request->integer('inquiry') ?: null;
        $inquiryExists = $requestedId !== null
            && DB::table('inquiries')->where('id', $requestedId)->exists();

        return Inertia::render('Sales/Quotations/Form', [
            'quotation' => null,
            // The link survives even when the contents are withheld; an id that names nothing
            // does not, because it would only fail validation on save.
            'inquiryId' => $inquiry['id'] ?? ($inquiryExists ? $requestedId : null),
            // The handoff from `Quote it`. Passing only the id left the merchandiser retyping
            // the customer and every line they were looking at a second earlier.
            'inquiryPrefill' => $inquiry,
            // F-09 — when `?inquiry=` names nothing the user can open, the form used to render
            // blank and silent: safe, and baffling. It now says so. The wording is deliberately
            // the same whether the inquiry is missing, deleted or merely not theirs to read —
            // a message that distinguished them would answer "does this record exist?" for
            // someone with no permission to ask.
            'contextNotice' => $this->inquiryContextNotice($request, $inquiryExists),
            ...$this->formOptions(),
        ]);
    }

    /**
     * What to say about an `?inquiry=` that names nothing — or null when there is nothing to
     * say, because none was asked for or the one asked for is real.
     *
     * Deliberately keyed on existence rather than on whether the prefill was produced: a user
     * who may not read inquiries is on a working handoff, not a broken one, and telling them
     * the context failed would be both wrong and a way to probe which ids exist.
     *
     * @return array<string, string>|null
     */
    private function inquiryContextNotice(Request $request, bool $inquiryExists): ?array
    {
        if ($inquiryExists || ! $request->has('inquiry')) {
            return null;
        }

        // `?inquiry=` present but naming nothing: missing, zero, negative, non-numeric or
        // deleted. All the same sentence, on purpose — it never says which.
        return [
            'tone' => 'warning',
            'title' => 'That inquiry could not be opened.',
            'body' => 'The quotation below has not been linked to an inquiry and nothing has been '
                .'filled in from one. Start a quotation without an inquiry, or go back to '
                .'inquiries and quote from the one you want.',
            'action_label' => 'Back to inquiries',
            'action_href' => '/inquiries',
        ];
    }

    /**
     * The inquiry behind `?inquiry=`, shaped for the quotation form.
     *
     * Nothing here is authoritative — `store()` revalidates every field. It exists so the
     * form opens with what the system already knows, and it stays silent when the user may
     * not read inquiries or the id does not resolve.
     *
     * @return array<string, mixed>|null
     */
    private function prefillInquiry(Request $request): ?array
    {
        $id = $request->integer('inquiry') ?: null;

        if ($id === null || ! $request->user()?->hasPermission('inquiry.view_any')) {
            return null;
        }

        $inquiry = Inquiry::query()
            ->with(['customer:id,code,name,currency_id,payment_term_id', 'lines.product:id,code,name,customer_id'])
            ->find($id);

        if ($inquiry === null) {
            return null;
        }

        return [
            'id' => (int) $inquiry->id,
            'number' => $inquiry->number,
            'status' => $inquiry->status,
            'required_by' => $inquiry->required_by,
            'customer' => $inquiry->customer?->only(['id', 'code', 'name']),
            'customer_id' => $inquiry->customer_id,
            'currency_id' => $inquiry->customer?->currency_id,
            // An inquiry line names a product only once one exists; the rest are described in
            // the customer's words. Those carry across as a description with no product, which
            // is exactly what the merchandiser has to resolve before the line can be priced.
            'lines' => $inquiry->lines->map(fn ($line): array => [
                // F-05 — carried onto the quotation line, so what the customer asked for stays
                // attached to what they were quoted.
                'id' => (int) $line->id,
                'line_no' => $line->line_no,
                'product_id' => $line->product_id,
                'product_code' => $line->product?->code,
                'description' => $line->description,
                'qty' => $line->qty,
                'target_rate_per_m' => $line->target_rate_per_m,
                'notes' => $line->notes,
            ])->values()->all(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // BR-46 — the customer's payment terms follow the quote onto the order and decide the
        // invoice due date. Left unset it fell through to a hard-coded Net 30 at invoicing,
        // which billed every Net-60 customer thirty days early and looked correct on screen.
        $data['payment_term_id'] ??= DB::table('customers')
            ->where('id', $data['customer_id'])
            ->value('payment_term_id');

        $quotation = DB::transaction(function () use ($data, $request): Quotation {
            $quotation = Quotation::query()->create([
                ...collect($data)->except('lines')->all(),
                'status' => 'draft',
                'merchandiser_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($quotation, $data['lines']);

            return $quotation;
        });

        return redirect()
            ->route('quotations.show', $quotation)
            ->with('success', 'Draft quotation created with a cost sheet per line.');
    }

    /**
     * Copy a quotation into a fresh draft.
     *
     * Repeat business is the norm here — the same customer, the same labels, a new season and a
     * different quantity. Retyping eight lines to change one of them is where transcription
     * errors come from, and a wrong rate on a repeat order is money.
     *
     * The copy is deliberately *not* a snapshot of the old prices: `syncLines()` recomputes a
     * cost sheet per line from today's rates, so yarn that went up 8% since March shows up as a
     * new number rather than being quietly re-quoted at the old one (Q1).
     */
    public function duplicate(Request $request, Quotation $quotation): RedirectResponse
    {
        $quotation->load('lines');

        $copy = DB::transaction(function () use ($quotation, $request): Quotation {
            $draft = Quotation::query()->create([
                ...collect($quotation->only([
                    'customer_id', 'inquiry_id', 'currency_id', 'exchange_rate', 'payment_term_id', 'terms',
                ]))->filter(fn ($value): bool => $value !== null)->all(),
                'number' => null,
                'revision_no' => 0,
                'quotation_date' => now()->toDateString(),
                'valid_until' => now()->addDays(30)->toDateString(),
                'status' => 'draft',
                'merchandiser_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($draft, $quotation->lines->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'product_spec_id' => $line->product_spec_id,
                'description' => $line->description,
                'qty' => $line->qty,
                'rate_per_m' => $line->rate_per_m,
                'tooling_charge' => $line->tooling_charge,
                'lead_time_days' => $line->lead_time_days,
            ])->all());

            return $draft;
        });

        return redirect()
            ->route('quotations.edit', $copy)
            ->with('success', 'Copied into a new draft. Every line has been re-costed at today\'s rates.');
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $quotation->load(['customer', 'currency:id,code,name,symbol', 'lines.product:id,code,name,product_type']);

        $sheets = CostSheet::query()
            ->whereIn('quotation_line_id', $quotation->lines->pluck('id'))
            ->with('lines')
            ->get()
            ->keyBy('quotation_line_id');

        $inquiry = $quotation->inquiry_id === null ? null : DB::table('inquiries')
            ->where('id', $quotation->inquiry_id)
            ->first(['id', 'number', 'status', 'required_by']);

        $inquiryTotals = $inquiry === null ? null : DB::table('inquiry_lines')
            ->where('inquiry_id', $inquiry->id)
            // `lines` is reserved in MySQL; the alias has to be something it will parse.
            ->selectRaw('COALESCE(SUM(qty), 0) AS requested_qty, COUNT(*) AS line_count')
            ->first();

        // Only the lines this quotation actually answers, keyed for the map below.
        $inquiryLines = $quotation->lines->pluck('inquiry_line_id')->filter()->isEmpty()
            ? collect()
            : DB::table('inquiry_lines')
                ->whereIn('id', $quotation->lines->pluck('inquiry_line_id')->filter()->all())
                ->get(['id', 'line_no', 'qty', 'target_rate_per_m', 'description'])
                ->keyBy('id');

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => [
                ...$quotation->only([
                    'id', 'number', 'revision_no', 'quotation_date', 'valid_until', 'exchange_rate',
                    'subtotal', 'tax_amount', 'total', 'status', 'sent_at', 'decided_at',
                    'reject_reason', 'terms',
                ]),
                'reference' => app(\App\Support\Numbering\NumberAllocator::class)
                    ->withRevision($quotation->number, (int) $quotation->revision_no),
                // The document's currency, and the rate that ties it to the factory's books
                // (BR-22/Q1 — snapshotted, so this is what it was quoted at, not today's).
                'currency' => $quotation->currency?->only(['id', 'code', 'name', 'symbol']),
                'customer' => $quotation->customer?->only(['id', 'code', 'name', 'min_order_value']),
            ],
            'lines' => $quotation->lines->map(fn ($line): array => [
                ...$line->only(['id', 'line_no', 'description', 'qty', 'rate_per_m', 'tooling_charge', 'line_total', 'lead_time_days']),
                // F-05 — what the customer actually asked for on the line this answers.
                // Quoting something else is legitimate and is not blocked; it is shown.
                'inquiry_line' => $line->inquiry_line_id === null ? null : ($inquiryLines[$line->inquiry_line_id] ?? null),
                'product' => $line->product?->only(['id', 'code', 'name', 'product_type']),
                'cost_sheet' => $sheets->get($line->id)?->only([
                    'id', 'basis_qty', 'gross_metres', 'total_wastage_pct', 'overhead_pct',
                    'admin_pct', 'margin_pct', 'material_cost', 'tooling_cost', 'machine_cost',
                    'labour_cost', 'energy_cost', 'packing_cost', 'other_cost', 'overhead_amount',
                    'total_cost', 'unit_cost', 'rate_per_m', 'is_locked',
                ]),
                // Every sheet line carries the rule that produced it — the point of §3.4 —
                // and now also how it multiplies out. A percentage row and a rate row are not
                // the same shape, and a machine row snapshotted before the calculator was
                // corrected has its rate recovered rather than printed as zero (F-03).
                'cost_lines' => $this->costLines->lines($sheets->get($line->id)->lines ?? []),
            ]),
            'availableTransitions' => $this->states->available($quotation),
            // Both ends of the chain this quotation sits in the middle of: the inquiry it
            // answers, and the order(s) it became. Without them the only way back was search.
            'inquiry' => $inquiry === null ? null : [
                'id' => (int) $inquiry->id,
                'number' => $inquiry->number,
                'status' => $inquiry->status,
                'required_by' => $inquiry->required_by,
                // F-05 — the document-level comparison, which is all that can honestly be said
                // for a quotation raised before lines were paired. `lines_paired` says whether
                // the per-line comparison underneath is a record or an absence.
                'requested_qty' => (float) ($inquiryTotals->requested_qty ?? 0),
                'requested_lines' => (int) ($inquiryTotals->line_count ?? 0),
                'quoted_qty' => (float) $quotation->lines->sum('qty'),
                'lines_paired' => $quotation->lines->whereNotNull('inquiry_line_id')->count(),
            ],
            'orders' => DB::table('sales_orders')
                ->where('quotation_id', $quotation->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'status', 'total']),
            // Q5 — the screen asks the same object the POST handler asks, so the primary
            // action and the server's answer cannot disagree. A converted quotation offers
            // the order, not a second conversion.
            // F-01/F-02 — the document's own history. The rows were always written; no screen
            // ever asked for them, so the page ended at the total and "who sent this, and
            // when" was answerable only from the global admin log.
            'trail' => $this->trail->for($quotation, $request->user()),
            'conversion' => [
                'convertible' => $this->conversions->isConvertible($quotation),
                'refusal' => $this->conversions->refusalReason($quotation),
                'live_orders' => $this->conversions->liveOrders($quotation)
                    ->map(fn ($order): array => [
                        'id' => $order->id,
                        'number' => $order->number,
                        'status' => $order->status,
                    ])->values(),
            ],
        ]);
    }

    public function edit(Quotation $quotation): Response
    {
        if ($quotation->status !== 'draft') {
            abort(403, 'A sent quotation is immutable (Q1). Create a revision instead.');
        }

        $quotation->load('lines');

        return Inertia::render('Sales/Quotations/Form', [
            'quotation' => $quotation,
            'inquiryId' => $quotation->inquiry_id,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== 'draft') {
            return back()->with('error', 'A sent quotation is immutable (Q1). Create a revision instead.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($quotation, $data): void {
            $quotation->update(collect($data)->except('lines')->all());
            $this->syncLines($quotation, $data['lines']);
        });

        return redirect()->route('quotations.show', $quotation)->with('success', 'Quotation updated.');
    }

    public function transition(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        // Q4 — revising creates n+1 and leaves the prior revision read-only.
        if ($data['to'] === 'revised') {
            $revision = $this->revise($quotation);

            return redirect()
                ->route('quotations.show', $revision)
                ->with('success', "Revision R{$revision->revision_no} created.");
        }

        try {
            $this->states->transition($quotation, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Quotation moved to {$data['to']}.");
    }

    /**
     * Q3 / Q5 — the rules and the writing both live in QuotationConversionService, so a
     * hand-rolled POST reaches exactly the same guard the button does.
     */
    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        $data = $request->validate([
            'customer_po_no' => ['nullable', 'string', 'max:80'],
            'delivery_date' => ['nullable', 'date'],
        ]);

        $order = $this->conversions->convert($quotation, $data, $request->user());

        return redirect()
            ->route('sales-orders.show', $order)
            ->with('success', 'Sales order drafted from the quotation. Confirm it once Gate 1 is satisfied.');
    }

    private function revise(Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($quotation): Quotation {
            $revision = $quotation->replicate(['sent_at', 'decided_at', 'reject_reason']);
            $revision->revision_no = (int) $quotation->revision_no + 1;
            $revision->status = 'draft';
            $revision->save();

            foreach ($quotation->lines as $line) {
                $copy = $line->replicate();
                $copy->quotation_id = $revision->id;
                $copy->save();
            }

            $quotation->update(['status' => 'revised']);

            return $revision;
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'inquiry_id' => ['nullable', 'integer', 'exists:inquiries,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after:quotation_date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            // BR-22 — snapshotted onto the quotation, never re-read when reprinting.
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'terms' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            // F-05 — which inquiry line this line answers, when it answers one. Nullable:
            // a quotation may be raised cold, and a line may be added that the customer never
            // asked for. Validated against the inquiry named on this quotation in
            // `assertInquiryLinesBelong()`, so it cannot be pointed at someone else's inquiry.
            'lines.*.inquiry_line_id' => ['nullable', 'integer', 'exists:inquiry_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate_per_m' => ['required', 'numeric', 'min:0'],
            'lines.*.tooling_charge' => ['nullable', 'numeric', 'min:0'],
            'lines.*.margin_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    /**
     * F-05 — an inquiry line may only be answered by a quotation raised against its inquiry.
     *
     * Without this the field is an id the client chooses, and pointing it at another
     * customer's inquiry line would read that line's quantity onto this document. Ids that do
     * not belong are dropped rather than refused: the pairing is a record of provenance, not
     * something the merchandiser typed, and losing it must not lose the quotation.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function scopeInquiryLines(?int $inquiryId, array $lines): array
    {
        $permitted = $inquiryId === null
            ? []
            : DB::table('inquiry_lines')->where('inquiry_id', $inquiryId)->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();

        return array_map(function (array $line) use ($permitted): array {
            $candidate = isset($line['inquiry_line_id']) ? (int) $line['inquiry_line_id'] : null;

            $line['inquiry_line_id'] = $candidate !== null && in_array($candidate, $permitted, true)
                ? $candidate
                : null;

            return $line;
        }, $lines);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(Quotation $quotation, array $lines): void
    {
        $lines = $this->scopeInquiryLines(
            $quotation->inquiry_id === null ? null : (int) $quotation->inquiry_id,
            $lines,
        );

        CostSheet::query()->whereIn('quotation_line_id', $quotation->lines()->select('id'))->delete();
        $quotation->lines()->delete();

        $subtotal = 0.0;

        foreach ($lines as $index => $line) {
            $lineTotal = $this->calculator->lineValue((int) $line['qty'], (float) $line['rate_per_m'])
                + (float) ($line['tooling_charge'] ?? 0);
            $subtotal += $lineTotal;

            $model = $quotation->lines()->create([
                'line_no' => $index + 1,
                'inquiry_line_id' => $line['inquiry_line_id'] ?? null,
                'product_id' => $line['product_id'],
                'product_spec_id' => $line['product_spec_id'] ?? null,
                'description' => $line['description'],
                'qty' => $line['qty'],
                'rate_per_m' => $line['rate_per_m'],
                'tooling_charge' => $line['tooling_charge'] ?? 0,
                'line_total' => $lineTotal,
                'lead_time_days' => $line['lead_time_days'] ?? null,
            ]);

            $product = Product::query()->with(['customer', 'routing.operations', 'activeBom'])->find($line['product_id']);
            $spec = $line['product_spec_id'] ?? null
                ? ProductSpec::query()->find($line['product_spec_id'])
                : $product?->currentSpec;

            // The sheet is what makes the rate defensible; a line without one cannot be sent.
            if ($product !== null && $spec !== null) {
                $this->costSheets->persist(
                    $product,
                    $spec,
                    (int) $line['qty'],
                    [
                        'marginPct' => $line['margin_pct'] ?? $this->settings->decimal('default_margin_pct', 20),
                        'exchangeRate' => (float) $quotation->exchange_rate,
                    ],
                    $model->id,
                );
            }
        }

        $quotation->forceFill([
            'subtotal' => $subtotal,
            'total' => $subtotal + (float) $quotation->tax_amount,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name')
                ->get(['id', 'code', 'name', 'min_order_value', 'currency_id', 'payment_term_id']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name', 'is_base']),
            'products' => Product::query()->active()->with('currentSpec:id,product_id,version_no')
                ->orderBy('code')->get(['id', 'code', 'name', 'customer_id', 'product_type']),
            'defaultMarginPct' => $this->settings->decimal('default_margin_pct', 20),
            'marginFloorPct' => $this->settings->decimal('margin_floor_pct', 12),
        ];
    }
}
