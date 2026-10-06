<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierContact;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The people at the supplier, kept on the supplier they belong to.
 *
 * As with customers, the table and the model existed and nothing could write to them: a
 * supplier's contact was whatever a seeder had left behind. Same shape as
 * `CustomerContactController` — nested under the supplier, ownership re-checked, one primary
 * at a time.
 */
class SupplierContactController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validated($request);

        $contact = DB::transaction(function () use ($supplier, $data): SupplierContact {
            $contact = SupplierContact::query()->create([...$data, 'supplier_id' => $supplier->id]);

            $this->settlePrimary($supplier, $contact);

            return $contact;
        });

        $this->audit->recordTable('supplier_contacts', $contact->id, 'created', null, $contact->attributesToArray());

        return back()->with('success', "{$contact->name} added to {$supplier->code}.");
    }

    public function update(Request $request, Supplier $supplier, SupplierContact $contact): RedirectResponse
    {
        $this->assertOwnedBy($supplier, $contact);

        $before = $contact->attributesToArray();
        $data = $this->validated($request);

        DB::transaction(function () use ($supplier, $contact, $data): void {
            $contact->update($data);

            $this->settlePrimary($supplier, $contact);
        });

        $this->audit->recordTable('supplier_contacts', $contact->id, 'updated', $before, $contact->attributesToArray());

        return back()->with('success', "{$contact->name} updated.");
    }

    public function destroy(Supplier $supplier, SupplierContact $contact): RedirectResponse
    {
        $this->assertOwnedBy($supplier, $contact);

        $before = $contact->attributesToArray();

        $contact->delete();

        $this->audit->recordTable('supplier_contacts', $contact->id, 'deleted', $before);

        return back()->with('success', "{$contact->name} removed.");
    }

    /** One primary per supplier: the person a buyer rings first. */
    private function settlePrimary(Supplier $supplier, SupplierContact $contact): void
    {
        if (! $contact->is_primary) {
            return;
        }

        SupplierContact::query()
            ->where('supplier_id', $supplier->id)
            ->where('id', '!=', $contact->getKey())
            ->update(['is_primary' => false]);
    }

    private function assertOwnedBy(Supplier $supplier, SupplierContact $contact): void
    {
        abort_unless($contact->supplier_id === $supplier->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'designation' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['boolean'],
        ]);
    }
}
