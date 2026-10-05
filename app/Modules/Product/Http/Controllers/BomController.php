<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\BomLine;
use App\Modules\Product\Models\Product;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BomController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = Bom::query()->with(['product:id,code,name']);

        $term = trim((string) $request->string('q'));

        if ($term !== '') {
            $query->whereHas('product', function ($products) use ($term): void {
                $products->where(function ($match) use ($term): void {
                    $match->where('code', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%");
                });
            });
        }

        $this->applyListing(
            $query,
            $request,
            filters: ['status' => 'status'],
            sortable: ['version_no', 'status', 'created_at'],
            defaultSort: '-id',
        );

        return Inertia::render('Product/Boms/Index', [
            'boms' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Bom $bom): array => [
                    'id' => $bom->id,
                    'product_id' => $bom->product_id,
                    'product_code' => $bom->product?->code,
                    'product_name' => $bom->product?->name,
                    'version_no' => $bom->version_no,
                    'status' => $bom->status,
                    'base_qty' => $bom->base_qty,
                    'created_at' => $bom->created_at->toDateString(),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }

    /**
     * PD-3 — one BOM per product may be active, so this screen creates a draft and the
     * product page activates it. Quantities are per `base_qty` finished pieces (BR-1).
     */
    public function create(Product $product): Response
    {
        $product->load(['currentSpec']);

        /*
         * A new version opens on the newest one, whatever its status. It used to open on the
         * active version, so a planner who had drafted v3 and came back to continue was handed
         * v2 again and redid the changes.
         */
        $newest = $product->boms()->with('lines')->orderByDesc('version_no')->first();

        return Inertia::render('Product/Boms/Form', [
            'product' => $product->only(['id', 'code', 'name', 'product_type']),
            'spec' => $product->currentSpec?->only(['id', 'version_no', 'colours', 'colour_list']),
            'bom' => null,
            'basedOn' => $newest?->only(['id', 'version_no', 'status', 'base_qty']),
            'activeLines' => $newest === null ? [] : $this->formLines($newest),
            ...$this->formOptions(),
        ]);
    }

    /**
     * Correct a draft.
     *
     * A draft could be created and activated and nothing else, so a typo in one quantity
     * meant a whole new version. Only a draft: an active or superseded BOM is what job cards
     * were planned from, and stays as it was.
     */
    public function edit(Bom $bom): Response|RedirectResponse
    {
        if ($refusal = $this->notEditable($bom)) {
            return redirect()->to(route('products.show', $bom->product_id).'#bom')->with('error', $refusal);
        }

        $bom->load(['product', 'spec', 'lines']);

        return Inertia::render('Product/Boms/Form', [
            'product' => $bom->product->only(['id', 'code', 'name', 'product_type']),
            'spec' => $bom->spec?->only(['id', 'version_no', 'colours', 'colour_list']),
            'bom' => $bom->only(['id', 'version_no', 'status', 'base_qty', 'notes']),
            'basedOn' => null,
            'activeLines' => $this->formLines($bom),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Bom $bom): RedirectResponse
    {
        if ($refusal = $this->notEditable($bom)) {
            return back()->with('error', $refusal);
        }

        $data = $this->validated($request);
        $activate = $request->boolean('activate');

        abort_if($activate && ! $request->user()->hasPermission('bom.activate'), 403);

        DB::transaction(function () use ($bom, $data, $activate): void {
            $bom->update(['base_qty' => $data['base_qty'], 'notes' => $data['notes'] ?? null]);

            BomLine::query()->where('bom_id', $bom->id)->delete();
            $this->writeLines($bom, $data['lines']);

            if ($activate) {
                $this->promote($bom);
            }
        });

        return redirect()
            ->to(route('products.show', $bom->product_id).'#bom')
            ->with('success', $activate
                ? "BOM v{$bom->version_no} saved and is now the active version."
                : "BOM v{$bom->version_no} saved. It is still a draft.");
    }

    /** Why this BOM cannot be changed, or null when it can. */
    private function notEditable(Bom $bom): ?string
    {
        if ($bom->status !== Bom::DRAFT) {
            return "BOM v{$bom->version_no} is {$bom->status}, so it cannot be edited. Create a new version instead.";
        }

        if (DB::table('job_cards')->where('bom_id', $bom->id)->exists()) {
            return "BOM v{$bom->version_no} is already used by a job card, so it cannot be edited. Create a new version instead.";
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function formLines(Bom $bom): array
    {
        return $bom->lines->map(fn ($line): array => [
            'item_id' => $line->item_id,
            'uom_id' => $line->uom_id,
            'qty_per_base' => $line->qty_per_base,
            'wastage_pct' => $line->wastage_pct,
            'colour_index' => $line->colour_index,
            'is_optional' => (bool) $line->is_optional,
            'formula_ref' => $line->formula_ref,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'items' => DB::table('items as i')
                ->leftJoin('item_categories as c', 'c.id', '=', 'i.item_category_id')
                ->where('i.is_active', true)
                ->orderBy('i.code')
                ->get(['i.id', 'i.code', 'i.name', 'i.base_uom_id', 'c.item_class']),
            'uoms' => DB::table('uoms')->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'base_qty' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:uoms,id'],
            'lines.*.qty_per_base' => ['required', 'numeric', 'gt:0'],
            'lines.*.wastage_pct' => ['nullable', 'numeric', 'min:0'],
            'lines.*.colour_index' => ['nullable', 'integer', 'min:1'],
            'lines.*.is_optional' => ['nullable', 'boolean'],
            'lines.*.formula_ref' => ['nullable', 'string', 'max:20'],
        ], [
            'lines.required' => 'A bill of materials needs at least one material.',
            'lines.*.item_id.required' => 'Choose a material.',
            'lines.*.uom_id.required' => 'Choose a unit.',
            'lines.*.qty_per_base.required' => 'Enter a quantity.',
            'lines.*.qty_per_base.gt' => 'The quantity must be more than zero.',
        ]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function writeLines(Bom $bom, array $lines): void
    {
        foreach ($lines as $line) {
            BomLine::query()->create([
                'bom_id' => $bom->id,
                'item_id' => $line['item_id'],
                'uom_id' => $line['uom_id'],
                'qty_per_base' => $line['qty_per_base'],
                'wastage_pct' => $line['wastage_pct'] ?? 0,
                'colour_index' => $line['colour_index'] ?? null,
                'is_optional' => $line['is_optional'] ?? false,
                'formula_ref' => $line['formula_ref'] ?? null,
            ]);
        }
    }

    /**
     * BOM quantities are per `base_qty` finished pieces — 1000 by default (BR-1).
     *
     * `formula_ref` marks a line whose quantity is *derived* rather than fixed: MRP recomputes
     * those from the spec instead of trusting the stored number, so a spec revision does not
     * silently leave the BOM wrong (02-database-schema §3.3).
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request);

        // A BOM written to be used should not need a second trip to the product page to be
        // activated. Activation is its own permission, so asking for it without holding it is
        // refused here rather than silently granted by a checkbox.
        $activate = $request->boolean('activate');

        abort_if($activate && ! $request->user()->hasPermission('bom.activate'), 403);

        $bom = DB::transaction(function () use ($product, $data, $request, $activate): Bom {
            $bom = Bom::query()->create([
                'product_id' => $product->id,
                'product_spec_id' => $data['product_spec_id'] ?? $product->currentSpec?->id,
                'version_no' => (int) $product->boms()->max('version_no') + 1,
                'status' => Bom::DRAFT,
                'base_qty' => $data['base_qty'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->writeLines($bom, $data['lines']);

            if ($activate) {
                $this->promote($bom);
            }

            return $bom;
        });

        // Back to the product, where the BOM sits beside the spec and the artwork it is read
        // with — the form itself only ever creates.
        // `#bom` is the anchor the product page scrolls to on arrival.
        return redirect()
            ->to(route('products.show', $product).'#bom')
            ->with(
                'success',
                $activate
                    ? "BOM v{$bom->version_no} created and is now the active version."
                    : "BOM v{$bom->version_no} created as a draft. Activate it before a job card can be released.",
            );
    }

    /**
     * PD-3 — exactly one BOM per product is active. Same emulation as P2 and A2: supersede
     * the outgoing version first, or the unique index over `active_key` rejects the write.
     */
    public function activate(Bom $bom): RedirectResponse
    {
        DB::transaction(fn () => $this->promote($bom));

        return back()->with('success', "BOM v{$bom->version_no} is now active.");
    }

    /**
     * Supersede the outgoing version, then activate this one — in that order, inside a
     * transaction the caller owns, or the unique index over `active_key` rejects the write.
     */
    private function promote(Bom $bom): void
    {
        Bom::query()
            ->where('product_id', $bom->product_id)
            ->where('id', '!=', $bom->getKey())
            ->where('status', Bom::ACTIVE)
            ->update(['status' => Bom::SUPERSEDED]);

        $bom->update(['status' => Bom::ACTIVE]);
    }
}
