<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\Bom;
use App\Modules\Product\Models\Tool;

/**
 * J1 — the four conditions a job card must satisfy before it may be released.
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
        $artwork = $this->artworkCheck($jobCard);
        $bom = $this->bomCheck($jobCard);
        $tools = $this->toolCheck($jobCard);
        $shortages = $this->availability->shortagesFor($jobCard);

        $materialOk = $shortages === [] || $materialWaived;

        $checks = [
            'artwork' => [
                'ok' => $artwork,
                'label' => 'Approved artwork version',
                'rule' => 'J1 · Gate 1',
                'detail' => $artwork
                    ? 'Version '.$jobCard->artworkVersion->version_no.' is approved.'
                    : 'The bound artwork version is not approved. Production cannot run against it.',
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
            'material' => [
                'ok' => $materialOk,
                'label' => 'Material in stock',
                'rule' => 'J1 · BR-24',
                'detail' => match (true) {
                    $shortages === [] => 'All BOM materials are available.',
                    $materialWaived => count($shortages).' shortage(s) waived by a planner with a reason.',
                    default => count($shortages).' material(s) short. Waive with a reason, or wait for the GRN.',
                },
            ],
        ];

        return [
            'ready' => $artwork && $bom && $tools['ok'] && $materialOk,
            'checks' => $checks,
            'shortages' => $shortages,
        ];
    }

    /**
     * Gate 1. The FK is NOT NULL, so the only remaining question is whether the version it
     * points at is still approved — a version can be superseded after a card is planned.
     */
    private function artworkCheck(JobCard $jobCard): bool
    {
        return $jobCard->artworkVersion?->status === ArtworkVersion::APPROVED;
    }

    private function bomCheck(JobCard $jobCard): bool
    {
        return $jobCard->bom !== null && $jobCard->bom->status === Bom::ACTIVE;
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
