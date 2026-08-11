<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\SpecInput;
use App\Support\Reference\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductSpecController extends Controller
{
    public function __construct(private readonly ConsumptionCalculator $consumption) {}

    /**
     * A new version. P3 — a spec is immutable once anything references it, so an edit is
     * always a new version rather than an update in place.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);

        $spec = DB::transaction(function () use ($product, $data, $request): ProductSpec {
            $versionNo = (int) $product->specs()->max('version_no') + 1;

            return ProductSpec::query()->create([
                ...$data,
                'product_id' => $product->id,
                'version_no' => $versionNo,
                'status' => ProductSpec::DRAFT,
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', "Spec v{$spec->version_no} created as a draft.");
    }

    /**
     * P2 — exactly one spec per product is `current`. The database enforces it through the
     * `current_key` generated column, so the outgoing version has to be superseded first,
     * inside the same transaction.
     */
    public function makeCurrent(ProductSpec $spec): RedirectResponse
    {
        DB::transaction(function () use ($spec): void {
            ProductSpec::query()
                ->where('product_id', $spec->product_id)
                ->where('id', '!=', $spec->getKey())
                ->where('status', ProductSpec::CURRENT)
                ->update(['status' => ProductSpec::SUPERSEDED]);

            $spec->update(['status' => ProductSpec::CURRENT]);
        });

        return back()->with('success', "Spec v{$spec->version_no} is now the current version.");
    }

    /**
     * The live derived panel a designer watches while typing: pitch, labels per metre and
     * suggested ends (BR-4, BR-5, BR-6), plus a full consumption plan when a trial quantity
     * is supplied.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_type' => ['required', Rule::in(Vocabulary::codes('product_type'))],
            'cut_type' => ['nullable', Rule::in(Vocabulary::codes('cut_type'))],
            'label_width_mm' => ['required', 'numeric', 'gt:0'],
            'label_height_mm' => ['required', 'numeric', 'gt:0'],
            'web_width_mm' => ['nullable', 'numeric', 'min:0'],
            'selvedge_mm' => ['nullable', 'numeric', 'min:0'],
            'lane_gap_mm' => ['nullable', 'numeric', 'min:0'],
            'cut_gap_mm' => ['nullable', 'numeric', 'min:0'],
            'ends' => ['nullable', 'integer', 'min:1'],
            'fabric_gsm' => ['nullable', 'numeric', 'min:0'],
            'warp_ratio' => ['nullable', 'numeric', 'gt:0', 'lt:1'],
            'colours' => ['nullable', 'integer', 'min:1'],
            'coverage_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bundle_size' => ['nullable', 'integer', 'min:1'],
            'bundles_per_carton' => ['nullable', 'integer', 'min:1'],
            'trial_qty' => ['nullable', 'integer', 'min:1'],
            'routing_id' => ['nullable', 'integer', 'exists:routings,id'],
        ]);

        try {
            $spec = SpecInput::fromArray(
                $data,
                Vocabulary::productType($data['product_type']),
                Vocabulary::cutType($data['cut_type'] ?? null),
            );

            $geometry = [
                'pitch_mm' => round($this->consumption->pitchMm($spec), 2),
                'labels_per_metre' => round($this->consumption->labelsPerMetre($spec), 4),
                'suggested_ends' => $this->consumption->suggestedEnds($spec),
                'effective_cut_gap_mm' => $spec->effectiveCutGapMm(),
            ];

            $plan = null;

            if (! empty($data['trial_qty'])) {
                $routing = $data['routing_id'] ?? null
                    ? \App\Modules\Product\Models\Routing::with('operations')->find($data['routing_id'])
                    : null;

                $plan = $this->consumption
                    ->plan($spec, (int) $data['trial_qty'], $routing?->toCalculatorSteps() ?? [])
                    ->toArray();
            }

            return response()->json(['geometry' => $geometry, 'plan' => $plan]);
        } catch (\InvalidArgumentException $e) {
            // A geometry that cannot produce a label is a validation failure, not a 500 —
            // the designer needs the reason next to the field they just typed in.
            throw ValidationException::withMessages(['geometry' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Product $product): array
    {
        $data = $request->validate([
            'label_width_mm' => ['required', 'numeric', 'gt:0'],
            'label_height_mm' => ['required', 'numeric', 'gt:0'],
            'web_width_mm' => ['nullable', 'numeric', 'min:0'],
            'selvedge_mm' => ['numeric', 'min:0'],
            'lane_gap_mm' => ['numeric', 'min:0'],
            'cut_gap_mm' => ['numeric', 'min:0'],
            'ends' => ['nullable', 'integer', 'min:1'],
            'base_material' => ['nullable', 'string', 'max:60'],
            'fabric_gsm' => ['nullable', 'numeric', 'min:0'],
            'warp_ratio' => ['numeric', 'gt:0', 'lt:1'],
            'colours' => ['required', 'integer', 'min:1'],
            'colour_list' => ['array'],
            'colour_list.*.index' => ['required', 'integer', 'min:1'],
            'colour_list.*.name' => ['required', 'string', 'max:60'],
            'colour_list.*.pantone' => ['nullable', 'string', 'max:30'],
            'colour_list.*.weight_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cut_type' => ['nullable', Rule::in(Vocabulary::codes('cut_type'))],
            'fold_type' => ['nullable', Rule::in(['flat', 'centre_fold', 'end_fold', 'loop', 'mitre', 'manhattan', 'book_cover'])],
            'finish' => ['nullable', 'string', 'max:120'],
            'coverage_pct' => ['numeric', 'min:0', 'max:100'],
            'bundle_size' => ['integer', 'min:1'],
            'bundles_per_carton' => ['integer', 'min:1'],
            'care_symbols' => ['array'],
            'fibre_composition' => ['nullable', 'string', 'max:255'],
            'country_of_origin' => ['nullable', 'string', 'max:60'],
            'claims' => ['array'],
            'attributes' => ['array'],
            'notes' => ['nullable', 'string'],
        ]);

        // BR-5 — a spec whose geometry yields no ends is invalid, and the message says which
        // dimension is at fault rather than failing later inside a costing run.
        $ends = $data['ends'] ?? null;

        if ($ends === null && ! empty($data['web_width_mm'])) {
            $suggested = $this->consumption->suggestedEnds(SpecInput::fromArray(
                [...$data, 'product_type' => $product->product_type],
                Vocabulary::productType($product->product_type),
                Vocabulary::cutType($data['cut_type'] ?? null),
            ));

            if ($suggested < 1) {
                throw ValidationException::withMessages([
                    'web_width_mm' => 'BR-5: the usable web is narrower than one label plus its lane gap. No label fits across.',
                ]);
            }
        }

        return [
            ...$data,
            'colour_list' => $data['colour_list'] ?? [],
            'care_symbols' => $data['care_symbols'] ?? [],
            'claims' => $data['claims'] ?? [],
            'attributes' => $data['attributes'] ?? [],
        ];
    }
}
