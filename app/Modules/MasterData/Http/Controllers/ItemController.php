<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Http\Requests\ItemRequest;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\Uom;
use App\Modules\MasterData\Models\Warehouse;
use App\Modules\MasterData\Services\ItemActivationChecklist;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\MasterData\States\ItemStateMachine;
use App\Support\Http\ListsResources;
use App\Support\Reference\ItemVocabulary;
use App\Support\Reference\Vocabulary;
use App\Support\States\TransitionDenied;
use App\Support\Text\Plain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function __construct(
        private readonly ItemMasterService $items,
        private readonly ItemStateMachine $states,
        private readonly ItemActivationChecklist $checklist,
    ) {}

    public function index(Request $request): Response
    {
        $query = Item::query()
            ->with(['category:id,code,name,item_class', 'baseUom:id,code', 'family:id,code,name'])
            ->withCount([]);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'description'],
            filters: ['category' => 'item_category_id', 'status' => 'status', 'type' => 'item_type', 'family' => 'production_family_id', 'make' => 'make_or_buy'],
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
                    'item_type' => $item->item_type,
                    'make_or_buy' => $item->make_or_buy,
                    'family' => $item->family?->name,
                    'status' => $item->status,
                ],
            ),
            'filters' => $this->listingFilters($request, ['category', 'status', 'type', 'family', 'make']),
            'categories' => ItemCategory::query()->orderBy('name')->get(['id', 'code', 'name', 'item_class']),
            'statuses' => Vocabulary::options('item_status'),
            'families' => \App\Modules\MasterData\Models\ProductionFamily::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'code_prefix'])
                ->map(fn ($family): array => ['value' => $family->id, 'label' => $family->name, 'code' => $family->code, 'prefix' => $family->code_prefix])->all(),
            'itemTypes' => ItemVocabulary::options(ItemVocabulary::ITEM_TYPES),
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
        $data = $request->validated();

        if (($data['code'] ?? '') === '') {
            unset($data['code']);
        }

        $item = $this->items->create($data, [], $request->user()->id);

        return redirect()
            ->route('items.show', $item)
            ->with('success', "Item {$item->code} created.");
    }

    public function show(Item $item): Response
    {
        $item->loadMissing('product');

        $item->load(['category', 'family', 'group', 'baseUom', 'purchaseUom', 'orderUom', 'defaultSupplier', 'defaultWarehouse', 'customer', 'toolOwner', 'product', 'creator:id,name', 'activator:id,name']);

        return Inertia::render('MasterData/Items/Show', [
            'item' => $item,
            // The products whose active bill draws on this item: where a change to it lands.
            'usedOn' => DB::table('bom_lines as bl')
                ->join('boms as b', 'b.id', '=', 'bl.bom_id')
                ->join('products as p', 'p.id', '=', 'b.product_id')
                ->join('items as pi', 'pi.id', '=', 'p.item_id')
                ->where('bl.item_id', $item->id)
                ->where('b.status', 'active')
                ->whereNull('p.deleted_at')
                ->distinct()
                ->orderBy('pi.code')
                ->get(['p.id', 'pi.code', 'pi.name']),
            // IM-1 — what it still needs before it is active, and which status changes the
            // reader may make from here.
            'activation' => $this->checklist->steps($item),
            'transitions' => $this->states->available($item),
            // The family's attribute definitions, so the page says "Diameter (mm)", not "diameter_mm".
            'attributeDefinitions' => \App\Support\Validation\FamilyAttributeRules::definitions()[$item->production_family_id] ?? [],
            // Worded vocabularies for the page: a reader sees "Weighted average", never the key.
            'labels' => [
                'itemTypes' => ItemVocabulary::ITEM_TYPES,
                'makeOrBuy' => ItemVocabulary::MAKE_OR_BUY,
                'garmentTypes' => ItemVocabulary::GARMENT_TYPES,
                'specScopes' => ItemVocabulary::SPEC_SCOPES,
                'materialBases' => ItemVocabulary::MATERIAL_BASES,
                'variantAxes' => ItemVocabulary::VARIANT_AXES,
                'valuationMethods' => ItemVocabulary::VALUATION_METHODS,
                'chargeBases' => ItemVocabulary::CHARGE_BASES,
            ],
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
        $this->items->update($item, $request->validated());

        return redirect()
            ->route('items.show', $item)
            ->with('success', "Item {$item->code} updated.");
    }

    /** IM-1 — activate, hold, resume or discontinue. */
    public function transition(Request $request, Item $item): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([Item::ACTIVE, Item::ON_HOLD, Item::DISCONTINUED])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($item, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['to'] === Item::ACTIVE
            ? "{$item->code} is active. It can now be bought, put on a bill of materials, quoted and ordered."
            : "{$item->code} is now ".Plain::status($data['to']).'.');
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
            'warehouses' => Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'families' => \App\Modules\MasterData\Models\ProductionFamily::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'code_prefix'])
                ->map(fn ($family): array => ['value' => $family->id, 'label' => $family->name, 'code' => $family->code, 'prefix' => $family->code_prefix])->all(),
            'groups' => \App\Modules\MasterData\Models\ItemGroup::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'production_family_id', 'parent_id', 'code', 'name']),
            'customers' => \App\Modules\MasterData\Models\Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'familyAttributes' => \App\Support\Validation\FamilyAttributeRules::definitions(),
            ...ItemVocabulary::all(),
        ];
    }
}
