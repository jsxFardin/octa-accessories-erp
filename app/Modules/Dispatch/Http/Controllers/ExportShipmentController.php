<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\ExportDocument;
use App\Modules\Dispatch\Models\ExportShipment;
use App\Modules\Dispatch\Services\ExportDocumentChecklist;
use App\Modules\Dispatch\States\ExportShipmentStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Currency\ExchangeRateResolver;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Http\RefusesActions;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use App\Support\Text\Plain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Export shipments (DF-6): the consignment leaving the country, its delivery notes, the
 * credit it ships under, and the documents that must travel with it before it is complete.
 * The Sales-side twin of the import shipment.
 */
class ExportShipmentController extends Controller
{
    use ContextualId;
    use ListsResources;
    use RefusesActions;

    public function __construct(
        private readonly ExportShipmentStateMachine $states,
        private readonly ExportDocumentChecklist $checklist,
        private readonly ExchangeRateResolver $rates,
        private readonly DocumentTrail $trail,
        private readonly Settings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $query = ExportShipment::query()->with(['customer:id,code,name', 'letterOfCredit:id,number,lc_no', 'currency:id,code'])->withCount('challans');

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'transport_doc_no', 'container_no', 'exp_form_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id', 'mode' => 'mode'],
            sortable: ['number', 'etd', 'eta', 'status', 'goods_value'],
            defaultSort: '-id',
        );

        $presentationDays = $this->settings->int('export_presentation_days', 21);
        $unpresented = fn ($q) => $q->where('status', ExportShipment::SHIPPED)->whereNotNull('lc_id')
            ->whereDate('shipped_on', '<=', now()->subDays($presentationDays)->toDateString());

        if ($request->query('unpresented') === '1') {
            $unpresented($query);
        }

        return Inertia::render('Dispatch/ExportShipments/Index', [
            'shipments' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (ExportShipment $shipment): array => [
                    ...$shipment->only(['id', 'number', 'mode', 'transport_doc_no', 'container_no', 'etd', 'eta', 'shipped_on', 'presented_on', 'goods_value', 'status']),
                    'customer' => $shipment->customer?->name,
                    'lc' => $shipment->letterOfCredit->lc_no ?? $shipment->letterOfCredit->number ?? null,
                    'currency' => $shipment->currency?->code,
                    'challans_count' => $shipment->challans_count,
                    'days_since_shipped' => $shipment->shipped_on === null ? null : (int) $shipment->shipped_on->startOfDay()->diffInDays(now()->startOfDay()),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer', 'mode', 'unpresented']),
            'counts' => [
                ...$this->stageCounts('export_shipments'),
                'unpresented' => $unpresented(ExportShipment::query())->count(),
            ],
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'code', 'name']),
            'modes' => ExportShipment::MODES,
            'statuses' => ExportShipment::STATUSES,
            'presentationDays' => $presentationDays,
        ]);
    }

    public function create(Request $request): Response
    {
        $challanId = $this->contextualId($request, 'challan', ['delivery_challan.view_any', 'delivery_challan.view']);
        $challan = $challanId === null ? null : DeliveryChallan::query()->with('salesOrder:id,number,currency_id')->find($challanId);

        // Arriving from a delivery note: its customer, and the credit covering its order.
        $credit = $challan?->sales_order_id === null ? null : DB::table('lc_sales_orders as lso')
            ->join('letters_of_credit as lc', 'lc.id', '=', 'lso.lc_id')
            ->where('lso.sales_order_id', $challan->sales_order_id)
            ->whereIn('lc.status', ['advised', 'accepted', 'shipped', 'presented', 'discrepant'])
            ->orderByDesc('lc.id')
            ->first(['lc.id', 'lc.incoterm', 'lc.port_of_loading', 'lc.port_of_discharge', 'lc.currency_id']);

        return Inertia::render('Dispatch/ExportShipments/Form', [
            'shipment' => null,
            'preselect' => [
                'customer_id' => $challan?->customer_id,
                'challan_id' => $challan?->id,
                'lc_id' => $credit?->id,
                'currency_id' => $credit->currency_id ?? $challan->salesOrder->currency_id ?? null,
                'incoterm' => $credit?->incoterm,
                'port_of_loading' => $credit?->port_of_loading,
                'port_of_discharge' => $credit?->port_of_discharge,
                'mode' => $challan?->mode === 'courier' ? 'courier' : 'sea',
            ],
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $challanId = $request->integer('challan_id') ?: null;

        $shipment = DB::transaction(function () use ($data, $request, $challanId): ExportShipment {
            $shipment = ExportShipment::query()->create([
                ...$data,
                'status' => ExportShipment::DRAFT,
                'created_by' => $request->user()->id,
            ]);

            if ($challanId !== null) {
                $this->attach($shipment, $challanId);
            }

            return $shipment;
        });

        return redirect()->route('export-shipments.show', $shipment)
            ->with('success', 'Export shipment opened as a draft. Attach its delivery notes, then book it.');
    }

    public function show(Request $request, ExportShipment $exportShipment): Response
    {
        $exportShipment->load(['customer:id,code,name,is_bonded', 'letterOfCredit:id,number,lc_no,status,last_shipment_date,expiry_date,latest_presentation_days', 'currency:id,code', 'creator:id,name']);

        return Inertia::render('Dispatch/ExportShipments/Show', [
            'shipment' => [
                ...$exportShipment->only(['id', 'number', 'mode', 'forwarder', 'carrier', 'vessel_or_flight', 'container_no', 'seal_no', 'transport_doc_no', 'transport_doc_date',
                    'exp_form_no', 'exp_form_date', 'ud_no', 'port_of_loading', 'port_of_discharge', 'final_destination', 'incoterm', 'etd', 'eta',
                    'shipped_on', 'presented_on', 'delivered_on', 'goods_value', 'gross_kg', 'net_kg', 'cbm', 'status', 'remarks']),
                'customer' => $exportShipment->customer?->only(['id', 'code', 'name', 'is_bonded']),
                'letter_of_credit' => $exportShipment->letterOfCredit?->only(['id', 'number', 'lc_no', 'status', 'last_shipment_date', 'expiry_date', 'latest_presentation_days']),
                'currency' => $exportShipment->currency?->code,
                'raised_by' => $exportShipment->creator?->name,
            ],
            'challans' => DB::table('delivery_challans as dc')
                ->leftJoin('sales_orders as so', 'so.id', '=', 'dc.sales_order_id')
                ->where('dc.export_shipment_id', $exportShipment->id)
                ->orderBy('dc.id')
                ->get(['dc.id', 'dc.number', 'dc.challan_date', 'dc.status', 'dc.total_cartons', 'dc.total_qty', 'so.number as order_number', 'so.id as order_id']),
            // Delivery notes this shipment could still carry: the customer's, dispatched, not on another shipment.
            'attachable' => in_array($exportShipment->status, ExportShipment::EDITABLE, true) ? DB::table('delivery_challans')
                ->where('customer_id', $exportShipment->customer_id)
                ->whereIn('status', ['issued', 'in_transit', 'delivered'])
                ->whereNull('export_shipment_id')
                ->orderByDesc('id')
                ->get(['id', 'number', 'challan_date', 'total_cartons', 'total_qty']) : [],
            'documents' => ExportDocument::query()->where('export_shipment_id', $exportShipment->id)->orderBy('id')->get()
                ->map(fn (ExportDocument $doc): array => [
                    ...$doc->only(['id', 'doc_type', 'doc_no', 'doc_date', 'status', 'remarks']),
                    'label' => ExportDocumentChecklist::LABELS[$doc->doc_type] ?? $doc->doc_type,
                    'has_file' => $doc->file_path !== null,
                ]),
            'checklist' => $this->checklist->rows($exportShipment),
            'documentTypes' => collect(ExportDocumentChecklist::TYPES)->map(fn (string $t): array => ['value' => $t, 'label' => ExportDocumentChecklist::LABELS[$t]])->values(),
            // The three the system drafts itself, with where each draft is viewed.
            'generated' => collect(\App\Modules\Dispatch\Services\ExportDocumentGenerator::GENERATED)
                ->map(fn (string $key, string $type): array => ['type' => $type, 'label' => ExportDocumentChecklist::LABELS[$type], 'print_url' => route("{$key}.print", ['id' => $exportShipment->id]), 'pdf_url' => route("{$key}.pdf", ['id' => $exportShipment->id])])
                ->values(),
            'invoices' => DB::table('sales_invoices as si')
                ->join('delivery_challans as dc', 'dc.id', '=', 'si.delivery_challan_id')
                ->where('dc.export_shipment_id', $exportShipment->id)
                ->where('si.status', '!=', 'cancelled')
                ->get(['si.id', 'si.number', 'si.total', 'si.status']),
            'availableTransitions' => $this->states->available($exportShipment),
            'trail' => $this->trail->for($exportShipment, $request->user()),
        ]);
    }

    public function edit(ExportShipment $exportShipment): Response
    {
        return Inertia::render('Dispatch/ExportShipments/Form', [
            'shipment' => $exportShipment,
            'preselect' => [],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, ExportShipment $exportShipment): RedirectResponse
    {
        if (in_array($exportShipment->status, [ExportShipment::CLOSED, ExportShipment::CANCELLED], true)) {
            return back()->with('error', 'A '.Plain::status($exportShipment->status).' shipment is not edited.');
        }

        $data = $this->validated($request, $exportShipment);

        // Once shipped the customer and the credit are facts; the transport and customs references still arrive.
        if (! in_array($exportShipment->status, ExportShipment::EDITABLE, true)) {
            unset($data['customer_id'], $data['lc_id'], $data['currency_id'], $data['exchange_rate']);
        }

        $exportShipment->update($data);

        return redirect()->route('export-shipments.show', $exportShipment)->with('success', "Shipment {$exportShipment->number} updated.");
    }

    public function transition(Request $request, ExportShipment $exportShipment): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([ExportShipment::BOOKED, ExportShipment::SHIPPED, ExportShipment::PRESENTED, ExportShipment::DELIVERED, ExportShipment::CLOSED, ExportShipment::CANCELLED])],
            'shipped_on' => ['nullable', 'date'],
            'presented_on' => ['nullable', 'date'],
            'delivered_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($exportShipment, $data['status'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Shipment {$exportShipment->refresh()->number} is now ".Plain::status($data['status']).'.');
    }

    public function attachChallan(Request $request, ExportShipment $exportShipment): RedirectResponse
    {
        $data = $request->validate(['challan_id' => ['required', 'integer', 'exists:delivery_challans,id']]);

        $this->refuseUnless(in_array($exportShipment->status, ExportShipment::EDITABLE, true), 'Delivery notes are attached while the shipment is a draft or booked.');

        $this->attach($exportShipment, (int) $data['challan_id']);

        return back()->with('success', 'Delivery note attached.');
    }

    public function detachChallan(ExportShipment $exportShipment, int $challanId): RedirectResponse
    {
        $this->refuseUnless(in_array($exportShipment->status, ExportShipment::EDITABLE, true), 'Delivery notes are removed while the shipment is a draft or booked.');

        DB::table('delivery_challans')->where('id', $challanId)->where('export_shipment_id', $exportShipment->id)->update(['export_shipment_id' => null]);

        return back()->with('success', 'Delivery note removed from the shipment.');
    }

    /** DF-6 AC2 — a document typed in (number, date, file) against the shipment. */
    public function storeDocument(Request $request, ExportShipment $exportShipment): RedirectResponse
    {
        $data = $request->validate([
            'doc_type' => ['required', Rule::in(ExportDocumentChecklist::TYPES)],
            'doc_no' => ['required', 'string', 'max:80'],
            'doc_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(ExportDocument::STATUSES)],
            'remarks' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $this->refuseIf(in_array($exportShipment->status, [ExportShipment::CLOSED, ExportShipment::CANCELLED], true), 'A '.Plain::status($exportShipment->status).' shipment takes no more documents.');

        $path = $request->hasFile('file') ? $request->file('file')->store("export-documents/{$exportShipment->id}") : null;

        ExportDocument::query()->create([
            'export_shipment_id' => $exportShipment->id,
            'sales_order_id' => DB::table('delivery_challans')->where('export_shipment_id', $exportShipment->id)->value('sales_order_id'),
            'doc_type' => $data['doc_type'],
            'doc_no' => $data['doc_no'],
            'doc_date' => $data['doc_date'] ?? null,
            'file_path' => $path,
            'status' => $data['status'] ?? 'issued',
            'remarks' => $data['remarks'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // The transport and customs numbers live on the shipment too, so the list and the LC page can quote them.
        $mirror = match ($data['doc_type']) {
            'bl', 'awb' => ['transport_doc_no' => $data['doc_no'], 'transport_doc_date' => $data['doc_date'] ?? null],
            'exp_form' => ['exp_form_no' => $data['doc_no'], 'exp_form_date' => $data['doc_date'] ?? null],
            'ud' => ['ud_no' => $data['doc_no']],
            default => [],
        };

        if ($mirror !== []) {
            $exportShipment->forceFill(array_filter($mirror, fn ($v): bool => $v !== null))->save();
        }

        return back()->with('success', (ExportDocumentChecklist::LABELS[$data['doc_type']] ?? 'Document').' recorded.');
    }

    /** DF-6 AC1 — draft the commercial invoice, packing list or certificate of origin from the shipment data. */
    public function generateDocument(Request $request, ExportShipment $exportShipment, \App\Modules\Dispatch\Services\ExportDocumentGenerator $generator): RedirectResponse
    {
        $data = $request->validate(['doc_type' => ['required', Rule::in(array_keys(\App\Modules\Dispatch\Services\ExportDocumentGenerator::GENERATED))]]);

        $this->refuseIf(in_array($exportShipment->status, [ExportShipment::CLOSED, ExportShipment::CANCELLED], true), 'A '.Plain::status($exportShipment->status).' shipment takes no more documents.');

        $document = $generator->draft($exportShipment, $data['doc_type'], $request->user());

        return back()->with('success', (ExportDocumentChecklist::LABELS[$data['doc_type']] ?? 'Document')." drafted as {$document->doc_no}. Check it against the credit, then mark it issued.");
    }

    public function updateDocument(Request $request, ExportShipment $exportShipment, ExportDocument $document): RedirectResponse
    {
        abort_unless($document->export_shipment_id === $exportShipment->id, 404);

        $data = $request->validate(['status' => ['required', Rule::in(ExportDocument::STATUSES)]]);

        $document->update($data);

        return back()->with('success', (ExportDocumentChecklist::LABELS[$document->doc_type] ?? 'Document').' marked '.Plain::status($data['status']).'.');
    }

    public function destroyDocument(ExportShipment $exportShipment, ExportDocument $document): RedirectResponse
    {
        abort_unless($document->export_shipment_id === $exportShipment->id, 404);
        $this->refuseIf($exportShipment->status === ExportShipment::CLOSED, 'A closed shipment keeps its documents.');

        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    public function destroy(ExportShipment $exportShipment): RedirectResponse
    {
        $this->refuseUnless($exportShipment->status === ExportShipment::DRAFT, 'Only a draft shipment can be deleted; cancel the others.');

        DB::transaction(function () use ($exportShipment): void {
            DB::table('delivery_challans')->where('export_shipment_id', $exportShipment->id)->update(['export_shipment_id' => null]);
            $exportShipment->delete();
        });

        return redirect()->route('export-shipments.index')->with('success', 'Draft shipment deleted.');
    }

    private function attach(ExportShipment $shipment, int $challanId): void
    {
        $challan = DB::table('delivery_challans')->where('id', $challanId)->first();

        $this->refuseUnless($challan !== null && (int) $challan->customer_id === (int) $shipment->customer_id, "That delivery note is another customer's.", 'challan_id');
        $this->refuseUnless(in_array($challan->status, ['issued', 'in_transit', 'delivered'], true), 'Only an issued delivery note can travel on a shipment; this one is '.Plain::status($challan->status).'.', 'challan_id');
        $this->refuseUnless($challan->export_shipment_id === null || (int) $challan->export_shipment_id === (int) $shipment->id, 'That delivery note is already on another export shipment.', 'challan_id');

        DB::table('delivery_challans')->where('id', $challanId)->update(['export_shipment_id' => $shipment->id]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ExportShipment $shipment = null): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'lc_id' => ['nullable', 'integer', Rule::exists('letters_of_credit', 'id')->where('direction', 'export')->where('customer_id', $request->integer('customer_id'))],
            'mode' => ['required', Rule::in(ExportShipment::MODES)],
            'forwarder' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:120'],
            'vessel_or_flight' => ['nullable', 'string', 'max:120'],
            'container_no' => ['nullable', 'string', 'max:40'],
            'seal_no' => ['nullable', 'string', 'max:40'],
            'transport_doc_no' => ['nullable', 'string', 'max:60'],
            'transport_doc_date' => ['nullable', 'date'],
            'exp_form_no' => ['nullable', 'string', 'max:60'],
            'exp_form_date' => ['nullable', 'date'],
            'ud_no' => ['nullable', 'string', 'max:60'],
            'port_of_loading' => ['nullable', 'string', 'max:80'],
            'port_of_discharge' => ['nullable', 'string', 'max:80'],
            'final_destination' => ['nullable', 'string', 'max:120'],
            'incoterm' => ['nullable', 'string', 'max:20'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'goods_value' => ['nullable', 'numeric', 'min:0'],
            'gross_kg' => ['nullable', 'numeric', 'min:0'],
            'net_kg' => ['nullable', 'numeric', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ], [
            'lc_id.exists' => "That credit is not one of this customer's export LCs.",
        ]);

        $data['exchange_rate'] = $this->rates->resolve((int) $data['currency_id'], $data['exchange_rate'] ?? null, $data['etd'] ?? null);
        $data['goods_value'] ??= 0;

        return $data;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'customers' => DB::table('customers')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency_id']),
            'letters' => DB::table('letters_of_credit')
                ->where('direction', 'export')
                ->whereIn('status', ['advised', 'accepted', 'shipped', 'presented', 'discrepant'])
                ->orderByDesc('id')
                ->get(['id', 'number', 'lc_no', 'customer_id', 'currency_id', 'incoterm', 'port_of_loading', 'port_of_discharge']),
            'currencies' => $this->rates->currencyOptions(),
            'modes' => ExportShipment::MODES,
        ];
    }
}
