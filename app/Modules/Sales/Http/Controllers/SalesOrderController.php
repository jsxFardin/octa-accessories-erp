<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Manufacturing\Services\JobCardPlanningGuard;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Calculators\CostSheetCalculator;
use App\Support\Currency\ExchangeRateResolver;
use App\Support\Http\ListsResources;
use App\Support\Notifications\Notifier;
use App\Support\Reference\Vocabulary;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SalesOrderStateMachine $states,
        private readonly CostSheetCalculator $costing,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly DocumentTrail $trail,
        private readonly JobCardPlanningGuard $planning,
        private readonly ExchangeRateResolver $rates,
    ) {}

    public function index(Request $request): Response
    {
        $query = SalesOrder::query()->with(['customer:id,code,name', 'currency:id,code'])->withCount('lines');

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

        // F-08 — the queue is about raising a job card, so every row has to say whether this
        // order still needs one. The condition is defined once, here, and used both to filter
        // the list and to decide whether the row offers the action; the filter below and the
        // flag on the row can therefore never disagree.
        $awaitingJobCard = fn ($line) => $line
            ->whereColumn('produced_qty', '<', 'ordered_qty')
            ->whereNotExists(fn ($exists) => $exists
                ->from('job_cards')
                ->whereColumn('job_cards.sales_order_line_id', 'sales_order_lines.id')
                ->whereNotIn('job_cards.status', ['cancelled']));

        $query->withExists(['lines as awaits_job_card' => $awaitingJobCard]);

        // The dashboard's "waiting for a job card" queue needs somewhere to land. An order
        // qualifies when a line still has quantity to make and no live card covers it —
        // the same condition `JobCardController::create()` builds its line list from.
        if ($request->query('awaiting') === 'job_card') {
            $query->whereIn('status', ['confirmed', 'in_production'])
                ->whereHas('lines', $awaitingJobCard);
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
                    // The list mixes BDT and USD orders; a bare 52.33 beside a 27,020.66
                    // says nothing about which is which.
                    'currency' => $order->currency?->code,
                    'status' => $order->status,
                    'lines_count' => $order->lines_count,
                    // F-08 — a queue that only names the work is half a queue. This is what
                    // lets the row offer "Create job card" instead of Open and Edit.
                    'awaits_job_card' => in_array($order->status, ['confirmed', 'in_production'], true)
                        // Set by the `withExists()` above, so it is an attribute rather than a
                        // declared property on the model.
                        && (bool) $order->getAttribute('awaits_job_card'),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer', 'late', 'awaiting']),
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

    public function show(Request $request, SalesOrder $salesOrder): Response
    {
        $salesOrder->load([
            'customer',
            'currency:id,code',
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
                'currency' => $salesOrder->currency?->code,
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
                // BR-53 — live job cards committing more than this line can now take, which is
                // what an amendment downwards leaves behind. Stated rather than left for
                // somebody to notice at the loading bay.
                'over_allocation' => $this->planning->overAllocation($line),
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
            'amendments' => DB::table('so_amendments as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.created_by')
                ->where('a.sales_order_id', $salesOrder->id)
                ->orderByDesc('a.id')
                ->get([
                    'a.id', 'a.revision_no', 'a.changed_field', 'a.old_value', 'a.new_value',
                    'a.reason', 'a.created_at', 'u.name as changed_by',
                ]),
            // J6 — a card's output is its final operation's good quantity. Reading
            // `job_cards.good_qty_running` here showed 60,457 made against 30,000 planned for a job
            // that made exactly 30,000, because that column adds metres to pieces.
            'jobCards' => DB::table('job_cards as jc')
                ->whereIn('jc.sales_order_line_id', $salesOrder->lines->pluck('id'))
                ->selectRaw('jc.id, jc.number, jc.status, jc.planned_qty, jc.due_date,
                    COALESCE((SELECT o.good_qty FROM job_card_operations o
                              WHERE o.job_card_id = jc.id
                              ORDER BY o.sequence_no DESC LIMIT 1), 0) as good_qty')
                ->get(),
            // F-01/F-02 — the order's own history: confirmed, held, amended, closed and by
            // whom. Recorded all along and shown nowhere.
            'trail' => $this->trail->for($salesOrder, $request->user()),
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

        // S1 — documented since the domain model and commented in `recordAmendments()`, but
        // never actually enforced: `ordered_qty` was validated as `numeric|gt:0` and nothing
        // more. An order reduced below what the floor has already made leaves production it
        // can never be delivered against, which is how a line ordered for 20,000 came to carry
        // 45,883 produced.
        $this->guardReduction($salesOrder, $data['lines']);

        DB::transaction(function () use ($salesOrder, $data, $request, $isConfirmed): void {
            if ($isConfirmed) {
                $this->recordAmendments($salesOrder, $data, (string) $request->input('amendment_reason'), $request->user()->id);
            }

            $salesOrder->update(collect($data)->except('lines')->all());
            $this->syncLines($salesOrder, $data['lines']);
            $this->recalculateTotals($salesOrder);

            // BR-46 — the credit decision is taken again once the amendment is on the order.
            //
            // It used to be taken only on `draft → confirmed`, while everything that determines
            // exposure — the customer, the lines, their quantities and rates, the currency and
            // its rate — stayed amendable afterwards and `update()` ran no credit logic at all.
            // An order confirmed at USD 100 could be amended to USD 100,000 and sit in
            // `confirmed` at twenty-four times the customer's limit, with production and
            // dispatch both reachable from that status; moving `customer_id` onto a different
            // customer had the same effect from the other direction.
            //
            // Evaluated *after* the amendment is applied and inside the same transaction, so the
            // figure judged is the one that will be stored, and a refusal takes the amendment
            // down with it. This follows the guard BR-53/S1 put on this same path rather than
            // introducing a second enforcement style.
            $this->guardCreditExposure($salesOrder->refresh(), $isConfirmed);
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

                // The hold is invisible to the people who can clear it otherwise: the status
                // sits on a screen none of them had a reason to open.
                $this->notifier->notifyOrderOnCreditHold($salesOrder, (float) $credit['excess']);

                return back()->with(
                    'warning',
                    sprintf(
                        'BR-46: this order takes %s past their credit limit by %s %s. Held for Accounts or the MD to release.',
                        $salesOrder->customer?->name,
                        // The decision is made in the factory's currency (BR-51), so the figure
                        // that explains it says which currency it is in.
                        $this->states->baseCurrencyCode(),
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
    /**
     * S1 — a line's ordered quantity may not fall below what has already been produced.
     *
     * Production that has happened cannot be un-made, so the order has to keep enough quantity
     * to receive it. Reducing *below committed job cards* is a different and lesser thing —
     * that work may not have started, the customer genuinely did cut the order, and cancelling
     * a card is a planner's decision rather than a side effect of an edit. That case is
     * allowed and surfaced (BR-53), not refused here.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    /**
     * BR-46 at the amendment boundary.
     *
     * A draft is not judged here: it has its own gate on confirmation, and holding a draft would
     * say nothing. Everything past draft is judged on the amended figures.
     *
     * What happens on a breach depends on how far the order has gone, because `credit_hold`
     * means "do not start this yet":
     *
     * - `confirmed` — the order is held. The amendment stands, because the customer may really
     *   have increased it; what stops is the order being executed on a value nobody checked.
     *   Releasing it needs `sales_order.release_credit_hold` and a documented reason, which is
     *   BR-46's existing mechanism rather than a new one.
     * - `credit_hold` — already held, and it stays held. The amendment cannot quietly return it.
     * - anything further on (`in_production`, `partially_delivered`, …) — the amendment itself is
     *   refused. Those goods exist; holding the order would be a lie about where they are, and
     *   un-ordering production is what S1 already forbids.
     *
     * The transition runs as the system: the amender holds `sales_order.confirm` in every seeded
     * role that can reach this path, but the hold is a consequence of the rule rather than an
     * action they chose, which is exactly what `asSystem()` is for. Guards still run.
     */
    private function guardCreditExposure(SalesOrder $order, bool $isConfirmed): void
    {
        if (! $isConfirmed && $order->status !== 'credit_hold') {
            return;
        }

        if (! $this->states->creditCheck($order)['on_hold']) {
            return;
        }

        if ($order->status === 'credit_hold') {
            return;
        }

        if ($order->status !== 'confirmed') {
            throw ValidationException::withMessages([
                'amendment_reason' => sprintf(
                    'This change takes %s past their credit limit, and the order is already %s — it cannot be held back now that the work has started. Reduce the amendment, or settle the outstanding balance first.',
                    $order->customer->name,
                    str_replace('_', ' ', $order->status),
                ),
            ]);
        }

        StateMachine::asSystem(fn () => $this->states->transition($order, 'credit_hold', [
            'amendment_reason' => 'BR-46: amendment took the order past the credit limit.',
        ]));
    }

    /**
     * S1 — `ordered_qty` may not be reduced below what the floor has already produced.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function guardReduction(SalesOrder $order, array $lines): void
    {
        $existing = $order->lines()->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            $id = $line['id'] ?? null;

            if ($id === null || ! $existing->has($id)) {
                continue;
            }

            $current = $existing->get($id);
            $produced = (float) $current->produced_qty;
            $wanted = (float) $line['ordered_qty'];

            if ($produced <= 0 || $wanted >= $produced - 0.000001) {
                continue;
            }

            throw ValidationException::withMessages([
                "lines.{$index}.ordered_qty" => sprintf(
                    'Line %d has already produced %s pcs, so it cannot be reduced to %s (S1). Production that has happened cannot be un-ordered — reduce it to %s or more, or cancel the job cards first and scrap the output through a stock adjustment.',
                    $current->line_no,
                    rtrim(rtrim(number_format($produced, 6, '.', ','), '0'), '.'),
                    rtrim(rtrim(number_format($wanted, 6, '.', ','), '0'), '.'),
                    rtrim(rtrim(number_format($produced, 6, '.', ','), '0'), '.'),
                ),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SalesOrder $order = null): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'customer_po_no' => ['nullable', 'string', 'max:80'],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
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

        // BR-58 — the snapshot rate is booked by the server from the reference table, not
        // taken on trust from the form. A `1` on a foreign-currency document understates every
        // base-currency figure derived from it and, where a band is compared, is an
        // authorisation bypass reached by typing a number.
        $data['exchange_rate'] = $this->rates->resolve(
            (int) $data['currency_id'],
            $data['exchange_rate'] ?? null,
            $data['order_date'] ?? null,
        );

        return $data;
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
