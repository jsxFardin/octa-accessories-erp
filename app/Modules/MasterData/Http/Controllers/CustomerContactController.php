<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerContact;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people at the customer, kept on the customer they belong to.
 *
 * The table, the model and the relation existed and the inquiry form read them, but no
 * screen could add or change one: every inquiry's "Contact" was a list of whatever a seeder
 * had left behind. Same shape as addresses — nested under the customer, ownership re-checked,
 * one primary at a time.
 */
class CustomerContactController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validated($request);

        $contact = DB::transaction(function () use ($customer, $data): CustomerContact {
            $contact = CustomerContact::query()->create([...$data, 'customer_id' => $customer->id]);

            $this->settlePrimary($customer, $contact);

            return $contact;
        });

        $this->audit->recordTable('customer_contacts', $contact->id, 'created', null, $contact->attributesToArray());

        return back()->with('success', "{$contact->name} added to {$customer->code}.");
    }

    public function update(Request $request, Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->assertOwnedBy($customer, $contact);

        $before = $contact->attributesToArray();
        $data = $this->validated($request);

        DB::transaction(function () use ($customer, $contact, $data): void {
            $contact->update($data);

            $this->settlePrimary($customer, $contact);
        });

        $this->audit->recordTable('customer_contacts', $contact->id, 'updated', $before, $contact->attributesToArray());

        return back()->with('success', "{$contact->name} updated.");
    }

    /** A contact an inquiry already names cannot be deleted; the refusal is said in words. */
    public function destroy(Customer $customer, CustomerContact $contact): RedirectResponse
    {
        $this->assertOwnedBy($customer, $contact);

        $before = $contact->attributesToArray();

        try {
            $contact->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true) && str_contains($e->getMessage(), 'foreign key')) {
                return back()->with('error', "{$contact->name} is named on an inquiry and cannot be removed.");
            }

            throw $e;
        }

        $this->audit->recordTable('customer_contacts', $contact->id, 'deleted', $before);

        return back()->with('success', "{$contact->name} removed.");
    }

    /** One primary per customer: the one a new inquiry suggests. */
    private function settlePrimary(Customer $customer, CustomerContact $contact): void
    {
        if (! $contact->is_primary) {
            return;
        }

        CustomerContact::query()
            ->where('customer_id', $customer->id)
            ->where('id', '!=', $contact->getKey())
            ->update(['is_primary' => false]);
    }

    private function assertOwnedBy(Customer $customer, CustomerContact $contact): void
    {
        abort_unless($contact->customer_id === $customer->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'designation' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['boolean'],
        ]);
    }
}
