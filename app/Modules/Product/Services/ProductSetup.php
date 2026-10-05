<?php

declare(strict_types=1);

namespace App\Modules\Product\Services;

use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Settings\Settings;

/**
 * What a product still needs before it can be quoted, ordered and produced — in the order the
 * work is done, and with the reason beside anything that is not right.
 *
 * `Product::readiness()` answers "does one exist?", which is the question a sales order gate
 * asks. It is the wrong question for the person setting a product up: a current spec with no
 * web width exists, and so does a woven BOM with no yarn on it, and both were reported as
 * ready until a quotation line came back with no rate and nothing to say why. Each step here
 * is checked against what costing will actually read.
 *
 * A step is `done`, `todo` (nothing there yet) or `attention` (there, but it will not cost).
 */
class ProductSetup
{
    /** The quantity the trial price is worked at: large enough that make-ready does not dominate. */
    public const TRIAL_QTY = 10000;

    public function __construct(
        private readonly CostSheetService $costing,
        private readonly ConsumptionCalculator $consumption,
        private readonly Settings $settings,
    ) {}

    /**
     * @return list<array{key: string, label: string, state: string, detail: string, unlocks: string|null, action: array<string, mixed>|null}>
     */
    public function steps(Product $product): array
    {
        $product->loadMissing([
            'customer',
            'specs',
            'artworks.versions',
            'boms',
            'routing.operations',
        ]);

        $spec = $product->specs->firstWhere('status', ProductSpec::CURRENT);

        return [
            $this->spec($product, $spec),
            $this->artwork($product),
            $this->bom($product),
            $this->routing($product),
            $this->price($product, $spec),
        ];
    }

    /** @return array<string, mixed> */
    private function spec(Product $product, ?ProductSpec $spec): array
    {
        $step = ['key' => 'spec', 'label' => 'Specification', 'unlocks' => 'Needed to quote and to confirm an order.'];

        if ($spec === null) {
            $draft = $product->specs->firstWhere('status', ProductSpec::DRAFT);

            return $draft !== null
                ? [...$step, 'state' => 'todo', 'detail' => "v{$draft->version_no} is saved as a draft. Make it current to use it.", 'action' => ['type' => 'make_current', 'id' => $draft->id]]
                : [...$step, 'state' => 'todo', 'detail' => 'No specification yet: the size, the web and the material.', 'action' => ['type' => 'new_spec']];
        }

        $problem = $this->specProblem($product, $spec);

        if ($problem !== null) {
            return [...$step, 'state' => 'attention', 'detail' => "v{$spec->version_no} cannot be costed. {$problem}", 'action' => ['type' => 'new_spec']];
        }

        return [...$step, 'state' => 'done', 'detail' => "v{$spec->version_no} is current.", 'action' => null];
    }

    private function specProblem(Product $product, ProductSpec $spec): ?string
    {
        $input = $spec->toCalculatorInput($product->product_type);

        try {
            $this->consumption->plan($input, self::TRIAL_QTY);
        } catch (\InvalidArgumentException $e) {
            // The calculator's messages name the rule first; the person reading this wants the
            // sentence, and the rule is on the tooltip.
            // The calculator's message is written for a costing run. Here the cause is known
            // and so is the way out, so both are said plainly.
            return $spec->web_width_mm === null && $spec->ends === null
                ? 'It has no web width and no ends, so nothing says how many labels run side by side. Save a new version with one of them.'
                : 'The web is too narrow for one label across. Save a new version with a wider web, or type the ends.';
        }

        if ($product->type()->consumesYarn() && $input->fabricGsm <= 0) {
            return 'It has no fabric GSM, so the yarn weight works out to zero. Save a new version with the GSM.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function artwork(Product $product): array
    {
        $step = ['key' => 'artwork', 'label' => 'Artwork', 'unlocks' => 'An approved version is needed to confirm an order.'];

        foreach ($product->artworks as $artwork) {
            $approved = $artwork->versions->firstWhere('status', ArtworkVersion::APPROVED);

            if ($approved !== null) {
                return [...$step, 'state' => 'done', 'detail' => "{$artwork->code} v{$approved->version_no} is approved.", 'action' => null];
            }
        }

        $artwork = $product->artworks->first();

        if ($artwork === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No artwork yet.', 'action' => ['type' => 'new_artwork']];
        }

        $detail = match (true) {
            $artwork->versions->isEmpty() => "{$artwork->code} has no version uploaded yet.",
            $artwork->versions->contains('status', ArtworkVersion::SUBMITTED) => "{$artwork->code} is waiting for approval.",
            default => "{$artwork->code} has no approved version yet.",
        };

        return [...$step, 'state' => 'todo', 'detail' => $detail, 'action' => ['type' => 'open_artwork', 'id' => $artwork->id]];
    }

    /** @return array<string, mixed> */
    private function bom(Product $product): array
    {
        $step = ['key' => 'bom', 'label' => 'Bill of materials', 'unlocks' => 'Gives the material cost, and is needed to release a job card.'];

        // Read here rather than off `$product->boms`: a caller may already have loaded the
        // lines with a narrowed item, and an item without its category looks like no yarn.
        $active = $product->activeBom()->with('lines.item.category')->first();

        if ($active === null) {
            $draft = $product->boms->firstWhere('status', Bom::DRAFT);

            return $draft !== null
                ? [...$step, 'state' => 'todo', 'detail' => "v{$draft->version_no} is saved as a draft. Activate it to use it.", 'action' => ['type' => 'activate_bom', 'id' => $draft->id]]
                : [...$step, 'state' => 'todo', 'detail' => 'No bill of materials yet.', 'action' => ['type' => 'new_bom']];
        }

        $problem = $this->bomProblem($product, $active);

        if ($problem !== null) {
            return [...$step, 'state' => 'attention', 'detail' => "v{$active->version_no}: {$problem}", 'action' => ['type' => 'new_bom']];
        }

        return [...$step, 'state' => 'done', 'detail' => "v{$active->version_no} is active, {$active->lines->count()} ".($active->lines->count() === 1 ? 'line' : 'lines').'.', 'action' => null];
    }

    /**
     * Only the material a type cannot be made without is checked: yarn for a woven label, ink
     * for a printed one. Anything finer is a judgement about a recipe, not a missing input.
     */
    private function bomProblem(Product $product, Bom $bom): ?string
    {
        $type = $product->type();
        $needed = match (true) {
            $type->consumesYarn() => 'yarn',
            $type->consumesInk() => 'ink',
            default => null,
        };

        if ($needed === null) {
            return null;
        }

        $lines = $bom->lines->filter(fn ($line): bool => $line->item?->category?->item_class === $needed);

        if ($lines->isEmpty()) {
            return "there is no {$needed} on it, so this product will be priced with no {$needed} cost.";
        }

        // Costing takes the best rate in the class, so one rated item is enough.
        if ($lines->every(fn ($line): bool => (float) $line->item->avg_rate <= 0 && (float) $line->item->std_rate <= 0)) {
            return "the {$needed} on it has no rate on the item master, so it adds nothing to the price.";
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function routing(Product $product): array
    {
        $step = ['key' => 'routing', 'label' => 'Routing', 'unlocks' => 'Gives the machine, labour and energy cost.'];
        $routing = $product->routing;

        if ($routing === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'No routing chosen, so no machine time is costed.', 'action' => ['type' => 'choose_routing']];
        }

        if ($routing->product_type !== null && $routing->product_type !== $product->product_type) {
            return [...$step, 'state' => 'attention', 'detail' => "{$routing->code} is a routing for another product type.", 'action' => ['type' => 'choose_routing']];
        }

        if (! $routing->operations->contains(fn ($operation): bool => (float) $operation->std_rate_per_hour > 0)) {
            return [...$step, 'state' => 'attention', 'detail' => "{$routing->code} has no operation with a run rate, so no machine time is costed.", 'action' => ['type' => 'open_routing', 'id' => $routing->id]];
        }

        $count = $routing->operations->count();

        return [...$step, 'state' => 'done', 'detail' => "{$routing->code} · {$routing->name}, {$count} ".($count === 1 ? 'operation' : 'operations').'.', 'action' => ['type' => 'choose_routing', 'quiet' => true]];
    }

    /**
     * The proof: the same cost sheet a quotation line runs, at a trial quantity. Whatever the
     * four steps above missed shows up here as a failure or a zero, before a customer is waiting.
     *
     * @return array<string, mixed>
     */
    private function price(Product $product, ?ProductSpec $spec): array
    {
        $step = ['key' => 'price', 'label' => 'Trial price', 'unlocks' => null, 'action' => null];

        if ($spec === null) {
            return [...$step, 'state' => 'todo', 'detail' => 'Worked out once there is a current specification.'];
        }

        try {
            $sheet = $this->costing->calculate($product, $spec, self::TRIAL_QTY);
        } catch (\InvalidArgumentException|\DivisionByZeroError) {
            return [...$step, 'state' => 'attention', 'detail' => 'Cannot be priced until the specification above is fixed.'];
        }

        if ($sheet->ratePerM <= 0) {
            return [...$step, 'state' => 'attention', 'detail' => 'Prices at zero: nothing above puts a cost on it. A quotation line for this product will not save.'];
        }

        return [
            ...$step,
            'state' => 'done',
            'detail' => 'What a quotation line would compute today.',
            'rate_per_m' => round($sheet->ratePerM, 4),
            'qty' => self::TRIAL_QTY,
            'margin_pct' => $sheet->marginPct,
            'currency' => (string) $this->settings->get('base_currency', 'BDT'),
            // BR-21 — said out loud, because a topped-up rate looks like a healthy one.
            'minimum_applied' => $sheet->belowMinimumOrderValue,
            'parts' => [
                'material' => round($sheet->materialCost, 2),
                'conversion' => round($sheet->machineCost + $sheet->labourCost + $sheet->energyCost, 2),
            ],
        ];
    }
}
