<?php

declare(strict_types=1);

namespace App\Modules\Costing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Product\Models\Product;
use App\Modules\Product\Models\ProductSpec;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The cost sheet panel recomputes live as a merchandiser types (08-architecture §4). Every
 * number returned carries the rule that produced it, so the panel can print `BR-9` beside the
 * yarn line and settle the argument on the spot.
 */
class CostSheetController extends Controller
{
    public function __construct(
        private readonly CostSheetService $service,
        private readonly Settings $settings,
    ) {}

    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'product_spec_id' => ['nullable', 'integer', 'exists:product_specs,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'margin_pct' => ['nullable', 'numeric', 'min:0', 'lt:100'],
            'overhead_pct' => ['nullable', 'numeric', 'min:0'],
            'admin_pct' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'customer_pays_tooling' => ['nullable', 'boolean'],
            'outsourcing_cost' => ['nullable', 'numeric', 'min:0'],
            'freight_cost' => ['nullable', 'numeric', 'min:0'],
            'packing_rate_per_bundle' => ['nullable', 'numeric', 'min:0'],
            'packing_rate_per_polybag' => ['nullable', 'numeric', 'min:0'],
            'packing_rate_per_carton' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var Product $product */
        $product = Product::query()->with(['customer', 'routing.operations'])->findOrFail($data['product_id']);

        $spec = $data['product_spec_id'] ?? null
            ? ProductSpec::query()->findOrFail($data['product_spec_id'])
            : $product->currentSpec;

        if ($spec === null) {
            throw ValidationException::withMessages([
                'product_spec_id' => 'This product has no current spec (P2). Create one before costing it.',
            ]);
        }

        $marginPct = (float) ($data['margin_pct'] ?? $this->settings->decimal('default_margin_pct', 20));
        $marginFloor = $this->settings->decimal('margin_floor_pct', 12);

        try {
            $sheet = $this->service->calculate($product, $spec, (int) $data['qty'], [
                'marginPct' => $marginPct,
                'overheadPct' => $data['overhead_pct'] ?? null,
                'adminPct' => $data['admin_pct'] ?? null,
                'exchangeRate' => $data['exchange_rate'] ?? 1,
                'currency' => $data['currency'] ?? $this->settings->get('base_currency', 'BDT'),
                'customerPaysTooling' => $data['customer_pays_tooling'] ?? false,
                'outsourcingCost' => $data['outsourcing_cost'] ?? 0,
                'freightCost' => $data['freight_cost'] ?? 0,
                'packingRatePerBundle' => $data['packing_rate_per_bundle'] ?? 0,
                'packingRatePerPolybag' => $data['packing_rate_per_polybag'] ?? 0,
                'packingRatePerCarton' => $data['packing_rate_per_carton'] ?? 0,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['qty' => $e->getMessage()]);
        }

        return response()->json([
            'sheet' => $sheet->toArray(),
            'warnings' => array_values(array_filter([
                // BR-21 is a flag, not a silent adjustment: the merchandiser has to see it.
                $sheet->belowMinimumOrderValue
                    ? 'Below this customer\'s minimum order value, so a minimum charge has been added.'
                    : null,
                $marginPct < $marginFloor
                    ? "Margin {$marginPct}% is below the {$marginFloor}% floor. Sending needs someone who may approve a margin below the floor."
                    : null,
            ])),
            'needs_margin_override' => $marginPct < $marginFloor,
        ]);
    }

    /** Q1 — a locked sheet belongs to a sent quotation and does not change. */
    public function update(Request $request, CostSheet $costSheet): RedirectResponse
    {
        if ($costSheet->is_locked) {
            return back()->with('error', 'This cost sheet is locked: its quotation has been sent (Q1).');
        }

        $data = $request->validate([
            'margin_pct' => ['required', 'numeric', 'min:0', 'lt:100'],
            'overhead_pct' => ['required', 'numeric', 'min:0'],
            'admin_pct' => ['required', 'numeric', 'min:0'],
        ]);

        $floor = $this->settings->decimal('margin_floor_pct', 12);

        if ($data['margin_pct'] < $floor && ! $request->user()->hasPermission('cost_sheet.override_margin')) {
            return back()->with('error', "A margin below {$floor}% needs someone who may approve a margin below the floor. Ask a sales manager to save it.");
        }

        $costSheet->update($data);

        return back()->with('success', 'Cost sheet updated.');
    }

    /**
     * Spec §4 — the variance is explained, with a reason code, by the person who costed it.
     * Allowed on a locked post-production sheet: the figures are final, the reading is not.
     */
    public function reason(Request $request, CostSheet $costSheet): RedirectResponse
    {
        if ($costSheet->stage !== CostSheet::POST_PRODUCTION) {
            return back()->with('error', 'Only a post-production sheet carries a variance reason.');
        }

        $data = $request->validate(['variance_reason' => ['required', 'string', 'max:255']]);
        $costSheet->update($data);

        return back()->with('success', 'Variance reason recorded.');
    }

    /**
     * Spec §4 — post-production results feed the standards: the wastage this job actually
     * ran at becomes the item's standard wastage, by an engineer's decision, not by itself.
     */
    public function adoptWastage(Request $request, CostSheet $costSheet): RedirectResponse
    {
        $job = $costSheet->job_card_id === null ? null : \App\Modules\Manufacturing\Models\JobCard::query()->with('product.item')->find($costSheet->job_card_id);

        if ($job === null || $job->product?->item === null) {
            return back()->with('error', 'This sheet is not a job\'s actuals.');
        }

        $waste = (float) \Illuminate\Support\Facades\DB::table('waste_logs')->where('job_card_id', $job->id)->sum('qty');
        $produced = $job->finalOperationOutput()['good'] + $waste;

        if ($produced <= 0) {
            return back()->with('error', 'The job made nothing to measure wastage against.');
        }

        $pct = round($waste / $produced * 100, 4);
        $job->product->item->update(['standard_wastage_pct' => $pct]);

        return back()->with('success', "Standard wastage of {$job->product->item->code} set to {$pct}% from this job.");
    }
}
