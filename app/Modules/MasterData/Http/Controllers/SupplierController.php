<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Supplier;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Yarn from the UK, Turkey, China, Hong Kong and India; ribbon from China and India; ink from
 * the UK. Lead time is per supplier-item, not global (BR-26), which is why `supplier_items`
 * exists rather than a single column here.
 */
class SupplierController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Supplier::query();

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'country'],
            filters: ['active' => 'is_active', 'approved' => 'is_approved', 'country' => 'country'],
            sortable: ['code', 'name', 'country', 'rating'],
            defaultSort: 'name',
        );

        return Inertia::render('MasterData/Suppliers/Index', [
            'suppliers' => $query->paginate($this->perPage($request))->withQueryString(),
            'filters' => $this->listingFilters($request, ['active', 'approved', 'country']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Suppliers/Form', ['supplier' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::query()->create($this->validated($request));

        return redirect()->route('suppliers.show', $supplier)->with('success', "Supplier {$supplier->code} created.");
    }

    public function show(Supplier $supplier): Response
    {
        return Inertia::render('MasterData/Suppliers/Show', [
            'supplier' => $supplier,
            'items' => DB::table('supplier_items as si')
                ->join('items as i', 'i.id', '=', 'si.item_id')
                ->where('si.supplier_id', $supplier->id)
                ->orderBy('i.code')
                ->get(['si.id', 'i.code', 'i.name', 'si.supplier_code', 'si.last_rate', 'si.lead_time_days', 'si.moq']),
            'purchaseOrders' => DB::table('purchase_orders')->where('supplier_id', $supplier->id)
                ->orderByDesc('id')->limit(20)->get(['id', 'number', 'order_date', 'status', 'total']),
        ]);
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('MasterData/Suppliers/Form', ['supplier' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return redirect()->route('suppliers.show', $supplier)->with('success', "Supplier {$supplier->code} updated.");
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', "Supplier {$supplier->code} archived.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('suppliers', 'code')->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:180'],
            'country' => ['nullable', 'string', 'max:60'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'payment_term_id' => ['nullable', 'integer', 'exists:payment_terms,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            // A PO may not be submitted to an unapproved supplier (05-workflows §7).
            'is_approved' => ['boolean'],
            'is_active' => ['boolean'],
            'bin_no' => ['nullable', 'string', 'max:40'],
            'tin_no' => ['nullable', 'string', 'max:40'],
        ]);
    }
}
