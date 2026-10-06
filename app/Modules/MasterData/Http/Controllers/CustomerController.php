<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Support\Http\ListsResources;
use App\Support\Reference\Countries;
use App\Support\Reference\Vocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers hold the commercial guard rails the rules read: `credit_limit` (BR-46),
 * `min_order_value` (BR-21) and the delivery tolerances that default onto every order line
 * (BR-44).
 */
class CustomerController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $inFlight = ['confirmed', 'in_production', 'partially_delivered'];
        $today = now()->toDateString();

        // What a salesperson scans a customer list for: what is on order, what is owed, when
        // they last ordered and what is out for quotation. The limits that used to fill the
        // row are settings, and live on the page.
        $query = Customer::query()
            ->with('currency:id,code')
            ->withCount('contacts')
            ->addSelect([
                'open_order_value' => DB::table('sales_orders')
                    ->selectRaw('COALESCE(SUM(total * exchange_rate), 0)')
                    ->whereColumn('sales_orders.customer_id', 'customers.id')
                    ->whereIn('status', $inFlight),
                'open_order_count' => DB::table('sales_orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('sales_orders.customer_id', 'customers.id')
                    ->whereIn('status', $inFlight),
                'outstanding' => DB::table('sales_invoices')
                    ->selectRaw('COALESCE(SUM((total - received_amount) * exchange_rate), 0)')
                    ->whereColumn('sales_invoices.customer_id', 'customers.id')
                    ->whereNotIn('status', ['draft', 'cancelled', 'paid']),
                'overdue' => DB::table('sales_invoices')
                    ->selectRaw('COALESCE(SUM((total - received_amount) * exchange_rate), 0)')
                    ->whereColumn('sales_invoices.customer_id', 'customers.id')
                    ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
                    ->whereDate('due_date', '<', $today),
                'last_order_on' => DB::table('sales_orders')
                    ->selectRaw('MAX(order_date)')
                    ->whereColumn('sales_orders.customer_id', 'customers.id')
                    ->whereNotIn('status', ['draft', 'cancelled']),
                'quotations_out' => DB::table('quotations')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('quotations.customer_id', 'customers.id')
                    ->where('status', 'sent'),
            ]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'email', 'phone'],
            filters: ['active' => 'is_active', 'kind' => 'kind'],
            sortable: ['code', 'name', 'credit_limit', 'open_order_value', 'outstanding', 'last_order_on'],
            defaultSort: 'name',
        );

        return Inertia::render('MasterData/Customers/Index', [
            'customers' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Customer $customer): array => [
                    ...$customer->only(['id', 'code', 'name', 'kind', 'email', 'phone', 'credit_limit', 'is_active', 'contacts_count']),
                    'currency' => $customer->currency?->code,
                    'open_order_value' => round((float) $customer->open_order_value, 2),
                    'open_order_count' => (int) $customer->open_order_count,
                    'outstanding' => round((float) $customer->outstanding, 2),
                    'overdue' => round((float) $customer->overdue, 2),
                    'last_order_on' => $customer->last_order_on,
                    'quotations_out' => (int) $customer->quotations_out,
                ],
            ),
            'filters' => $this->listingFilters($request, ['active', 'kind']),
            'kinds' => Vocabulary::options('customer_kind'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Customers/Form', ['customer' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // The first contact and the delivery address, when the form gave them: a customer with
        // nobody to call and nowhere to ship is a record that cannot be worked yet.
        $extras = $request->validate([
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_designation' => ['nullable', 'string', 'max:80'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address_label' => ['nullable', 'string', 'max:80'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_city' => ['nullable', 'string', 'max:80'],
            'address_country' => ['nullable', 'string', 'max:60', Rule::in(Countries::names())],
            'address_transit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $customer = DB::transaction(function () use ($data, $extras): Customer {
            $customer = Customer::query()->create($data);

            if (trim((string) ($extras['contact_name'] ?? '')) !== '') {
                $customer->contacts()->create([
                    'name' => $extras['contact_name'],
                    'designation' => $extras['contact_designation'] ?? null,
                    'email' => $extras['contact_email'] ?? null,
                    'phone' => $extras['contact_phone'] ?? null,
                    'is_primary' => true,
                ]);
            }

            if (trim((string) ($extras['address_line1'] ?? '')) !== '') {
                $customer->addresses()->create([
                    'label' => $extras['address_label'] ?: 'Factory',
                    'kind' => 'both',
                    'line1' => $extras['address_line1'],
                    'city' => $extras['address_city'] ?? null,
                    'country' => $extras['address_country'] ?? 'Bangladesh',
                    'transit_days' => (int) ($extras['address_transit_days'] ?? 1),
                    'is_default' => true,
                ]);
            }

            return $customer;
        });

        // "Created." was true and useless: an inactive customer is created just as
        // successfully and then cannot be found in any picker, which reads as a lost save.
        return redirect()->route('customers.show', $customer)->with(
            'success',
            $customer->is_active
                ? "Customer {$customer->code} created and available to quote and order against."
                : "Customer {$customer->code} created, but marked inactive — it will not appear in order or quotation pickers until it is activated.",
        );
    }

    public function show(Customer $customer): Response
    {
        $customer->load(['contacts', 'addresses', 'currency:id,code,name', 'paymentTerm:id,code,name,net_days']);

        $inFlight = ['confirmed', 'in_production', 'partially_delivered'];
        $today = now()->toDateString();

        // BR-46 — what the customer owes and what they have on order, both in the base
        // currency, because the limit is stated in it. "Outstanding" alone understated the
        // exposure: a customer with nothing invoiced and a million pieces confirmed read as
        // zero against the limit.
        $outstanding = (float) DB::table('sales_invoices')
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->sum(DB::raw('(total - received_amount) * exchange_rate'));
        $overdue = (float) DB::table('sales_invoices')
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->whereDate('due_date', '<', $today)
            ->sum(DB::raw('(total - received_amount) * exchange_rate'));
        $openOrders = DB::table('sales_orders')
            ->where('customer_id', $customer->id)
            ->whereIn('status', $inFlight)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total * exchange_rate), 0) AS value')
            ->first();
        $lifetime = DB::table('sales_invoices')
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total * exchange_rate), 0) AS value')
            ->first();

        return Inertia::render('MasterData/Customers/Show', [
            'customer' => [
                ...$customer->only([
                    'id', 'code', 'name', 'kind', 'email', 'phone', 'bin_no', 'tin_no', 'credit_limit',
                    'min_order_value', 'over_tolerance_pct', 'under_tolerance_pct', 'is_active',
                ]),
                'currency' => $customer->currency?->only(['id', 'code', 'name']),
                'payment_term' => $customer->paymentTerm?->only(['id', 'code', 'name', 'net_days']),
                // The country is the default address's, not a column of the customer's.
                'country' => $customer->addresses->sortByDesc('is_default')->first()?->country,
            ],
            'contacts' => $customer->contacts->sortByDesc('is_primary')->values()
                ->map(fn ($contact) => $contact->only(['id', 'name', 'designation', 'email', 'phone', 'is_primary'])),
            'stats' => [
                'open_order_count' => (int) ($openOrders->n ?? 0),
                'open_order_value' => round((float) ($openOrders->value ?? 0), 2),
                'outstanding' => round($outstanding, 2),
                'overdue' => round($overdue, 2),
                'exposure' => round($outstanding + (float) ($openOrders->value ?? 0), 2),
                'credit_limit' => (float) $customer->credit_limit,
                'last_order_on' => DB::table('sales_orders')->where('customer_id', $customer->id)
                    ->whereNotIn('status', ['draft', 'cancelled'])->max('order_date'),
                'quotations_out' => (int) DB::table('quotations')->where('customer_id', $customer->id)->where('status', 'sent')->count(),
                'inquiries_open' => (int) DB::table('inquiries')->where('customer_id', $customer->id)->whereIn('status', ['open', 'quoted'])->count(),
                'lifetime_invoiced' => round((float) ($lifetime->value ?? 0), 2),
                'invoice_count' => (int) ($lifetime->n ?? 0),
            ],
            // Addresses and brands are maintained here rather than in Setup: both belong to
            // exactly this account, and a delivery address is what a packing list resolves
            // its destination through.
            'addresses' => $customer->addresses->sortByDesc('is_default')->values(),
            'brands' => Brand::query()->where('customer_id', $customer->id)
                ->orderBy('code')->get(['id', 'code', 'name', 'is_active']),
            'countries' => Countries::options(),
            'products' => DB::table('products')->where('customer_id', $customer->id)
                ->orderBy('code')->get(['id', 'code', 'name', 'product_type', 'status']),
            'openOrders' => DB::table('v_order_book')->where('customer_id', $customer->id)
                ->orderBy('promised_date')->limit(20)->get(),
            // The relationship, not only the settings: what they asked for, what was offered,
            // what is unpaid, and the rates agreed with them.
            'inquiries' => DB::table('inquiries')->where('customer_id', $customer->id)
                ->orderByDesc('id')->limit(8)->get(['id', 'number', 'inquiry_date', 'required_by', 'status']),
            'quotations' => DB::table('quotations as q')->leftJoin('currencies as cur', 'cur.id', '=', 'q.currency_id')
                ->where('q.customer_id', $customer->id)
                ->orderByDesc('q.id')->limit(8)
                ->get(['q.id', 'q.number', 'q.revision_no', 'q.quotation_date', 'q.valid_until', 'q.total', 'q.status', 'cur.code as currency']),
            'invoices' => DB::table('sales_invoices as si')->leftJoin('currencies as cur', 'cur.id', '=', 'si.currency_id')
                ->where('si.customer_id', $customer->id)
                ->whereNotIn('si.status', ['draft', 'cancelled', 'paid'])
                ->orderBy('si.due_date')->limit(10)
                ->get(['si.id', 'si.number', 'si.invoice_date', 'si.due_date', 'si.total', 'si.received_amount', 'si.status', 'cur.code as currency']),
            'priceLists' => DB::table('price_lists as pl')->leftJoin('currencies as cur', 'cur.id', '=', 'pl.currency_id')
                ->where('pl.customer_id', $customer->id)
                ->orderByDesc('pl.valid_from')
                ->get(['pl.id', 'pl.code', 'pl.name', 'pl.valid_from', 'pl.valid_to', 'pl.is_active', 'cur.code as currency']),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('MasterData/Customers/Form', ['customer' => $customer, ...$this->options()]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request, $customer));

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->code} updated.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('customers.index')->with('success', "Customer {$customer->code} archived.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Customer $customer = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('customers', 'code')->ignore($customer?->id)],
            'name' => ['required', 'string', 'max:180'],
            'kind' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'buying_house_id' => ['nullable', 'integer', 'exists:buying_houses,id'],
            'agent_id' => ['nullable', 'integer', 'exists:agents,id'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            // BR-46/BR-51 — a base-currency figure, like `min_order_value` beside it. The
            // customer's own `currency_id` is what they are traded in, not what their limits
            // are stated in; the credit decision converts every document to base before it
            // compares against this.
            'credit_limit' => ['numeric', 'min:0'],
            'min_order_value' => ['numeric', 'min:0'],
            'over_tolerance_pct' => ['numeric', 'min:0', 'max:100'],
            'under_tolerance_pct' => ['numeric', 'min:0', 'max:100'],
            'bin_no' => ['nullable', 'string', 'max:40'],
            'tin_no' => ['nullable', 'string', 'max:40'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'paymentTerms' => PaymentTerm::query()->orderBy('net_days')->get(['id', 'code', 'name', 'net_days']),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['id', 'code', 'name']),
            // From the vocabulary registry rather than typed into the page: the same four
            // kinds were written out by hand on the form and worded differently in the list.
            'kinds' => Vocabulary::options('customer_kind'),
            'countries' => Countries::options(),
        ];
    }
}
