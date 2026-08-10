<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\QuotationStateMachine;
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
    ) {}

    public function index(Request $request): Response
    {
        $query = Quotation::query()->with(['customer:id,code,name'])->withCount('lines');

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
                    'lines_count' => $quotation->lines_count,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/Quotations/Form', [
            'quotation' => null,
            'inquiryId' => $request->integer('inquiry') ?: null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

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

    public function show(Quotation $quotation): Response
    {
        $quotation->load(['customer', 'lines.product:id,code,name,product_type']);

        $sheets = CostSheet::query()
            ->whereIn('quotation_line_id', $quotation->lines->pluck('id'))
            ->with('lines')
            ->get()
            ->keyBy('quotation_line_id');

        return Inertia::render('Sales/Quotations/Show', [
            'quotation' => [
                ...$quotation->only([
                    'id', 'number', 'revision_no', 'quotation_date', 'valid_until', 'exchange_rate',
                    'subtotal', 'tax_amount', 'total', 'status', 'sent_at', 'decided_at',
                    'reject_reason', 'terms',
                ]),
                'reference' => app(\App\Support\Numbering\NumberAllocator::class)
                    ->withRevision($quotation->number, (int) $quotation->revision_no),
                'customer' => $quotation->customer?->only(['id', 'code', 'name', 'min_order_value']),
            ],
            'lines' => $quotation->lines->map(fn ($line): array => [
                ...$line->only(['id', 'line_no', 'description', 'qty', 'rate_per_m', 'tooling_charge', 'line_total', 'lead_time_days']),
                'product' => $line->product?->only(['id', 'code', 'name', 'product_type']),
                'cost_sheet' => $sheets->get($line->id)?->only([
                    'id', 'basis_qty', 'gross_metres', 'total_wastage_pct', 'overhead_pct',
                    'admin_pct', 'margin_pct', 'material_cost', 'tooling_cost', 'machine_cost',
                    'labour_cost', 'energy_cost', 'packing_cost', 'other_cost', 'overhead_amount',
                    'total_cost', 'unit_cost', 'rate_per_m', 'is_locked',
                ]),
                // Every sheet line carries the rule that produced it — the point of §3.4.
                'cost_lines' => $sheets->get($line->id)?->lines->map->only([
                    'sequence_no', 'cost_type', 'description', 'basis_uom', 'qty', 'rate', 'amount', 'formula_ref',
                ]) ?? [],
            ]),
            'availableTransitions' => $this->states->available($quotation),
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

    /** Q3 — only an accepted quotation converts to a sales order. */
    public function convert(Request $request, Quotation $quotation): RedirectResponse
    {
        if ($quotation->status !== 'accepted') {
            return back()->with('error', 'Q3: only an accepted quotation may be converted to a sales order.');
        }

        $data = $request->validate([
            'customer_po_no' => ['nullable', 'string', 'max:80'],
            'delivery_date' => ['nullable', 'date'],
        ]);

        $order = DB::transaction(function () use ($quotation, $data, $request): SalesOrder {
            $order = SalesOrder::query()->create([
                'quotation_id' => $quotation->id,
                'customer_id' => $quotation->customer_id,
                'customer_po_no' => $data['customer_po_no'] ?? null,
                'order_date' => now()->toDateString(),
                'delivery_date' => $data['delivery_date'] ?? null,
                'currency_id' => $quotation->currency_id,
                'exchange_rate' => $quotation->exchange_rate,
                'payment_term_id' => $quotation->payment_term_id,
                'merchandiser_id' => $request->user()->id,
                'priority' => 'normal',
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $subtotal = 0.0;

            foreach ($quotation->lines as $index => $line) {
                $lineTotal = $this->calculator->lineValue((int) $line->qty, (float) $line->rate_per_m)
                    + (float) $line->tooling_charge;
                $subtotal += $lineTotal;

                SalesOrderLine::query()->create([
                    'sales_order_id' => $order->id,
                    'line_no' => $index + 1,
                    'product_id' => $line->product_id,
                    'product_spec_id' => $line->product_spec_id
                        ?? Product::query()->find($line->product_id)?->currentSpec?->id,
                    'description' => $line->description,
                    'ordered_qty' => $line->qty,
                    'rate_per_m' => $line->rate_per_m,
                    'tooling_charge' => $line->tooling_charge,
                    'line_total' => $lineTotal,
                    'over_tolerance_pct' => $this->settings->decimal('over_tolerance_pct', 5),
                    'under_tolerance_pct' => $this->settings->decimal('under_tolerance_pct', 5),
                    'status' => 'open',
                ]);
            }

            $order->forceFill(['subtotal' => $subtotal, 'total' => $subtotal])->save();

            return $order;
        });

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

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(Quotation $quotation, array $lines): void
    {
        CostSheet::query()->whereIn('quotation_line_id', $quotation->lines()->select('id'))->delete();
        $quotation->lines()->delete();

        $subtotal = 0.0;

        foreach ($lines as $index => $line) {
            $lineTotal = $this->calculator->lineValue((int) $line['qty'], (float) $line['rate_per_m'])
                + (float) ($line['tooling_charge'] ?? 0);
            $subtotal += $lineTotal;

            $model = $quotation->lines()->create([
                'line_no' => $index + 1,
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
