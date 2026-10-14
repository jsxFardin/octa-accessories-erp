<?php

declare(strict_types=1);

namespace App\Modules\Planning\Services;

use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\MasterData\Models\Item;
use App\Support\Calculators\MrpCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * BR-24 … BR-26 — one material plan run, from open job cards to persisted requirements.
 *
 * The *output* of a run is persisted rather than recomputed on demand, because a planner has
 * to be able to answer "what did the system tell me on Tuesday?" and a live recompute cannot
 * (02-database-schema §3.7). It lives here, not in the controller, so the queue worker and the
 * request can both run it (NFR-3): a production-size run no longer ties up a web request.
 */
class MrpRunner
{
    public function __construct(
        private readonly MrpCalculator $mrp,
        private readonly StockAvailability $availability,
    ) {}

    /** Opens a run row in `running`; the computation fills it in. */
    public function open(int $horizonDays, ?int $userId): int
    {
        return (int) DB::table('mrp_runs')->insertGetId([
            'factory_unit_id' => (int) DB::table('factory_units')->orderBy('id')->value('id'),
            'horizon_from' => now()->toDateString(),
            'horizon_to' => now()->addDays($horizonDays)->toDateString(),
            'run_at' => now(),
            'status' => 'running',
            'run_by' => $userId,
        ]);
    }

    /** Computes the requirements for an open run and marks it completed, or failed with the reason. */
    public function compute(int $runId, int $horizonDays): void
    {
        try {
            DB::transaction(function () use ($runId, $horizonDays): void {
                $shortages = 0;
                $horizonEnd = CarbonImmutable::now()->addDays($horizonDays);

                $jobCards = JobCard::query()
                    ->whereIn('status', [JobCard::DRAFT, JobCard::PLANNED, JobCard::MATERIAL_PENDING, JobCard::RELEASED])
                    ->whereNotNull('bom_id')
                    ->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', $horizonEnd))
                    ->get();

                /** @var array<int, array{gross: float, need_date: string|null}> $demand */
                $demand = [];

                foreach ($jobCards as $jobCard) {
                    $lines = DB::table('bom_lines as bl')
                        ->join('boms as b', 'b.id', '=', 'bl.bom_id')
                        ->where('bl.bom_id', $jobCard->bom_id)
                        ->get(['bl.item_id', 'bl.qty_per_base', 'b.base_qty']);

                    foreach ($lines as $line) {
                        $required = (float) $line->qty_per_base * ((float) $jobCard->planned_qty / (float) $line->base_qty);
                        $itemId = (int) $line->item_id;

                        $demand[$itemId]['gross'] = ($demand[$itemId]['gross'] ?? 0) + $required;

                        // The earliest need date across every job card wins: material is needed
                        // when the first job needs it, not on average.
                        $needDate = $jobCard->planned_start?->toDateString() ?? $jobCard->due_date?->toDateString();

                        if ($needDate !== null) {
                            $existing = $demand[$itemId]['need_date'] ?? null;
                            $demand[$itemId]['need_date'] = $existing === null ? $needDate : min($existing, $needDate);
                        }
                    }
                }

                foreach ($demand as $itemId => $row) {
                    $item = Item::query()->find($itemId);

                    if ($item === null) {
                        continue;
                    }

                    $needDate = $this->mrp->materialNeedDate(
                        CarbonImmutable::parse($row['need_date'] ?? now()->addDays(14)->toDateString()),
                        (int) $item->safety_days,
                    );

                    $onHand = $this->availability->onHand($itemId);
                    // BR-24 — an order that lands after the need date does not cover it.
                    $onOrder = $this->availability->onOrder($itemId, $needDate->toDateString());
                    $reserved = $this->availability->reserved($itemId);

                    $result = $this->mrp->netRequirement($row['gross'], $onHand, $onOrder, $reserved);

                    // BR-26 — the lead time is the supplier's for *this item* where one is agreed
                    // (`supplier_items`), the supplier's general figure otherwise.
                    $leadTime = (int) (DB::table('supplier_items')
                        ->where('supplier_id', $item->default_supplier_id)
                        ->where('item_id', $itemId)
                        ->value('lead_time_days')
                        ?? DB::table('suppliers')->where('id', $item->default_supplier_id)->value('lead_time_days')
                        ?? 0);

                    DB::table('material_requirements')->insert([
                        'mrp_run_id' => $runId,
                        'item_id' => $itemId,
                        'gross_req_qty' => $result['gross_req'],
                        'on_hand_qty' => $onHand,
                        'on_order_qty' => $onOrder,
                        'reserved_qty' => $reserved,
                        'net_req_qty' => $result['net_req'],
                        // A made item is not bought: the shortage stands, and the suggestion is a
                        // job card for the net requirement rather than a purchase order.
                        'suggested_po_qty' => $item->isMade() ? 0 : $this->mrp->suggestedPurchaseQty(
                            $result['net_req'],
                            (float) $item->min_order_qty,
                            (float) $item->order_multiple,
                        ),
                        'suggested_make_qty' => $item->isMade() ? max(0.0, (float) $result['net_req']) : 0,
                        'need_date' => $needDate->toDateString(),
                        'po_place_by' => $this->mrp->placeByDate($needDate, $leadTime)->toDateString(),
                        'is_shortage' => $result['has_shortage'],
                    ]);

                    if ($result['has_shortage']) {
                        $shortages++;
                    }
                }

                DB::table('mrp_runs')->where('id', $runId)->update([
                    'status' => 'completed',
                    'shortage_count' => $shortages,
                ]);
            });
        } catch (Throwable $e) {
            DB::table('mrp_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'notes' => mb_substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }
    }
}
