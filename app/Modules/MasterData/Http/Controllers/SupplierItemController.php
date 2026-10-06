<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierItem;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a supplier sells us, and on what terms: their code for it, the last rate, and the lead
 * time and minimum order that are theirs for this material rather than the supplier's in
 * general (BR-26).
 *
 * The supplier page listed these rows and no screen could add one, so a new supplier's list
 * stayed empty until something else happened to write to the table.
 */
class SupplierItemController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'item_id' => [
                'required', 'integer', 'exists:items,id',
                Rule::unique('supplier_items', 'item_id')->where('supplier_id', $supplier->id),
            ],
            ...$this->terms(),
        ], ['item_id.unique' => 'This material is already on the list. Edit that row instead.']);

        $link = SupplierItem::query()->create([...$data, 'supplier_id' => $supplier->id]);

        $this->audit->recordTable('supplier_items', $link->id, 'created', null, $link->attributesToArray());

        return back()->with('success', "Material added to {$supplier->code}.");
    }

    /** The material itself is not changed on an existing row: that is a different row. */
    public function update(Request $request, Supplier $supplier, SupplierItem $item): RedirectResponse
    {
        $this->assertOwnedBy($supplier, $item);

        $before = $item->attributesToArray();

        $item->update($request->validate($this->terms()));

        $this->audit->recordTable('supplier_items', $item->id, 'updated', $before, $item->attributesToArray());

        return back()->with('success', 'Material terms updated.');
    }

    public function destroy(Supplier $supplier, SupplierItem $item): RedirectResponse
    {
        $this->assertOwnedBy($supplier, $item);

        $before = $item->attributesToArray();

        $item->delete();

        $this->audit->recordTable('supplier_items', $item->id, 'deleted', $before);

        return back()->with('success', "Material removed from {$supplier->code}.");
    }

    private function assertOwnedBy(Supplier $supplier, SupplierItem $item): void
    {
        abort_unless($item->supplier_id === $supplier->id, 404);
    }

    /** @return array<string, array<int, mixed>> */
    private function terms(): array
    {
        return [
            'supplier_code' => ['nullable', 'string', 'max:60'],
            'last_rate' => ['nullable', 'numeric', 'min:0'],
            // A rate without a currency is the misreading BR-55 exists to stop.
            'currency_id' => ['nullable', 'required_with:last_rate', 'integer', 'exists:currencies,id'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'moq' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
