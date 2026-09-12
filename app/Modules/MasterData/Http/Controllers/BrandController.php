<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A customer's brands, kept on the customer.
 *
 * A brand belongs to exactly one account — it is the label the customer's own garments carry
 * — so it is added where that account is, not in Setup behind a customer dropdown that has to
 * be picked correctly a second time.
 *
 * `brands.code` is unique across the whole table, not per customer, because a product's code
 * is read off it and two accounts sharing a code would make that reference ambiguous.
 */
class BrandController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $brand = Brand::query()->create([
            ...$this->validated($request),
            'customer_id' => $customer->id,
        ]);

        $this->audit->recordTable('brands', $brand->id, 'created', null, $brand->attributesToArray());

        return back()->with('success', "Brand {$brand->code} added to {$customer->code}.");
    }

    public function update(Request $request, Customer $customer, Brand $brand): RedirectResponse
    {
        $this->assertOwnedBy($customer, $brand);

        $before = $brand->attributesToArray();

        $brand->update($this->validated($request, $brand));

        $this->audit->recordTable('brands', $brand->id, 'updated', $before, $brand->attributesToArray());

        return back()->with('success', "Brand {$brand->code} updated.");
    }

    /**
     * A brand a product is filed under stays. Deactivating is the way to retire one, and the
     * message says so rather than leaving the user to guess at the database's refusal.
     */
    public function destroy(Customer $customer, Brand $brand): RedirectResponse
    {
        $this->assertOwnedBy($customer, $brand);

        $before = $brand->attributesToArray();

        try {
            $brand->delete();
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23000', '23503'], true) && str_contains($e->getMessage(), 'foreign key')) {
                return back()->with(
                    'error',
                    "Products are filed under {$brand->code}, so it cannot be deleted. Untick Active to retire it instead.",
                );
            }

            throw $e;
        }

        $this->audit->recordTable('brands', $brand->id, 'deleted', $before);

        return back()->with('success', "Brand {$brand->code} removed.");
    }

    private function assertOwnedBy(Customer $customer, Brand $brand): void
    {
        abort_unless($brand->customer_id === $customer->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('brands', 'code')->ignore($brand?->getKey())],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['boolean'],
        ]);
    }
}
