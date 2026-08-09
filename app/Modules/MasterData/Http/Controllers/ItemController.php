<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Http\Requests\ItemRequest;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\Uom;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Items carry the technical fields the consumption formulas need — density, GSM, ink lay,
 * shade criticality, shelf life — because BR-9, BR-10 and BR-37 read them off the item, not
 * out of a config file (02-database-schema §3.2).
 */
class ItemController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Item::query()
            ->with(['category:id,code,name,item_class', 'baseUom:id,code'])
            ->withCount([]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'description'],
            filters: ['category' => 'item_category_id', 'active' => 'is_active'],
            sortable: ['code', 'name', 'avg_rate', 'reorder_level'],
            defaultSort: 'code',
        );

        return Inertia::render('MasterData/Items/Index', [
            'items' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Item $item): array => [
                    'id' => $item->id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'category' => $item->category?->name,
                    'item_class' => $item->category?->item_class,
                    'base_uom' => $item->baseUom?->code,
                    'std_rate' => $item->std_rate,
                    'avg_rate' => $item->avg_rate,
                    'reorder_level' => $item->reorder_level,
                    'is_shade_critical' => $item->is_shade_critical,
                    'has_expiry' => $item->has_expiry,
                    'is_active' => $item->is_active,
                ],
            ),
            'filters' => $this->listingFilters($request, ['category', 'active']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'name', 'item_class']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('MasterData/Items/Form', [
            'item' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(ItemRequest $request): RedirectResponse
    {
        $item = Item::query()->create([...$request->validated(), 'attributes' => $request->validated()['attributes'] ?? []]);

        return redirect()
            ->route('items.show', $item)
            ->with('success', "Item {$item->code} created.");
    }

    public function show(Item $item): Response
    {
        $item->load(['category', 'baseUom', 'purchaseUom', 'defaultSupplier']);

        return Inertia::render('MasterData/Items/Show', [
            'item' => $item,
            // Live, not cached: an availability figure that is 60 seconds stale is a wrong
            // purchasing decision (08-architecture §7).
            'stock' => DB::table('stock_balances as sb')
                ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
                ->where('sb.item_id', $item->id)
                ->groupBy('w.code', 'w.name', 'w.is_nettable')
                ->orderBy('w.code')
                ->get([
                    'w.code as warehouse_code',
                    'w.name as warehouse_name',
                    'w.is_nettable',
                    DB::raw('SUM(sb.balance_qty) as balance_qty'),
                ]),
            'lots' => DB::table('stock_lots')
                ->where('item_id', $item->id)
                ->where('balance_qty', '>', 0)
                ->orderBy('received_on')
                ->limit(25)
                ->get(['id', 'lot_no', 'shade_code', 'balance_qty', 'received_on', 'expiry_date', 'cert_scheme', 'cert_claim_pct', 'status']),
        ]);
    }

    public function edit(Item $item): Response
    {
        return Inertia::render('MasterData/Items/Form', [
            'item' => $item,
            ...$this->formOptions(),
        ]);
    }

    public function update(ItemRequest $request, Item $item): RedirectResponse
    {
        $item->update($request->validated());

        return redirect()
            ->route('items.show', $item)
            ->with('success', "Item {$item->code} updated.");
    }

    public function destroy(Item $item): RedirectResponse
    {
        // Master data is soft-deleted; transactions referencing it keep resolving.
        $item->delete();

        return redirect()
            ->route('items.index')
            ->with('success', "Item {$item->code} deactivated.");
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'code', 'name', 'item_class']),
            'uoms' => Uom::query()->orderBy('code')->get(['id', 'code', 'name', 'dimension']),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }
}
