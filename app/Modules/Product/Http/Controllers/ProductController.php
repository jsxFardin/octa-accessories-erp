<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\Employee;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Services\ItemActivationChecklist;
use App\Modules\MasterData\Services\ItemMasterService;
use App\Modules\MasterData\States\ItemStateMachine;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\Routing;
use App\Modules\Product\Services\ProductSetup;
use App\Support\Http\ContextualId;
use App\Support\Http\ListsResources;
use App\Support\Reference\ItemVocabulary;
use App\Support\Reference\Vocabulary;
use App\Support\States\TransitionDenied;
use App\Support\Text\Plain;
use App\Support\Validation\FamilyAttributeRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The finished-goods entry point to the item master.
 *
 * A product is an item with a make profile (`products`). This screen keeps the engineering
 * view — specs, artworks, bills of materials, routing — while the item's own fields are
 * written through {@see ItemMasterService} like every other item.
 */
class ProductController extends Controller
{
    use ContextualId;
    use ListsResources;

    public function __construct(
        private readonly ItemMasterService $items,
        private readonly ItemStateMachine $states,
        private readonly ItemActivationChecklist $checklist,
    ) {}

    public function index(Request $request): Response
    {
        // Code, name and status live on the item; the join lets the list search and sort
        // on them as it always did.
        $query = Product::query()
            ->join('items as pi', 'pi.id', '=', 'products.item_id')
            ->select('products.*')
            ->with(['customer:id,code,name', 'brand:id,name', 'currentSpec']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['pi.code', 'pi.name', 'products.customer_style_ref'],
            filters: ['customer' => 'products.customer_id', 'type' => 'products.product_type', 'status' => 'pi.status'],
            sortable: ['code', 'name', 'product_type', 'status'],
            defaultSort: 'code',
        );

        return Inertia::render('Product/Products/Index', [
            'products' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Product $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'customer' => $product->customer?->name,
                    'brand' => $product->brand?->name,
                    'product_type' => $product->product_type,
                    'customer_style_ref' => $product->customer_style_ref,
                    'status' => $product->status,
                    'spec_version' => $product->currentSpec?->version_no,
                ],
            ),
            'filters' => $this->listingFilters($request, ['customer', 'type', 'status']),
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'productTypes' => Vocabulary::options('product_type'),
            'statuses' => Vocabulary::options('item_status'),
        ]);
    }

    /** `?customer=` carries the customer whose page the product was started from. */
    public function create(Request $request): Response
    {
        $options = $this->formOptions();
        $requested = $this->contextualId($request, 'customer', ['customer.view_any', 'customer.view']);

        return Inertia::render('Product/Products/Form', [
            'product' => null,
            // Only a customer the picker actually offers. The raw parameter used to be echoed
            // straight through, so an archived or unknown id left the select showing a value
            // it could not resolve — and then failed validation on save.
            'preselectedCustomer' => $options['customers']->contains('id', $requested) ? $requested : null,
            ...$options,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$item, $profile] = $this->validated($request);

        $product = $this->items->create($item, $profile, $request->user()->id)->product()->firstOrFail();

        return redirect()
            ->route('products.show', $product)
            ->with('success', "Product {$product->code} created. The setup list below shows what it needs next.");
    }

    /** The routing is chosen from the product page's setup list, not three screens away. */
    public function updateRouting(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'routing_id' => ['required', 'integer', $this->routingOfType($product->product_type, $product->item?->production_family_id)],
        ]);

        $product->update($data);

        return back()->with('success', 'Routing updated.');
    }

    public function show(Product $product, ProductSetup $setup): Response
    {
        $product->load([
            'item.family:id,code,name',
            'item.group:id,code,name',
            'item.baseUom:id,code',
            'item.orderUom:id,code',
            'customer',
            'brand',
            'routing.operations',
            'specs.creator:id,name',
            'artworks.versions',
            'boms.lines.item:id,code,name',
            'boms.lines.uom:id,code',
        ]);

        $currentSpec = $product->currentSpec;

        return Inertia::render('Product/Products/Show', [
            'product' => [
                ...$product->only([
                    'id', 'code', 'name', 'product_type', 'customer_style_ref', 'status',
                    'is_running_programme', 'annual_forecast_qty',
                ]),
                'item' => [
                    ...$product->item->only(['id', 'item_type', 'make_or_buy', 'garment_type', 'spec_scope', 'material_base', 'variant_axes']),
                    'family' => $product->item->family?->only(['id', 'code', 'name']),
                    'group' => $product->item->group?->only(['id', 'code', 'name']),
                    'base_uom' => $product->item->baseUom?->code,
                    'order_uom' => $product->item->orderUom?->code,
                ],
                'customer' => $product->customer?->only(['id', 'code', 'name']),
                'brand' => $product->brand?->only(['id', 'name']),
                'routing' => $product->routing?->only(['id', 'code', 'name']),
            ],
            // What the product still needs, in the order the work is done. Not "does one exist"
            // — a spec with no web width exists — but "will it cost".
            'setup' => $setup->steps($product),
            // IM-1 — and what the item needs before it is active, which includes the above.
            'activation' => $this->checklist->steps($product->item),
            'transitions' => $this->states->available($product->item),
            'routings' => Routing::query()
                ->where('is_active', true)
                ->where('product_type', $product->product_type)
                ->orderByDesc('is_default')
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'is_default']),
            'specs' => $product->specs->map(fn ($spec): array => [
                ...$spec->only([
                    'id', 'version_no', 'status', 'label_width_mm', 'label_height_mm', 'web_width_mm',
                    'selvedge_mm', 'lane_gap_mm', 'cut_gap_mm', 'ends', 'fabric_gsm', 'warp_ratio',
                    'colours', 'colour_list', 'cut_type', 'fold_type', 'coverage_pct',
                    'bundle_size', 'bundles_per_carton', 'base_material', 'fibre_composition',
                    'country_of_origin', 'created_at',
                ]),
                'created_by' => $spec->creator?->name,
                'derived' => $spec->derivedGeometry($product->product_type),
            ]),
            'artworks' => $product->artworks->map(fn ($artwork): array => [
                'id' => $artwork->id,
                'code' => $artwork->code,
                'title' => $artwork->title,
                'versions' => $artwork->versions->map->only(['id', 'version_no', 'status', 'submitted_at', 'approved_at', 'customer_ref']),
            ]),
            // Gate 1 starts here, not on the artwork list. A product with no approved artwork
            // cannot be produced, and this is the screen where that is noticed — so the first
            // artwork is started from it rather than from a list that asks which product it
            // belongs to all over again.
            'designers' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'suggestedArtworkCode' => $product->code.'-AW'.($product->artworks->count() + 1),
            'boms' => $product->boms->map(fn ($bom): array => [
                ...$bom->only(['id', 'version_no', 'status', 'base_qty', 'notes']),
                'lines' => $bom->lines->map(fn ($line): array => [
                    ...$line->only(['id', 'qty_per_base', 'wastage_pct', 'colour_index', 'is_optional', 'formula_ref']),
                    'item' => $line->item?->only(['id', 'code', 'name']),
                    'uom' => $line->uom?->code,
                ])->all(),
            ]),
            'currentSpecId' => $currentSpec?->id,
            'options' => $this->formOptions(),
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load('item');

        return Inertia::render('Product/Products/Form', [
            'product' => [
                ...$product->toArray(),
                // The item's own fields, flattened for the form.
                ...$product->item->only(['code', 'name', 'description', 'production_family_id', 'item_group_id', 'garment_type', 'spec_scope', 'material_base', 'variant_axes', 'base_uom_id', 'order_uom_id', 'pack_pcs_per_inner', 'pack_inners_per_carton', 'default_warehouse_id', 'valuation_method', 'is_lot_tracked', 'standard_wastage_pct', 'attributes']),
            ],
            'preselectedCustomer' => null,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        [$item, $profile] = $this->validated($request, $product);

        $this->items->update($product->item, $item, $profile);

        return redirect()
            ->route('products.show', $product)
            ->with('success', "Product {$product->fresh()->code} updated.");
    }

    /** IM-1 — the product's lifecycle is its item's. */
    public function transition(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([Item::ACTIVE, Item::ON_HOLD, Item::DISCONTINUED])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->states->transition($product->item, $data['to'], $data);
        } catch (TransitionDenied $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $data['to'] === Item::ACTIVE
            ? "{$product->code} is active. It can now be quoted, ordered and planned."
            : "{$product->code} is now ".Plain::status($data['to']).'.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();
        $product->item?->delete();

        return redirect()->route('products.index')->with('success', "Product {$product->code} archived.");
    }

    /**
     * The item's fields and the profile's, validated together and split for the service.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $buyerSpecific = $request->input('spec_scope', 'buyer') === 'buyer';
        $familyId = $request->input('production_family_id');
        $familyId = $familyId === null || $familyId === '' ? null : (int) $familyId;

        $data = $request->validate([
            'spec_scope' => ['nullable', Rule::in(array_keys(ItemVocabulary::SPEC_SCOPES))],
            // P1 — a buyer-specific product belongs to exactly one customer, and that never
            // changes: the artwork approval and the price both belong to that relationship.
            'customer_id' => [
                $buyerSpecific ? 'required' : 'nullable', 'integer', 'exists:customers,id',
                // …so on an existing product it is not a field at all, whatever the request says.
                ...($product?->customer_id === null ? [] : [Rule::in([$product->customer_id])]),
            ],
            // A brand of this customer, or one that belongs to no single customer.
            'brand_id' => [
                'nullable', 'integer',
                Rule::exists('brands', 'id')->where(fn ($query) => $query->where(fn ($brand) => $brand
                    ->whereNull('customer_id')
                    ->orWhere('customer_id', (int) $request->input('customer_id')))),
            ],
            'routing_id' => ['nullable', 'integer', $this->routingOfType((string) $request->input('product_type'), $request->integer('production_family_id') ?: null)],
            // Typed codes are for catalogues that already have them; left empty, the family's
            // series assigns one.
            'code' => ['nullable', 'string', 'max:40', Rule::unique('items', 'code')->ignore($product?->item_id)],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'customer_style_ref' => ['nullable', 'string', 'max:80'],
            'product_type' => ['required', Rule::in(Vocabulary::codes('product_type'))],
            'production_family_id' => ['nullable', 'integer', 'exists:production_families,id'],
            'item_group_id' => ['nullable', 'integer', Rule::exists('item_groups', 'id')->where('production_family_id', (int) $request->input('production_family_id'))],
            'garment_type' => ['nullable', Rule::in(array_keys(ItemVocabulary::GARMENT_TYPES))],
            'material_base' => ['nullable', Rule::in(array_keys(ItemVocabulary::MATERIAL_BASES))],
            'variant_axes' => ['nullable', 'array'],
            'variant_axes.*' => [Rule::in(array_keys(ItemVocabulary::VARIANT_AXES))],
            'base_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'order_uom_id' => ['nullable', 'integer', 'exists:uoms,id'],
            'pack_pcs_per_inner' => ['nullable', 'integer', 'min:1'],
            'pack_inners_per_carton' => ['nullable', 'integer', 'min:1'],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'valuation_method' => ['nullable', Rule::in(array_keys(ItemVocabulary::VALUATION_METHODS))],
            'is_lot_tracked' => ['boolean'],
            'standard_wastage_pct' => ['nullable', 'numeric', 'min:0'],
            'attributes' => ['nullable', 'array'],
            ...FamilyAttributeRules::for($familyId),
            'is_running_programme' => ['boolean'],
            // BR-15 — a running programme amortises tooling over the annual forecast.
            'annual_forecast_qty' => ['nullable', 'numeric', 'min:0', 'required_if:is_running_programme,true'],
        ], [
            'customer_id.required' => 'A buyer-specific product needs its buyer.',
            'customer_id.in' => 'A product stays with the customer it was created for. To make it for another customer, create a new product.',
            'brand_id.exists' => 'Choose a brand that belongs to this customer.',
            'item_group_id.exists' => 'Choose a group that belongs to the chosen family.',
            ...FamilyAttributeRules::messages($familyId),
        ]);

        $profileKeys = ['customer_id', 'brand_id', 'routing_id', 'customer_style_ref', 'product_type', 'is_running_programme', 'annual_forecast_qty'];
        $profile = array_intersect_key($data, array_flip($profileKeys));
        $item = array_diff_key($data, array_flip($profileKeys));

        $item['item_type'] = 'finished_good';
        $item['make_or_buy'] = Item::MAKE;
        $item['spec_scope'] = $buyerSpecific ? 'buyer' : 'standard';
        // A label product is made in family 02 unless it says otherwise; the base and order
        // units of a label are pieces and thousand pieces.
        $item['production_family_id'] ??= \App\Modules\MasterData\Models\ProductionFamily::query()->where('code', '02')->value('id');
        $item['base_uom_id'] ??= \App\Modules\MasterData\Models\Uom::query()->where('code', 'pcs')->value('id');
        $item['order_uom_id'] ??= \App\Modules\MasterData\Models\Uom::query()->where('code', 'M')->value('id');

        if (($item['code'] ?? '') === '') {
            unset($item['code']);
        }

        return [$item, $profile];
    }

    /**
     * A woven label run down a flexo routing is costed at the wrong machines' rates, and a
     * drawcord run down a zipper routing on the wrong ones too. A routing fits by its
     * family or by its label type (Routing::fits).
     */
    private function routingOfType(string $productType, ?int $familyId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($productType, $familyId): void {
            $routing = Routing::query()->find($value);

            if ($routing === null) {
                $fail('Choose a routing from the list.');
            } elseif (! $routing->fits($productType, $familyId === null ? null : (int) $familyId)) {
                $fail("{$routing->code} is a routing for another family or product type. Choose one that matches this product.");
            }
        };
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'code', 'name', 'customer_id']),
            'routings' => Routing::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'product_type', 'production_family_id', 'max_lot_size'])
                ->map(fn (Routing $routing): array => [
                    ...$routing->only(['id', 'code', 'name', 'product_type', 'production_family_id', 'max_lot_size']),
                    'label' => "{$routing->code} · {$routing->name}",
                ]),
            'productTypes' => Vocabulary::options('product_type'),
            'statuses' => Vocabulary::options('item_status'),
            'families' => \App\Modules\MasterData\Models\ProductionFamily::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'code_prefix'])
                ->map(fn ($family): array => ['value' => $family->id, 'label' => $family->name, 'code' => $family->code, 'prefix' => $family->code_prefix])->all(),
            'groups' => \App\Modules\MasterData\Models\ItemGroup::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'production_family_id', 'parent_id', 'code', 'name']),
            'uoms' => \App\Modules\MasterData\Models\Uom::query()->orderBy('code')->get(['id', 'code', 'name', 'dimension']),
            'warehouses' => \App\Modules\MasterData\Models\Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'familyAttributes' => FamilyAttributeRules::definitions(),
            ...ItemVocabulary::all(),
            // The gap travels with the option so the spec form can prefill it (BR-4).
            'cutTypes' => array_map(
                fn (array $row): array => [
                    'value' => (string) $row['code'],
                    'label' => (string) $row['name'],
                    'default_cut_gap_mm' => (float) $row['default_cut_gap_mm'],
                ],
                Vocabulary::rows('cut_type'),
            ),
        ];
    }
}
