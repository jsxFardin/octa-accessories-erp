<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Support\Http\ListsResources;
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
        $query = Customer::query()->withCount('contacts');

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'email'],
            filters: ['active' => 'is_active', 'kind' => 'kind'],
            sortable: ['code', 'name', 'credit_limit'],
            defaultSort: 'name',
        );

        return Inertia::render('MasterData/Customers/Index', [
            'customers' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['active', 'kind']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Customers/Form', ['customer' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::query()->create($this->validated($request));

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->code} created.");
    }

    public function show(Customer $customer): Response
    {
        $customer->load(['contacts', 'addresses']);

        return Inertia::render('MasterData/Customers/Show', [
            'customer' => $customer,
            'products' => DB::table('products')->where('customer_id', $customer->id)
                ->orderBy('code')->get(['id', 'code', 'name', 'product_type', 'status']),
            'openOrders' => DB::table('v_order_book')->where('customer_id', $customer->id)
                ->orderBy('promised_date')->limit(20)->get(),
            'outstanding' => (float) DB::table('sales_invoices')
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->sum(DB::raw('total - received_amount')),
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
        ];
    }
}
