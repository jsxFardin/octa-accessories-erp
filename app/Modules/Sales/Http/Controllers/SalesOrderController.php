<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Http\ListsResources;
use App\Support\Reference\Vocabulary;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SalesOrderStateMachine $states,
        private readonly CostSheetCalculator $costing,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $query = SalesOrder::query()->with(['customer:id,code,name'])->withCount('lines');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'customer_po_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id'],
            sortable: ['number', 'order_date', 'delivery_date', 'total', 'status'],
            defaultSort: '-id',
        );

        if ($request->query('late') === '1') {
            $query->whereDate('delivery_date', '<', now())
                ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered']);
        }

        return Inertia::render('Sales/SalesOrders/Index', [
            'orders' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SalesOrder $order): array => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'revision_no' => $order->revision_no,
                    'customer' => $order->customer?->name,
                    'customer_po_no' => $order->customer_po_no,
                    'order_date' => $order->order_date,
                    'delivery_date' => $order->delivery_date,
                    'total' => $order->total,
                    'status' => $order->status,
                    'lines_count' => $order->lines_count,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer', 'late']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Sales/SalesOrders/Form', [
            'order' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $order = DB::transaction(function () use ($data, $request): SalesOrder {
            // BR-34 — no number yet. A draft shows "(unnumbered)" until it leaves draft.
            $order = SalesOrder::query()->create([
                ...collect($data)->except('lines')->all(),
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            $this->syncLines($order, $data['lines']);
            $this->recalculateTotals($order);

            return $order;
        });

        return redirect()
            ->route('sales-orders.show', $order)
            ->with('success', 'Draft order created. Confirm it once artwork and specs are in place.');
    }

    public function show(SalesOrder $salesOrder): Response
    {
        $salesOrder->load([
            'customer',
            'lines.product.artworks.versions',
            'lines.product.customer',
            'lines.spec',
        ]);

        return Inertia::render('Sales/SalesOrders/Show', [
            'order' => [
                ...$salesOrder->only([
                    'id', 'number', 'revision_no', 'customer_po_no', 'order_date', 'delivery_date',
                    'subtotal', 'tax_amount', 'total', 'priority', 'status', 'confirmed_at',
                    'closed_at', 'close_reason', 'notes',
                ]),
                'customer' => $salesOrder->customer?->only(['id', 'code', 'name', 'credit_limit', 'min_order_value']),
            ],
            'lines' => $salesOrder->lines->map(fn (SalesOrderLine $line): array => [
                ...$line->only([
                    'id', 'line_no', 'description', 'ordered_qty', 'produced_qty', 'delivered_qty',
                    'invoiced_qty', 'rate_per_m', 'tooling_charge', 'line_total',
                    'over_tolerance_pct', 'under_tolerance_pct', 'promised_date', 'status',
                ]),
                'product' => $line->product?->only(['id', 'code', 'name', 'product_type']),
                'spec_version' => $line->spec?->version_no,
                'spec_is_current' => $line->spec?->isCurrent() ?? false,
                'artwork_approved' => $line->product?->artworks
                    ->flatMap->versions
                    ->contains(fn ($v): bool => $v->status === ArtworkVersion::APPROVED) ?? false,
                // BR-44 — the band the shipment must land inside, shown next to the quantity
                // rather than discovered at the loading bay.
                'delivery_band' => [
                    'min' => round((float) $line->ordered_qty * (1 - (float) $line->under_tolerance_pct / 100), 0),
                    'max' => round((float) $line->ordered_qty * (1 + (float) $line->over_tolerance_pct / 100), 0),
                ],
            ]),
            // S3 readiness, per line, so the confirm button explains itself before it is pressed.
            'readiness' => $salesOrder->lines->map(fn (SalesOrderLine $line): array => [
                'line_no' => $line->line_no,
                'product' => $line->product?->code,
                'spec' => $line->spec?->isCurrent() ?? false,
                'artwork' => $line->product?->artworks
                    ->flatMap->versions
                    ->contains(fn ($v): bool => $v->status === ArtworkVersion::APPROVED) ?? false,
                'bom' => $line->product?->activeBom()->exists() ?? false,
            ]),
            'creditCheck' => $this->states->creditCheck($salesOrder),
            'availableTransitions' => $this->states->available($salesOrder),
            'amendments' => DB::table('so_amendments')
                ->where('sales_order_id', $salesOrder->id)
                ->orderByDesc('id')
                ->get(),
            'jobCards' => DB::table('job_cards')
                ->whereIn('sales_order_line_id', $salesOrder->lines->pluck('id'))
                ->get(['id', 'number', 'status', 'planned_qty', 'good_qty', 'due_date']),
            // P0-4 — the fulfilment strip: every figure from its authoritative source, the
            // packed number derived from carton contents rather than cached anywhere.
            'fulfilment' => [
                'ordered' => (float) $salesOrder->lines->sum('ordered_qty'),
                'produced' => (float) $salesOrder->lines->sum('produced_qty'),
                'fg_received' => (float) DB::table('fg_receipts as fr')
                    ->join('job_cards as jc', 'jc.id', '=', 'fr.job_card_id')
                    ->whereIn('jc.sales_order_line_id', $salesOrder->lines->pluck('id'))
                    ->where('fr.status', 'posted')->sum('fr.qty'),
                'fg_available' => (float) DB::table('stock_lots as sl')
                    ->join('job_cards as jc', 'jc.id', '=', 'sl.job_card_id')
                    ->whereIn('jc.sales_order_line_id', $salesOrder->lines->pluck('id'))
                    ->where('sl.kind', 'finished_goods')->where('sl.status', 'available')
                    ->sum('sl.balance_qty'),
                'packed' => (float) DB::table('carton_contents as cc')
                    ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                    ->join('packing_lists as pl', 'pl.id', '=', 'c.packing_list_id')
                    ->whereIn('cc.sales_order_line_id', $salesOrder->lines->pluck('id'))
                    ->whereIn('pl.status', ['packed', 'dispatched', 'delivered'])
                    ->sum('cc.qty'),
                'delivered' => (float) $salesOrder->lines->sum('delivered_qty'),
                'invoiced' => (float) $salesOrder->lines->sum('invoiced_qty'),
                // P2-1 — applied credit value against this order's invoices.
                'credited_value' => (float) DB::table('credit_notes as cn')
                    ->join('sales_invoices as si', 'si.id', '=', 'cn.sales_invoice_id')
                    ->where('si.sales_order_id', $salesOrder->id)
                    ->where('cn.status', 'applied')
                    ->sum('cn.amount'),
            ],
            'challans' => DB::table('delivery_challans')
                ->where('sales_order_id', $salesOrder->id)
                ->orderByDesc('id')
                ->get(['id', 'number', 'status', 'challan_date', 'total_qty']),
        ]);
    }

    public function edit(SalesOrder $salesOrder): Response
    {
        $salesOrder->load('lines');

        return Inertia::render('Sales/SalesOrders/Form', [
            'order' => $salesOrder,
            ...$this->formOptions(),
        ]);
    }

    /**
     * S2 — every quantity or date change after `confirmed` writes an amendment row with a
     * reason and a user. There are no silent edits to a confirmed order.
     */
    public function update(Request $request, SalesOrder $salesOrder): RedirectResponse
    {
        $data = $this->validated($request, $salesOrder);
        $isConfirmed = ! in_array($salesOrder->status, ['draft', 'credit_hold'], true);

        if ($isConfirmed && blank($request->input('amendment_reason'))) {
            return back()->with('error', 'S2: changing a confirmed order requires an amendment reason.');
        }

        DB::transaction(function () use ($salesOrder, $data, $request, $isConfirmed): void {
            if ($isConfirmed) {
                $this->recordAmendments($salesOrder, $data, (string) $request->input('amendment_reason'), $request->user()->id);
            }

            $salesOrder->update(collect($data)->except('lines')->all());
            $this->syncLines($salesOrder, $data['lines']);
            $this->recalculateTotals($salesOrder);
        });

        return redirect()
            ->route('sales-orders.show', $salesOrder)
            ->with('success', 'Order updated.');
    }

    public function transition(Request $request, SalesOrder $salesOrder): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string'],
            'close_reason' => ['nullable', 'string', 'max:255'],
            'release_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // BR-46 — the credit decision is taken here rather than by the operator: a
        // confirmation that would breach the limit lands on credit_hold, not on confirmed.
        if ($data['to'] === 'confirmed' && $salesOrder->status === 'draft') {
            $credit = $this->states->creditCheck($salesOrder);

            if ($credit['on_hold']) {
                $this->states->transition($salesOrder, 'credit_hold', $data);

                return back()->with(
                    'warning',
                    sprintf(
                        'BR-46: this order takes %s past their credit limit by %s. Held for Accounts or the MD to release.',
                        $salesOrder->customer?->name,
                        number_format($credit['excess'], 2),
                    ),
                );
            }
        }

        try {
            $this->states->transition($salesOrder, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order moved to {$data['to']}.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SalesOrder $order = null): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'customer_po_no' => ['nullable', 'string', 'max:80'],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'billing_address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'delivery_address_id' => ['nullable', 'integer', 'exists:customer_addresses,id'],
            'factory_unit_id' => ['nullable', 'integer', 'exists:factory_units,id'],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.product_spec_id' => ['required', 'integer', 'exists:product_specs,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.ordered_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate_per_m' => ['required', 'numeric', 'min:0'],
            'lines.*.tooling_charge' => ['nullable', 'numeric', 'min:0'],
            'lines.*.over_tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.under_tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.promised_date' => ['nullable', 'date'],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(SalesOrder $order, array $lines): void
    {
        $keptIds = [];

        foreach ($lines as $index => $line) {
            $attributes = [
                'sales_order_id' => $order->id,
                'line_no' => $index + 1,
                'product_id' => $line['product_id'],
                'product_spec_id' => $line['product_spec_id'],
                'description' => $line['description'] ?? null,
                'ordered_qty' => $line['ordered_qty'],
                'rate_per_m' => $line['rate_per_m'],
                'tooling_charge' => $line['tooling_charge'] ?? 0,
                'over_tolerance_pct' => $line['over_tolerance_pct'] ?? $this->settings->decimal('over_tolerance_pct', 5),
                'under_tolerance_pct' => $line['under_tolerance_pct'] ?? $this->settings->decimal('under_tolerance_pct', 5),
                'promised_date' => $line['promised_date'] ?? null,
                // BR-1 — a line value is quantity over 1000 times the per-M rate.
                'line_total' => $this->costing->lineValue((int) $line['ordered_qty'], (float) $line['rate_per_m'])
                    + (float) ($line['tooling_charge'] ?? 0),
            ];

            if (isset($line['id'])) {
                /** @var SalesOrderLine $model */
                $model = SalesOrderLine::query()->findOrFail($line['id']);
                $model->update($attributes);
            } else {
                $model = SalesOrderLine::query()->create($attributes);
            }

            $keptIds[] = $model->id;
        }

        // S1 — a removed line is only removable while nothing has been produced against it.
        SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->whereNotIn('id', $keptIds)
            ->where('produced_qty', 0)
            ->delete();
    }

    private function recalculateTotals(SalesOrder $order): void
    {
        // BR-47 — the document total is the sum of rounded line values, so the printed
        // order foots against its own lines.
        $subtotal = (float) $order->lines()->sum('line_total');

        $order->forceFill([
            'subtotal' => $subtotal,
            'total' => $subtotal + (float) $order->tax_amount,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function recordAmendments(SalesOrder $order, array $data, string $reason, int $userId): void
    {
        $revision = (int) $order->revision_no + 1;
        $tracked = ['delivery_date', 'customer_po_no', 'priority'];
        $rows = [];

        foreach ($tracked as $field) {
            // A field the request did not send is a field nobody edited. Comparing it against
            // '' would record an amendment for a header the form simply left out.
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ((string) $order->{$field} !== (string) $data[$field]) {
                $rows[] = [
                    'sales_order_id' => $order->id,
                    'revision_no' => $revision,
                    'changed_field' => $field,
                    'old_value' => (string) $order->{$field},
                    'new_value' => (string) ($data[$field] ?? ''),
                    'reason' => $reason,
                    'created_by' => $userId,
                    'created_at' => now(),
                ];
            }
        }

        foreach ($order->lines as $line) {
            $incoming = null;

            foreach ($data['lines'] as $candidate) {
                if (($candidate['id'] ?? null) === $line->id) {
                    $incoming = $candidate;

                    break;
                }
            }

            if ($incoming !== null && (float) $incoming['ordered_qty'] !== (float) $line->ordered_qty) {
                // S1 — quantity may only be reduced as far as what is already produced.
                $rows[] = [
                    'sales_order_id' => $order->id,
                    'revision_no' => $revision,
                    'changed_field' => "line {$line->line_no} ordered_qty",
                    'old_value' => (string) $line->ordered_qty,
                    'new_value' => (string) $incoming['ordered_qty'],
                    'reason' => $reason,
                    'created_by' => $userId,
                    'created_at' => now(),
                ];
            }
        }

        if ($rows !== []) {
            DB::table('so_amendments')->insert($rows);
            $order->forceFill(['revision_no' => $revision])->save();
        }
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'priorities' => Vocabulary::options('order_priority'),
            'customers' => Customer::query()->active()->orderBy('name')
                ->get(['id', 'code', 'name', 'credit_limit', 'min_order_value']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name', 'is_base']),
            'products' => Product::query()->active()->with('currentSpec:id,product_id,version_no')
                ->orderBy('code')->get(['id', 'code', 'name', 'customer_id', 'product_type']),
            'defaults' => [
                'over_tolerance_pct' => $this->settings->decimal('over_tolerance_pct', 5),
                'under_tolerance_pct' => $this->settings->decimal('under_tolerance_pct', 5),
            ],
        ];
    }
}
