<?php

declare(strict_types=1);

namespace App\Modules\Trade\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Modules\Trade\Services\LcAmendmentService;
use App\Modules\Trade\Services\LcPosition;
use App\Modules\Trade\States\ExportLcStateMachine;
use App\Support\Audit\DocumentTrail;
use App\Support\Currency\ExchangeRateResolver;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Http\RefusesActions;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
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
 * Export letters of credit — the credits customers' banks open in our favour, covering the
 * sales orders shipped against them. The Sales twin of the Buying screen: same table, same
 * amendments and currency rule, the beneficiary's lifecycle instead of the applicant's.
 */
class ExportLetterOfCreditController extends Controller
{
    use ContextualId;
    use ListsResources;
    use RefusesActions;

    /** Once accepted the terms belong to the bank; only these still move without an amendment. */
    private const EDITABLE_AFTER_ACCEPTED = ['advising_bank_account_id', 'charges_amount', 'remarks', 'issuing_bank'];

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly ExchangeRateResolver $rates,
        private readonly ExportLcStateMachine $states,
        private readonly LcAmendmentService $amendments,
        private readonly DocumentTrail $trail,
        private readonly Settings $settings,
        private readonly LcPosition $position,
    ) {}

    public function index(Request $request): Response
    {
        $query = LetterOfCredit::query()->export()->with(['customer:id,code,name', 'advisingBankAccount:id,code,name', 'currency:id,code']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'lc_no'],
            filters: ['status' => 'status', 'customer' => 'customer_id', 'kind' => 'kind'],
            sortable: ['number', 'issued_on', 'expiry_date', 'last_shipment_date', 'amount', 'status'],
            defaultSort: '-id',
        );

        $live = LetterOfCredit::EXPORT_LIVE;
        $soon = now()->addDays(14)->toDateString();

        if ($request->query('expiring') === '1') {
            $query->whereIn('status', $live)->where(fn ($q) => $q
                ->whereDate('expiry_date', '<=', $soon)
                ->orWhere(fn ($q2) => $q2->whereDate('last_shipment_date', '<=', $soon)->whereIn('status', [LetterOfCredit::ADVISED, LetterOfCredit::ACCEPTED])));
        }

        $presentationDays = $this->settings->int('export_presentation_days', 21);

        if ($request->query('unpresented') === '1') {
            $query->where('status', LetterOfCredit::SHIPPED)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('delivery_challans as dc')
                    ->join('lc_sales_orders as lso', 'lso.sales_order_id', '=', 'dc.sales_order_id')
                    ->whereColumn('lso.lc_id', 'letters_of_credit.id')
                    ->whereDate('dc.challan_date', '<=', now()->subDays($presentationDays)->toDateString()));
        }

        return Inertia::render('Trade/ExportLettersOfCredit/Index', [
            'letters' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (LetterOfCredit $lc): array => [
                    ...$lc->only(['id', 'number', 'lc_no', 'kind', 'amount', 'status', 'issued_on', 'expiry_date', 'last_shipment_date', 'presented_on', 'realised_on']),
                    'customer' => $lc->customer?->name,
                    'bank' => $lc->advisingBankAccount?->name,
                    'currency' => $lc->currency?->code,
                ],
            ),
            'filters' => $this->listingFilters($request, ['status', 'customer', 'kind', 'expiring', 'unpresented']),
            'counts' => [
                ...LetterOfCredit::query()->export()->selectRaw('status as stage, COUNT(*) as n')->groupBy('status')->pluck('n', 'stage')->map(fn ($n): int => (int) $n)->all(),
                'expiring' => LetterOfCredit::query()->export()->whereIn('status', $live)
                    ->where(fn ($q) => $q->whereDate('expiry_date', '<=', $soon)
                        ->orWhere(fn ($q2) => $q2->whereDate('last_shipment_date', '<=', $soon)->whereIn('status', [LetterOfCredit::ADVISED, LetterOfCredit::ACCEPTED])))
                    ->count(),
            ],
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'code', 'name']),
            'kinds' => LetterOfCredit::EXPORT_KINDS,
            'statuses' => LetterOfCredit::EXPORT_STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        $customerId = $this->contextualId($request, 'customer', ['customer.view_any', 'customer.view']);

        return Inertia::render('Trade/ExportLettersOfCredit/Form', [
            'letter' => null,
            'preselectCustomerId' => $customerId,
            ...$this->options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $letter = DB::transaction(fn (): LetterOfCredit => LetterOfCredit::query()->create([
            ...$data,
            'direction' => LetterOfCredit::EXPORT,
            // BR-34 — advised is the first real status, so our number is given on save.
            'number' => $this->numbers->next('export_lc'),
            'status' => LetterOfCredit::ADVISED,
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('export-lcs.show', $letter)
            ->with('success', "Export LC {$letter->number} recorded as advised. Accept it once the terms are checked.");
    }

    public function show(Request $request, LetterOfCredit $exportLc): Response
    {
        $this->assertExport($exportLc);

        $exportLc->load(['customer:id,code,name', 'advisingBankAccount:id,code,name,bank_name', 'currency:id,code,name,symbol', 'amendments' => fn ($q) => $q->orderBy('amendment_no')]);

        return Inertia::render('Trade/ExportLettersOfCredit/Show', [
            'letter' => [
                ...$exportLc->only(['id', 'number', 'lc_no', 'kind', 'issuing_bank', 'amount', 'tolerance_pct', 'tenor_days', 'charges_amount',
                    'issued_on', 'expiry_date', 'last_shipment_date', 'latest_presentation_days', 'partial_shipment', 'transhipment',
                    'incoterm', 'port_of_loading', 'port_of_discharge', 'status', 'remarks', 'presented_on', 'realised_on', 'realisation_ref']),
                'customer' => $exportLc->customer?->only(['id', 'code', 'name']),
                'bank_name' => $exportLc->advisingBankAccount === null ? null : ($exportLc->advisingBankAccount->bank_name ?? $exportLc->advisingBankAccount->name),
                'current_amount' => $exportLc->currentAmount(),
                'effective_expiry' => $exportLc->effectiveExpiry(),
                'currency' => $exportLc->currency?->code,
            ],
            'amendments' => $exportLc->amendments,
            'salesOrders' => DB::table('lc_sales_orders as lso')
                ->join('sales_orders as so', 'so.id', '=', 'lso.sales_order_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'so.currency_id')
                ->where('lso.lc_id', $exportLc->id)
                ->orderBy('so.number')
                ->get(['so.id', 'so.number', 'so.order_date', 'so.delivery_date', 'so.total', 'so.status', 'lso.covered_amount', 'cur.code as currency']),
            // BR-56 — only orders this credit can pay for: same customer, same currency, live, not attached.
            'availableSalesOrders' => DB::table('sales_orders')
                ->where('customer_id', $exportLc->customer_id)
                ->where('currency_id', $exportLc->currency_id)
                ->whereNotIn('status', ['cancelled', 'closed'])
                ->whereNotIn('id', fn ($q) => $q->from('lc_sales_orders')->where('lc_id', $exportLc->id)->select('sales_order_id'))
                ->orderByDesc('id')
                ->get(['id', 'number', 'total', 'status']),
            // Back-to-back import credits opened against this master; the limit is stated, not enforced.
            'backToBack' => DB::table('letters_of_credit as lc')
                ->leftJoin('suppliers as s', 's.id', '=', 'lc.supplier_id')
                ->leftJoin('currencies as cur', 'cur.id', '=', 'lc.currency_id')
                ->where('lc.master_lc_id', $exportLc->id)
                ->orderBy('lc.id')
                ->get(['lc.id', 'lc.number', 'lc.lc_no', 'lc.amount', 'lc.status', 's.name as supplier', 'cur.code as currency']),
            'btbLimitPct' => $this->settings->decimal('btb_limit_pct', 75),
            'position' => $this->position->for($exportLc),
            'invoices' => DB::table('sales_invoices')->where('lc_id', $exportLc->id)->orderByDesc('id')->get(['id', 'number', 'invoice_date', 'total', 'received_amount', 'status']),
            'availableTransitions' => $this->states->available($exportLc),
            'trail' => $this->trail->for($exportLc, $request->user()),
        ]);
    }

    public function edit(LetterOfCredit $exportLc): Response
    {
        $this->assertExport($exportLc);

        return Inertia::render('Trade/ExportLettersOfCredit/Form', [
            'letter' => $exportLc->only(['id', 'number', 'lc_no', 'kind', 'customer_id', 'issuing_bank', 'advising_bank_account_id', 'currency_id', 'exchange_rate',
                'amount', 'tolerance_pct', 'tenor_days', 'charges_amount', 'issued_on', 'expiry_date', 'last_shipment_date', 'latest_presentation_days',
                'partial_shipment', 'transhipment', 'incoterm', 'port_of_loading', 'port_of_discharge', 'remarks', 'status']),
            'preselectCustomerId' => null,
            ...$this->options(),
        ]);
    }

    public function update(Request $request, LetterOfCredit $exportLc): RedirectResponse
    {
        $this->assertExport($exportLc);

        $data = $this->validated($request, $exportLc);

        if ($exportLc->status !== LetterOfCredit::ADVISED) {
            $data = array_intersect_key($data, array_flip(self::EDITABLE_AFTER_ACCEPTED));
        }

        // BR-56 — the currency is fixed once an order is drawn on the credit.
        if (array_key_exists('currency_id', $data) && (int) $data['currency_id'] !== (int) $exportLc->currency_id
            && DB::table('lc_sales_orders')->where('lc_id', $exportLc->id)->exists()) {
            throw ValidationException::withMessages(['currency_id' => 'This credit already covers an order in its currency. Remove the orders before changing the currency of the credit.']);
        }

        $exportLc->update($data);

        return redirect()->route('export-lcs.show', $exportLc)->with('success', "Export LC {$exportLc->number} updated.");
    }

    public function transition(Request $request, LetterOfCredit $exportLc): RedirectResponse
    {
        $this->assertExport($exportLc);

        $data = $request->validate([
            'status' => ['required', Rule::in([LetterOfCredit::ACCEPTED, LetterOfCredit::PRESENTED, LetterOfCredit::DISCREPANT, LetterOfCredit::REALISED, LetterOfCredit::CLOSED, LetterOfCredit::CANCELLED])],
            'reason' => ['nullable', 'string', 'max:500'],
            'presented_on' => ['nullable', 'date'],
            'realised_on' => ['nullable', 'date'],
            'realisation_ref' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->states->transition($exportLc, $data['status'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Export LC {$exportLc->number} is now ".Plain::status($data['status']).'.');
    }

    /** TR-E3 — cover a sales order with this credit. */
    public function attachOrder(Request $request, LetterOfCredit $exportLc): RedirectResponse
    {
        $this->assertExport($exportLc);

        $data = $request->validate([
            'sales_order_id' => ['required', 'integer', 'exists:sales_orders,id'],
            'covered_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $order = DB::table('sales_orders')->where('id', $data['sales_order_id'])->first();

        $this->refuseUnless($order !== null && (int) $order->customer_id === (int) $exportLc->customer_id, 'That order is for a different customer than the credit.');
        $this->refuseUnless((int) $order->currency_id === (int) $exportLc->currency_id, 'That order is in a different currency from the credit and cannot be covered by it.');

        DB::table('lc_sales_orders')->updateOrInsert(
            ['lc_id' => $exportLc->id, 'sales_order_id' => $data['sales_order_id']],
            ['covered_amount' => $data['covered_amount'] ?? $order->total],
        );

        return back()->with('success', "Order {$order->number} is covered by this credit.");
    }

    public function detachOrder(LetterOfCredit $exportLc, int $salesOrderId): RedirectResponse
    {
        $this->assertExport($exportLc);

        DB::table('lc_sales_orders')->where('lc_id', $exportLc->id)->where('sales_order_id', $salesOrderId)->delete();

        return back()->with('success', 'Order removed from this credit.');
    }

    public function amend(Request $request, LetterOfCredit $exportLc): RedirectResponse
    {
        $this->assertExport($exportLc);
        $this->refuseUnless(in_array($exportLc->status, LetterOfCredit::EXPORT_LIVE, true), 'Only a live credit can be amended.');

        $data = $request->validate([
            'amended_on' => ['required', 'date'],
            'amount_delta' => ['numeric'],
            'new_expiry_date' => ['nullable', 'date'],
            'new_last_shipment_date' => ['nullable', 'date', 'before_or_equal:new_expiry_date'],
            'charges_amount' => ['numeric', 'min:0'],
            'narrative' => ['nullable', 'string', 'max:500'],
        ]);

        $this->amendments->record($exportLc, $data, $request->user()->id);

        return back()->with('success', 'Amendment recorded.');
    }

    public function destroy(LetterOfCredit $exportLc): RedirectResponse
    {
        $this->assertExport($exportLc);
        $this->refuseUnless($exportLc->status === LetterOfCredit::ADVISED, 'Only an advised credit can be deleted; cancel the others.');

        $exportLc->delete();

        return redirect()->route('export-lcs.index')->with('success', 'Credit deleted.');
    }

    private function assertExport(LetterOfCredit $letter): void
    {
        if ($letter->direction !== LetterOfCredit::EXPORT) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?LetterOfCredit $letter = null): array
    {
        $data = $request->validate([
            // The customer's bank has already issued it, so its number is known from day one.
            'lc_no' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(LetterOfCredit::EXPORT_KINDS)],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'issuing_bank' => ['nullable', 'string', 'max:120'],
            'advising_bank_account_id' => ['nullable', 'integer', 'exists:bank_accounts,id'],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tolerance_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tenor_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'charges_amount' => ['nullable', 'numeric', 'min:0'],
            'issued_on' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'last_shipment_date' => ['nullable', 'date', 'before_or_equal:expiry_date'],
            'latest_presentation_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'partial_shipment' => ['boolean'],
            'transhipment' => ['boolean'],
            'incoterm' => ['nullable', 'string', 'max:20'],
            'port_of_loading' => ['nullable', 'string', 'max:80'],
            'port_of_discharge' => ['nullable', 'string', 'max:80'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $data['exchange_rate'] = $this->rates->resolve((int) $data['currency_id'], $data['exchange_rate'] ?? null, $data['issued_on'] ?? null);
        $data['latest_presentation_days'] ??= $this->settings->int('export_presentation_days', 21);
        $data['tolerance_pct'] ??= 0;
        $data['tenor_days'] ??= 0;
        $data['charges_amount'] ??= 0;
        $data['partial_shipment'] = (bool) ($data['partial_shipment'] ?? true);
        $data['transhipment'] = (bool) ($data['transhipment'] ?? true);

        return $data;
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'customers' => DB::table('customers')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'currency_id']),
            'bankAccounts' => DB::table('bank_accounts')->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'bank_name', 'currency_id']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            'kinds' => LetterOfCredit::EXPORT_KINDS,
            'presentationDays' => $this->settings->int('export_presentation_days', 21),
        ];
    }
}
