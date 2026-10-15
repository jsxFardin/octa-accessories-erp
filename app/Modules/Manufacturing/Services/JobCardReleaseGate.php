<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\Tool;
use Illuminate\Support\Facades\DB;

/**
 * J1 — the conditions a job card must satisfy before it may be released: artwork, BOM,
 * tools, a machine on every operation, a QC plan, and material.
 *
 * This returns a *report*, not a boolean. A supervisor whose release is blocked at 2 a.m.
 * needs to know which condition failed and by how much, not "forbidden".
 */
class JobCardReleaseGate
{
    public function __construct(private readonly StockAvailability $availability) {}

    /**
     * @return array{
     *     ready: bool,
     *     checks: array<string, array{ok: bool, label: string, rule: string, detail: string}>,
     *     shortages: list<array<string, mixed>>
     * }
     */
    public function evaluate(JobCard $jobCard, bool $materialWaived = false): array
    {
        $artworkCheck = $this->artworkCheck($jobCard);
        $artwork = $artworkCheck['ok'];
        $bom = $this->bomCheck($jobCard);
        $tools = $this->toolCheck($jobCard);
        $machines = $this->machineCheck($jobCard);
        $qcPlan = $this->qcPlanCheck($jobCard);
        $sample = $this->sampleApprovalCheck($jobCard);
        $shortages = $this->availability->shortagesFor($jobCard);

        $materialOk = $shortages === [] || $materialWaived;

        $checks = [
            'artwork' => [
                'ok' => $artwork,
                'label' => 'Approved artwork version',
                'rule' => 'J1 · Gate 1',
                'detail' => $artworkCheck['detail'],
            ],
            'bom' => [
                'ok' => $bom,
                'label' => 'Active BOM',
                'rule' => 'J1 · PD-3',
                'detail' => $bom ? 'An active BOM is bound.' : 'No active BOM for this product.',
            ],
            'tools' => [
                'ok' => $tools['ok'],
                'label' => 'Required tools available',
                'rule' => 'J1 · BR-13',
                'detail' => $tools['detail'],
            ],
            'machines' => [
                'ok' => $machines['ok'],
                'label' => 'Machine on every operation',
                'rule' => 'J1 · J2',
                'detail' => $machines['detail'],
            ],
            'qc_plan' => [
                'ok' => $qcPlan['ok'],
                'label' => 'QC plan',
                'rule' => 'J1 · QC1',
                'detail' => $qcPlan['detail'],
            ],
            'sample_approval' => [
                'ok' => $sample['ok'],
                'label' => 'Pre-production sample approved',
                'rule' => 'J1 · AS-7',
                'detail' => $sample['detail'],
            ],
            'material' => [
                'ok' => $materialOk,
                'label' => 'Material in stock',
                'rule' => 'J1 · BR-24',
                'detail' => match (true) {
                    $shortages === [] => 'All BOM materials are available.',
                    $materialWaived => count($shortages).' shortage(s) waived by a planner with a reason.',
                    default => count($shortages).' material(s) short. Waive with a reason, or wait for the goods receipt.',
                },
            ],
        ];

        return [
            'ready' => $artwork && $bom && $tools['ok'] && $machines['ok'] && $qcPlan['ok'] && $sample['ok'] && $materialOk,
            'checks' => $checks,
            'shortages' => $shortages,
        ];
    }

    /**
     * Gate 1. A label type, and any family that says it needs artwork, must carry an approved
     * version — and a version can be superseded after a card is planned. Tape, zipper, cord
     * and injection run without one.
     *
     * @return array{ok: bool, detail: string}
     */
    private function artworkCheck(JobCard $jobCard): array
    {
        $version = $jobCard->artworkVersion;

        if ($version !== null) {
            // AS-5 AC4 — a sample runs on the version it is sampling; the run is how that
            // version earns its approval, so only bulk is held to an approved one.
            if ($jobCard->sample_request_line_id !== null) {
                return ['ok' => true, 'detail' => "Sample run against version {$version->version_no} ({$version->status})."];
            }

            return $version->status === ArtworkVersion::APPROVED
                ? ['ok' => true, 'detail' => "Version {$version->version_no} is approved."]
                : ['ok' => false, 'detail' => 'The bound artwork version is not approved. Production cannot run against it.'];
        }

        $product = $jobCard->product;
        $family = $product?->item?->family;
        $required = ($product->product_type ?? 'other') !== 'other'
            || (bool) ($family?->getAttribute('requires_artwork') ?? false);

        return $required
            ? ['ok' => false, 'detail' => 'No approved artwork version is bound, and this family does not run without one.']
            : ['ok' => true, 'detail' => 'This family runs without artwork.'];
    }

    /**
     * AS-7 AC3 — a customer flagged `requires_sample_approval` gets no bulk run of a product
     * until a pre-production sample of it has been approved. Sample cards, stock runs and
     * component jobs are not bulk for a customer and pass.
     *
     * @return array{ok: bool, detail: string}
     */
    private function sampleApprovalCheck(JobCard $jobCard): array
    {
        if ($jobCard->sales_order_line_id === null) {
            return ['ok' => true, 'detail' => 'Not a bulk run for a customer order.'];
        }

        $customerId = DB::table('sales_order_lines as sol')
            ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
            ->where('sol.id', $jobCard->sales_order_line_id)
            ->value('so.customer_id');

        $required = $customerId !== null
            && (bool) DB::table('customers')->where('id', $customerId)->value('requires_sample_approval');

        if (! $required) {
            return ['ok' => true, 'detail' => 'This customer does not require a sample approval before bulk.'];
        }

        $approved = DB::table('sample_request_lines as l')
            ->join('sample_requests as r', 'r.id', '=', 'l.sample_request_id')
            ->where('r.customer_id', $customerId)
            ->where('r.sample_type', 'pre_production')
            ->where('l.product_id', $jobCard->product_id)
            ->where('l.status', 'approved')
            ->orderByDesc('l.id')
            ->first(['r.number']);

        return $approved !== null
            ? ['ok' => true, 'detail' => "Pre-production sample approved on {$approved->number}."]
            : ['ok' => false, 'detail' => 'This customer requires an approved pre-production sample of the product before bulk runs, and none is approved yet. Raise a sample request, dispatch it and record the approval.'];
    }

    private function bomCheck(JobCard $jobCard): bool
    {
        return $jobCard->bom !== null && $jobCard->bom->status === Bom::ACTIVE;
    }

    /**
     * Every operation that runs on a machine group has a machine chosen from it. A job with
     * nowhere to run is not planned, whatever its status says; the planning board is where
     * the machine is chosen.
     *
     * @return array{ok: bool, detail: string}
     */
    private function machineCheck(JobCard $jobCard): array
    {
        $unassigned = $jobCard->operations()
            ->whereNotNull('machine_group_id')
            ->whereNull('machine_id')
            ->orderBy('sequence_no')
            ->pluck('name')
            ->all();

        $total = $jobCard->operations()->whereNotNull('machine_group_id')->count();

        if ($total === 0) {
            return ['ok' => true, 'detail' => 'No operation needs a machine.'];
        }

        return [
            'ok' => $unassigned === [],
            'detail' => $unassigned === []
                ? "{$total} operation(s) scheduled on a machine."
                : 'No machine chosen for: '.implode(', ', $unassigned).'. Schedule them on the planning board.',
        ];
    }

    /**
     * Something will inspect the output: an operation on the routing requires QC, or the
     * item names a QC plan. Without either, the QC gate after production has nothing to
     * hold the goods against.
     *
     * @return array{ok: bool, detail: string}
     */
    private function qcPlanCheck(JobCard $jobCard): array
    {
        $qcSteps = $jobCard->operations()->where('requires_qc', true)->count();

        if ($qcSteps > 0) {
            return ['ok' => true, 'detail' => "{$qcSteps} operation(s) end in an inspection."];
        }

        $plan = $jobCard->product?->item?->qc_plan_ref;

        if (filled($plan)) {
            return ['ok' => true, 'detail' => "Item QC plan {$plan}."];
        }

        return [
            'ok' => false,
            'detail' => 'No operation requires QC and the item names no QC plan. Mark the inspection step on the routing, or set the plan on the item.',
        ];
    }

    /**
     * BR-13 — every operation that names a tool needs that tool available, with life left.
     *
     * @return array{ok: bool, detail: string}
     */
    private function toolCheck(JobCard $jobCard): array
    {
        $toolIds = $jobCard->operations()->whereNotNull('tool_id')->pluck('tool_id')->all();

        if ($toolIds === []) {
            return ['ok' => true, 'detail' => 'This routing needs no tooling.'];
        }

        $unavailable = Tool::query()
            ->whereIn('id', $toolIds)
            ->where(function ($query): void {
                $query->where('status', '!=', 'available')
                    ->orWhereColumn('used_impressions', '>=', 'life_impressions');
            })
            ->pluck('code')
            ->all();

        return [
            'ok' => $unavailable === [],
            'detail' => $unavailable === []
                ? count($toolIds).' tool(s) available.'
                : 'Unavailable or worn out: '.implode(', ', $unavailable),
        ];
    }
}
