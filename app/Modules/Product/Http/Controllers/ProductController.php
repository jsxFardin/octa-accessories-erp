<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Brand;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\Routing;
use App\Support\Calculators\CutType;
use App\Support\Calculators\ProductType;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Product::query()->with(['customer:id,code,name', 'brand:id,name', 'currentSpec']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['code', 'name', 'customer_style_ref'],
            filters: ['customer' => 'customer_id', 'type' => 'product_type', 'status' => 'status'],
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
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'name']),
            'productTypes' => $this->productTypes(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Product/Products/Form', [
            'product' => null,
            'preselectedCustomer' => $request->integer('customer') ?: null,
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = Product::query()->create([
            ...$this->validated($request),
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('products.show', $product)
            ->with('success', "Product {$product->code} created. Add a spec and an artwork before it can be ordered.");
    }

    public function show(Product $product): Response
    {
        $product->load([
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
                    'is_running_programme', 'annual_forecast_qty', 'is_active',
                ]),
                'customer' => $product->customer?->only(['id', 'code', 'name']),
                'brand' => $product->brand?->only(['id', 'name']),
                'routing' => $product->routing?->only(['id', 'code', 'name']),
            ],
            // S3 / Gate 1 — the readiness panel a merchandiser checks before confirming.
            'readiness' => $product->readiness(),
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
        return Inertia::render('Product/Products/Form', [
            'product' => $product,
            'preselectedCustomer' => null,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->validated($request, $product));

        return redirect()
            ->route('products.show', $product)
            ->with('success', "Product {$product->code} updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()->route('products.index')->with('success', "Product {$product->code} archived.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            // P1 — a product belongs to exactly one customer, and that never changes: the
            // artwork approval and the price both belong to that relationship.
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'routing_id' => ['nullable', 'integer', 'exists:routings,id'],
            'code' => ['required', 'string', 'max:40', Rule::unique('products', 'code')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:180'],
            'customer_style_ref' => ['nullable', 'string', 'max:80'],
            'product_type' => ['required', Rule::in(array_column(ProductType::cases(), 'value'))],
            'is_running_programme' => ['boolean'],
            // BR-15 — a running programme amortises tooling over the annual forecast.
            'annual_forecast_qty' => ['nullable', 'numeric', 'min:0', 'required_if:is_running_programme,true'],
            'status' => ['required', Rule::in(['development', 'active', 'on_hold', 'discontinued'])],
            'is_active' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'customers' => Customer::query()->active()->orderBy('name')->get(['id', 'code', 'name']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name', 'customer_id']),
            'routings' => Routing::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'product_type', 'max_lot_size']),
            'productTypes' => $this->productTypes(),
            'cutTypes' => array_map(
                fn (CutType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'default_cut_gap_mm' => $type->defaultCutGapMm(),
                ],
                CutType::cases(),
            ),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function productTypes(): array
    {
        return array_map(
            fn (ProductType $type): array => ['value' => $type->value, 'label' => $type->label()],
            ProductType::cases(),
        );
    }
}
