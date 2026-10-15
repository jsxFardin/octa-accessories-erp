<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sampling\Models\SampleRequestLine;
use App\Support\Calculators\CapacityCalculator;
use App\Support\Calculators\ConsumptionCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a job card from any of its sources — an order line, a sample request line, a parent
 * job, or stock — with the consumption plan snapshotted and an operation per routing step.
 *
 * Gate 1 lives here for bulk: a family that needs artwork cannot raise a card without an
 * approved version. A sample card is the exception by design (AS-5 AC4): the sample is how
 * the version earns its approval, so it runs against the version named on the sample line.
 */
class JobCardFactory
{
    public function __construct(
        private readonly ConsumptionCalculator $consumption,
        private readonly CapacityCalculator $capacity,
        private readonly JobCardPlanningGuard $planning,
    ) {}

    /**
     * @param  array{
     *     factory_unit_id: int|string,
     *     planned_qty: float|int|string,
     *     parent_job_card_id?: int|string|null,
     *     colourway?: string|null,
     *     due_date?: string|null,
     *     priority?: int|string|null,
     *     product_id?: int|string|null,
     * }  $data
     * @param  string  $field  The request field a refusal is reported against.
     */
    public function create(
        array $data,
        User $actor,
        ?SalesOrderLine $orderLine = null,
        ?SampleRequestLine $sampleLine = null,
        string $field = 'product_id',
    ): JobCard {
        $productId = $orderLine->product_id ?? $sampleLine->product_id ?? $data['product_id'] ?? null;

        if ($productId === null) {
            throw ValidationException::withMessages([$field => 'Name the product before raising a job card for it.']);
        }

        /** @var Product $product */
        $product = Product::query()->with(['routing.operations', 'activeBom', 'item.family'])->findOrFail($productId);

        // The label families run on geometry: a spec, gross metres, ends. Every other family
        // runs in the item's own unit. Artwork is a gate where the family says so (printed
        // label, paper, packaging, decoration) and for every label type.
        $labelGeometry = $product->product_type !== 'other';
        $artworkRequired = $labelGeometry || (bool) ($product->item->family->requires_artwork ?? false);

        $version = $this->artworkVersion($product, $sampleLine);

        if ($sampleLine === null && $artworkRequired && $version === null) {
            throw ValidationException::withMessages([
                $field => 'This product has no approved artwork version, and a job card cannot be raised without one. '
                    .'Approve an artwork version on the product, then try again.',
            ]);
        }

        if ($product->routing === null) {
            throw ValidationException::withMessages([
                $field => 'This product has no routing, so there are no steps to plan. Choose a routing on the product, then try again.',
            ]);
        }

        $spec = $labelGeometry ? ($sampleLine->spec ?? $product->currentSpec) : null;

        if ($labelGeometry && $spec === null) {
            throw ValidationException::withMessages([
                $field => 'This product has no specification, so its consumption cannot be planned. Add a specification on the product, then try again.',
            ]);
        }

        return DB::transaction(function () use ($data, $orderLine, $sampleLine, $product, $spec, $labelGeometry, $version, $actor): JobCard {
            $plan = null;
            $plannedQty = (float) $data['planned_qty'];

            if ($orderLine !== null) {
                // BR-49 — decided under a lock on the order line, inside the transaction that
                // writes the card, so two planners submitting at once serialise on the row the
                // ceiling is summed over.
                $locked = SalesOrderLine::query()->lockForUpdate()->findOrFail($orderLine->id);

                $this->planning->assert($locked, $plannedQty);
            }

            if ($labelGeometry) {
                // The snapshot (02-database-schema §3.8): these three figures follow the card,
                // not the spec, for the rest of its life.
                $plan = $this->consumption->plan(
                    $spec->toCalculatorInput($product->product_type),
                    (int) $plannedQty,
                    $product->routing->toCalculatorSteps(),
                    $spec->colourWeights(),
                );
            }

            $jobCard = JobCard::query()->create([
                'factory_unit_id' => $data['factory_unit_id'],
                'sales_order_line_id' => $orderLine?->id,
                'sample_request_line_id' => $sampleLine?->id,
                'parent_job_card_id' => $data['parent_job_card_id'] ?? null,
                'for_stock' => $orderLine === null && $sampleLine === null && empty($data['parent_job_card_id']),
                'product_id' => $product->id,
                'product_spec_id' => $spec?->id,
                'artwork_version_id' => $version?->id,
                'bom_id' => $product->activeBom?->id,
                'routing_id' => $product->routing_id,
                'colourway' => $data['colourway'] ?? $sampleLine?->colourway,
                'planned_qty' => $plannedQty,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'] ?? 50,
                'gross_metres' => $plan?->grossMetres,
                'ends' => $plan?->ends,
                'labels_per_metre' => $plan?->labelsPerMetre,
                'status' => JobCard::DRAFT,
                'created_by' => $actor->id,
            ]);

            // In the item's own unit, each step must put out enough for every step after it:
            // the planned input of a step is the next step's planned output grossed up by
            // this step's wastage, walked back from the finished quantity.
            $grossByStep = [];
            $need = $plannedQty;

            foreach ($product->routing->operations->sortByDesc('sequence_no') as $operation) {
                $need = $need * (1 + (float) $operation->wastage_pct / 100);
                $grossByStep[$operation->id] = round($need, 6);
            }

            foreach ($product->routing->operations as $operation) {
                $planned = $labelGeometry
                    ? ($operation->consumes_web ? $plan->grossMetres : $plannedQty)
                    : $grossByStep[$operation->id];

                JobCardOperation::query()->create([
                    'job_card_id' => $jobCard->id,
                    'routing_operation_id' => $operation->id,
                    'sequence_no' => $operation->sequence_no,
                    'code' => $operation->code,
                    'name' => $operation->name,
                    'machine_group_id' => $operation->machine_group_id,
                    'planned_qty' => $planned,
                    'planned_minutes' => $this->capacity->loadMinutes(
                        $planned,
                        (float) ($operation->std_rate_per_hour ?? 0),
                        (float) $operation->setup_minutes,
                    ),
                    'requires_qc' => $operation->requires_qc,
                    'is_optional' => $operation->is_optional,
                    'status' => JobCardOperation::PENDING,
                ]);
            }

            return $jobCard;
        });
    }

    /**
     * Bulk runs on the product's approved version. A sample runs on the version its line
     * names, whatever its status, and falls back to the approved one when the line names none.
     */
    private function artworkVersion(Product $product, ?SampleRequestLine $sampleLine): ?ArtworkVersion
    {
        if ($sampleLine?->artwork_version_id !== null) {
            return $sampleLine->artworkVersion;
        }

        return ArtworkVersion::query()
            ->whereIn('artwork_id', $product->artworks()->select('id'))
            ->where('status', ArtworkVersion::APPROVED)
            ->first();
    }
}
