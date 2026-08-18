<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\Uom;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\SupplierQuotation;
use App\Modules\Procurement\Models\SupplierQuotationLine;
use App\Modules\Procurement\Models\SupplierRfq;
use App\Modules\Procurement\Models\SupplierRfqLine;
use App\Modules\Procurement\States\SupplierRfqStateMachine;
use App\Support\Http\ListsResources;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PR-2 — RFQ issue, supplier quotation capture, side-by-side comparison, winner → PO.
 */
class SupplierRfqController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly SupplierRfqStateMachine $states,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $query = SupplierRfq::query()->with(['requisition:id,number'])->withCount(['lines', 'quotations']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number'],
            filters: ['status' => 'status'],
            sortable: ['number', 'issued_on', 'respond_by', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Procurement/Rfqs/Index', [
            'rfqs' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (SupplierRfq $row): array => [
                    ...$row->only(['id', 'number', 'issued_on', 'respond_by', 'status']),
                    'pr_number' => $row->requisition?->number,
                    'lines_count' => $row->lines_count,
                    'quotations_count' => $row->quotations_count,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }

    public function create(Request $request): Response
    {
        $prId = $request->integer('pr_id') ?: null;
        $requisition = $prId !== null
            ? PurchaseRequisition::query()->with('lines')->find($prId)
            : null;

        return Inertia::render('Procurement/Rfqs/Form', [
            'rfq' => null,
            'requisition' => $requisition?->only(['id', 'number', 'status', 'factory_unit_id']),
            'lines' => $requisition?->lines->map(fn ($line): array => [
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'qty' => $line->qty,
            ])->all() ?? [],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $rfq = DB::transaction(function () use ($data, $request): SupplierRfq {
            $this->assertRequisition($data['pr_id'] ?? null);

            $rfq = new SupplierRfq;
            $rfq->forceFill([
                'pr_id' => $data['pr_id'] ?? null,
                'issued_on' => $data['issued_on'] ?? now()->toDateString(),
                'respond_by' => $data['respond_by'] ?? null,
                'status' => SupplierRfq::DRAFT,
                'created_by' => $request->user()?->id,
            ])->save();

            $this->syncLines($rfq, $data['lines']);

            return $rfq;
        });

        return redirect()
            ->route('rfqs.show', $rfq)
            ->with('success', 'RFQ saved as a draft. Issue it to collect supplier quotations.');
    }

    public function show(SupplierRfq $rfq): Response
    {
        $rfq->load(['requisition:id,number,status', 'creator:id,name']);

        return Inertia::render('Procurement/Rfqs/Show', [
            'rfq' => [
                ...$rfq->only(['id', 'number', 'pr_id', 'issued_on', 'respond_by', 'status']),
                'requisition' => $rfq->requisition?->only(['id', 'number', 'status']),
                'creator' => $rfq->creator?->only(['id', 'name']),
            ],
            'lines' => $this->mappedLines($rfq),
            'quotations' => $this->mappedQuotations($rfq),
            'availableTransitions' => $this->states->available($rfq),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'code', 'name', 'is_approved', 'currency_id', 'lead_time_days']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'code', 'name', 'is_base']),
            'quoteThreshold' => $this->settings->decimal('rfq_three_quote_value_threshold', 50000),
        ]);
    }

    public function edit(SupplierRfq $rfq): Response|RedirectResponse
    {
        if ($rfq->status !== SupplierRfq::DRAFT) {
            return redirect()->route('rfqs.show', $rfq)->with('error', 'Only a draft RFQ can be edited.');
        }

        $rfq->load('lines');

        return Inertia::render('Procurement/Rfqs/Form', [
            'rfq' => [
                ...$rfq->only(['id', 'number', 'pr_id', 'issued_on', 'respond_by', 'status']),
                'lines' => $rfq->lines->map(fn (SupplierRfqLine $line): array => [
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'qty' => $line->qty,
                ])->all(),
            ],
            'requisition' => null,
            'lines' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, SupplierRfq $rfq): RedirectResponse
    {
        if ($rfq->status !== SupplierRfq::DRAFT) {
            return back()->with('error', 'Only a draft RFQ can be edited.');
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($rfq, $data): void {
            $this->assertRequisition($data['pr_id'] ?? null);
            $rfq->forceFill([
                'pr_id' => $data['pr_id'] ?? null,
                'issued_on' => $data['issued_on'] ?? $rfq->issued_on,
                'respond_by' => $data['respond_by'] ?? null,
            ])->save();
            $this->syncLines($rfq, $data['lines']);
        });

        return redirect()->route('rfqs.show', $rfq)->with('success', 'RFQ updated.');
    }

    public function transition(Request $request, SupplierRfq $rfq): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        try {
            $this->states->transition($rfq, $data['to']);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        $rfq->refresh();

        return back()->with('success', sprintf(
            'RFQ %s is now %s.',
            $rfq->number ?? '#'.$rfq->id,
            $data['to'],
        ));
    }

    public function storeQuotation(Request $request, SupplierRfq $rfq): RedirectResponse
    {
        if ($rfq->status !== SupplierRfq::ISSUED) {
            return back()->with('error', 'Quotations can only be recorded against an issued RFQ.');
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'quoted_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($rfq, $data): void {
            /** @var SupplierRfq $locked */
            $locked = SupplierRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());

            if ($locked->status !== SupplierRfq::ISSUED) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'This RFQ is no longer open for quotations.',
                ]);
            }

            $exists = SupplierQuotation::query()
                ->where('rfq_id', $locked->id)
                ->where('supplier_id', $data['supplier_id'])
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'This supplier already has a quotation on this RFQ.',
                ]);
            }

            $supplier = Supplier::query()->findOrFail($data['supplier_id']);

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'That supplier is not active.',
                ]);
            }

            $rfqItems = SupplierRfqLine::query()
                ->where('rfq_id', $locked->id)
                ->get()
                ->keyBy('item_id');

            $quotation = new SupplierQuotation;
            $quotation->forceFill([
                'rfq_id' => $locked->id,
                'supplier_id' => $data['supplier_id'],
                'quoted_on' => $data['quoted_on'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'currency_id' => $data['currency_id'],
                'lead_time_days' => $data['lead_time_days'] ?? $supplier->lead_time_days,
                'total' => 0,
                'is_selected' => false,
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            $total = 0.0;

            foreach ($data['lines'] as $index => $line) {
                $rfqLine = $rfqItems->get((int) $line['item_id']);

                if ($rfqLine === null) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.item_id" => 'That item is not on this RFQ.',
                    ]);
                }

                $amount = round((float) $line['qty'] * (float) $line['rate'], 4);
                $total += $amount;

                $row = new SupplierQuotationLine;
                $row->forceFill([
                    'supplier_quotation_id' => $quotation->id,
                    'line_no' => $index + 1,
                    'item_id' => $line['item_id'],
                    'qty' => $line['qty'],
                    'uom_id' => $line['uom_id'],
                    'rate' => $line['rate'],
                    'amount' => $amount,
                ])->save();
            }

            $quotation->forceFill(['total' => $total])->save();
        });

        return back()->with('success', 'Supplier quotation recorded.');
    }

    public function select(Request $request, SupplierRfq $rfq): RedirectResponse
    {
        if ($rfq->status !== SupplierRfq::ISSUED) {
            return back()->with('error', 'A winner can only be chosen on an issued RFQ.');
        }

        $data = $request->validate([
            'quotation_id' => ['required', 'integer', 'exists:supplier_quotations,id'],
            'override_reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($rfq, $data): void {
            /** @var SupplierRfq $locked */
            $locked = SupplierRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());

            if ($locked->status !== SupplierRfq::ISSUED) {
                throw ValidationException::withMessages([
                    'quotation_id' => 'This RFQ is no longer open.',
                ]);
            }

            /** @var SupplierQuotation $winner */
            $winner = SupplierQuotation::query()
                ->where('rfq_id', $locked->id)
                ->whereKey($data['quotation_id'])
                ->firstOrFail();

            $this->assertEnoughQuotations($locked, $winner, $data['override_reason'] ?? null);

            SupplierQuotation::query()->where('rfq_id', $locked->id)->update(['is_selected' => false]);
            $winner->forceFill(['is_selected' => true])->save();
        });

        return back()->with('success', 'Winning quotation selected. You can raise the purchase order.');
    }

    public function compare(SupplierRfq $rfq): Response
    {
        $rfq->load(['requisition:id,number']);

        return Inertia::render('Procurement/Rfqs/Compare', [
            'rfq' => [
                ...$rfq->only(['id', 'number', 'status', 'issued_on', 'respond_by']),
                'pr_number' => $rfq->requisition?->number,
            ],
            'lines' => $this->mappedLines($rfq),
            'quotations' => $this->mappedQuotations($rfq),
            'quoteThreshold' => $this->settings->decimal('rfq_three_quote_value_threshold', 50000),
        ]);
    }

    public function storePurchaseOrder(Request $request, SupplierRfq $rfq): RedirectResponse
    {
        if (! $request->user()?->hasPermission('purchase_order.create')) {
            abort(403);
        }

        $order = DB::transaction(function () use ($rfq): PurchaseOrder {
            /** @var SupplierRfq $locked */
            $locked = SupplierRfq::query()->lockForUpdate()->findOrFail($rfq->getKey());

            if ($locked->status !== SupplierRfq::ISSUED) {
                throw ValidationException::withMessages([
                    'quotation_id' => 'The RFQ must be issued before a purchase order is raised.',
                ]);
            }

            /** @var SupplierQuotation|null $winner */
            $winner = SupplierQuotation::query()
                ->where('rfq_id', $locked->id)
                ->where('is_selected', true)
                ->with('lines')
                ->first();

            if ($winner === null) {
                throw ValidationException::withMessages([
                    'quotation_id' => 'Select a winning quotation first.',
                ]);
            }

            $supplier = Supplier::query()->findOrFail($winner->supplier_id);
            $factoryUnitId = DB::table('factory_units')->where('is_active', true)->orderBy('id')->value('id');
            $prLines = collect();

            if ($locked->pr_id !== null) {
                $pr = PurchaseRequisition::query()->findOrFail($locked->pr_id);
                $factoryUnitId = $pr->factory_unit_id;
                $prLines = $pr->lines()->get()->keyBy('item_id');
            }

            $order = new PurchaseOrder;
            $order->forceFill([
                'supplier_id' => $winner->supplier_id,
                'factory_unit_id' => $factoryUnitId,
                'order_date' => now()->toDateString(),
                'expected_date' => $winner->lead_time_days !== null
                    ? now()->addDays((int) $winner->lead_time_days)->toDateString()
                    : null,
                'currency_id' => $winner->currency_id,
                'exchange_rate' => 1,
                'payment_term_id' => $supplier->payment_term_id,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'remarks' => sprintf('Raised from RFQ %s.', $locked->number ?? '#'.$locked->id),
            ])->save();

            $subtotal = 0.0;

            foreach ($winner->lines as $index => $line) {
                $amount = round((float) $line->qty * (float) $line->rate, 4);
                $subtotal += $amount;
                $prLine = $prLines->get((int) $line->item_id);

                DB::table('purchase_order_lines')->insert([
                    'po_id' => $order->id,
                    'line_no' => $index + 1,
                    'item_id' => $line->item_id,
                    'pr_line_id' => $prLine?->id,
                    'uom_id' => $line->uom_id,
                    'qty' => $line->qty,
                    'rate' => $line->rate,
                    'amount' => $amount,
                    'expected_date' => $order->expected_date,
                ]);

                if ($prLine !== null) {
                    DB::table('purchase_requisition_lines')
                        ->where('id', $prLine->id)
                        ->update(['ordered_qty' => DB::raw('ordered_qty + '.(float) $line->qty)]);
                }
            }

            $order->forceFill([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ])->save();

            $this->states->transition($locked, SupplierRfq::CLOSED);

            return $order;
        });

        return redirect()
            ->route('purchase-orders.show', $order)
            ->with('success', 'Draft purchase order raised from the winning quotation.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'pr_id' => ['nullable', 'integer', 'exists:purchase_requisitions,id'],
            'issued_on' => ['nullable', 'date'],
            'respond_by' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function syncLines(SupplierRfq $rfq, array $lines): void
    {
        SupplierRfqLine::query()->where('rfq_id', $rfq->id)->delete();

        foreach ($lines as $index => $line) {
            $row = new SupplierRfqLine;
            $row->forceFill([
                'rfq_id' => $rfq->id,
                'line_no' => $index + 1,
                'item_id' => $line['item_id'],
                'qty' => $line['qty'],
                'uom_id' => $line['uom_id'],
            ])->save();
        }
    }

    private function assertRequisition(?int $prId): void
    {
        if ($prId === null) {
            return;
        }

        $status = PurchaseRequisition::query()->whereKey($prId)->value('status');

        if ($status !== 'approved') {
            throw ValidationException::withMessages([
                'pr_id' => 'An RFQ can only be raised from an approved requisition.',
            ]);
        }
    }

    private function assertEnoughQuotations(SupplierRfq $rfq, SupplierQuotation $winner, ?string $overrideReason): void
    {
        $threshold = $this->settings->decimal('rfq_three_quote_value_threshold', 50000);
        $count = SupplierQuotation::query()->where('rfq_id', $rfq->id)->count();

        if ((float) $winner->total <= $threshold || $count >= 3) {
            return;
        }

        if ($overrideReason !== null && trim($overrideReason) !== '') {
            $winner->forceFill([
                'remarks' => trim(($winner->remarks ? $winner->remarks."\n" : '').'Three-quote override: '.$overrideReason),
            ])->save();

            return;
        }

        throw ValidationException::withMessages([
            'override_reason' => sprintf(
                'This quotation is %s, above the %s threshold that requires three quotations. Record more quotes or give an override reason.',
                number_format((float) $winner->total, 2),
                number_format($threshold, 2),
            ),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function mappedLines(SupplierRfq $rfq): array
    {
        return DB::table('supplier_rfq_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->where('l.rfq_id', $rfq->id)
            ->orderBy('l.line_no')
            ->get([
                'l.id', 'l.line_no', 'l.item_id', 'l.uom_id', 'l.qty',
                'i.code as item_code', 'i.name as item_name', 'i.min_order_qty',
                'u.code as uom',
            ])
            ->map(fn ($row): array => (array) $row)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function mappedQuotations(SupplierRfq $rfq): array
    {
        $quotes = SupplierQuotation::query()
            ->with(['supplier:id,code,name', 'currency:id,code', 'lines'])
            ->where('rfq_id', $rfq->id)
            ->orderBy('id')
            ->get();

        $moqs = DB::table('supplier_items')
            ->whereIn('supplier_id', $quotes->pluck('supplier_id'))
            ->get(['supplier_id', 'item_id', 'moq', 'lead_time_days', 'last_rate'])
            ->groupBy('supplier_id');

        return $quotes->map(function (SupplierQuotation $quote) use ($moqs): array {
            $supplierMoq = $moqs->get($quote->supplier_id, collect());

            return [
                'id' => $quote->id,
                'supplier_id' => $quote->supplier_id,
                'supplier' => $quote->supplier?->only(['id', 'code', 'name']),
                'quoted_on' => $quote->quoted_on,
                'valid_until' => $quote->valid_until,
                'currency' => $quote->currency?->code,
                'total' => (float) $quote->total,
                'lead_time_days' => $quote->lead_time_days,
                'is_selected' => $quote->is_selected,
                'remarks' => $quote->remarks,
                'lines' => $quote->lines->map(function (SupplierQuotationLine $line) use ($supplierMoq): array {
                    $moq = null;

                    foreach ($supplierMoq as $row) {
                        if ((int) $row->item_id === (int) $line->item_id) {
                            $moq = $row->moq;
                            break;
                        }
                    }

                    return [
                        'item_id' => $line->item_id,
                        'qty' => (float) $line->qty,
                        'uom_id' => $line->uom_id,
                        'rate' => (float) $line->rate,
                        'amount' => (float) $line->amount,
                        'moq' => $moq,
                    ];
                })->all(),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'items' => Item::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'base_uom_id', 'min_order_qty', 'order_multiple']),
            'uoms' => Uom::query()->orderBy('code')->get(['id', 'code', 'name']),
            'requisitions' => PurchaseRequisition::query()
                ->where('status', 'approved')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'number', 'required_by']),
        ];
    }
}
