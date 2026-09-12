<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerAddress;
use App\Support\Audit\AuditLogger;
use App\Support\Reference\Countries;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Delivery and billing addresses, kept on the customer they belong to.
 *
 * They had no screen at all — the only way to add one was a seeder — and the consequence was
 * not cosmetic: a packing list resolves its destination through
 * `CustomerAddress::defaultDeliveryFor`, so a customer with no address reaches dispatch with
 * nowhere to send the cartons.
 *
 * Every route here is nested under the customer and re-checks that the address belongs to it,
 * because an id in a URL is a guess until it is verified.
 */
class CustomerAddressController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        $address = DB::transaction(function () use ($customer, $data): CustomerAddress {
            $address = CustomerAddress::query()->create([...$data, 'customer_id' => $customer->id]);

            $this->settleDefault($customer, $address);

            return $address;
        });

        $this->audit->recordTable('customer_addresses', $address->id, 'created', null, $address->attributesToArray());

        return back()->with('success', "Address “{$address->label}” added to {$customer->code}.");
    }

    public function update(Request $request, Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->assertOwnedBy($customer, $address);

        $before = $address->attributesToArray();
        $data = $this->validated($request);

        DB::transaction(function () use ($customer, $address, $data): void {
            $address->update($data);

            $this->settleDefault($customer, $address);
        });

        $this->audit->recordTable('customer_addresses', $address->id, 'updated', $before, $address->attributesToArray());

        return back()->with('success', "Address “{$address->label}” updated.");
    }

    /**
     * An address a shipped document already points at cannot be deleted — the foreign key
     * says so, and its refusal is translated rather than shown as SQL.
     */
    public function destroy(Customer $customer, CustomerAddress $address): RedirectResponse
    {
        $this->assertOwnedBy($customer, $address);

        $before = $address->attributesToArray();

        try {
            $address->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true) && str_contains($e->getMessage(), 'foreign key')) {
                return back()->with(
                    'error',
                    'This address is already named on a packing list or delivery challan and cannot be deleted.',
                );
            }

            throw $e;
        }

        $this->audit->recordTable('customer_addresses', $address->id, 'deleted', $before);

        return back()->with('success', "Address “{$address->label}” removed.");
    }

    /**
     * One default per customer. Nothing in the schema enforces it — `defaultDeliveryFor`
     * simply takes the first — so two ticked defaults would resolve by id, silently.
     */
    private function settleDefault(Customer $customer, CustomerAddress $address): void
    {
        if (! $address->is_default) {
            return;
        }

        CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('id', '!=', $address->getKey())
            ->update(['is_default' => false]);
    }

    private function assertOwnedBy(Customer $customer, CustomerAddress $address): void
    {
        abort_unless($address->customer_id === $customer->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:80'],
            // Mirrors customer_addresses_kind_chk; anything else aborts the insert.
            'kind' => ['required', Rule::in(['billing', 'delivery', 'both'])],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'district' => ['nullable', 'string', 'max:80'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:60', Rule::in(Countries::names())],
            // BR-29 — the transit allowance this address adds to a promised date.
            'transit_days' => ['required', 'integer', 'min:0', 'max:365'],
            'route_zone' => ['nullable', 'string', 'max:60'],
            'is_default' => ['boolean'],
        ]);
    }
}
