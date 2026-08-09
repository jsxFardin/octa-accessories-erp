<?php

declare(strict_types=1);

namespace App\Modules\Planning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\MasterData\Models\Item;
use App\Support\Calculators\MrpCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BR-24 … BR-26.
 *
 * The *output* of a run is persisted rather than recomputed on demand, because a planner has
 * to be able to answer "what did the system tell me on Tuesday?" and a live recompute cannot
 * (02-database-schema §3.7).
 */
class MrpController extends Controller
{
    public function __construct(
        private readonly MrpCalculator $mrp,
        private readonly StockAvailability $availability,
    ) {}

    public function index(Request $request): Response
    {
        $runId = $request->integer('run') ?: DB::table('mrp_runs')->max('id');

        return Inertia::render('Planning/Mrp', [
            'runs' => DB::table('mrp_runs')->orderByDesc('id')->limit(20)
                ->get(['id', 'run_at', 'horizon_from', 'horizon_to', 'status', 'shortage_count']),
            'run' => $runId ? DB::table('mrp_runs')->find($runId) : null,
            'requirements' => $runId
                ? DB::table('material_requirements as mr')
                    ->join('items as i', 'i.id', '=', 'mr.item_id')
                    ->where('mr.mrp_run_id', $runId)
                    ->orderByDesc('mr.net_req_qty')
                    ->get([
                        'mr.id', 'i.code as item_code', 'i.name as item_name', 'mr.gross_req_qty',
                        'mr.on_hand_qty', 'mr.on_order_qty', 'mr.reserved_qty', 'mr.net_req_qty',
                        'mr.suggested_po_qty', 'mr.need_date', 'mr.po_place_by', 'mr.is_shortage',
                    ])
                : [],
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $horizonDays = min(180, max(7, (int) $request->input('horizon_days', 60)));

        $runId = DB::transaction(function () use ($horizonDays, $request): int {
            $runId = DB::table('mrp_runs')->insertGetId([
                'factory_unit_id' => (int) DB::table('factory_units')->orderBy('id')->value('id'),
                'horizon_from' => now()->toDateString(),
                'horizon_to' => now()->addDays($horizonDays)->toDateString(),
                'run_at' => now(),
                'status' => 'running',
                'run_by' => $request->user()->id,
            ]);

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

                $onHand = $this->availability->onHand($itemId);
                $onOrder = $this->availability->onOrder($itemId);
                $reserved = $this->availability->reserved($itemId);

                $result = $this->mrp->netRequirement($row['gross'], $onHand, $onOrder, $reserved);

                $needDate = $this->mrp->materialNeedDate(
                    CarbonImmutable::parse($row['need_date'] ?? now()->addDays(14)->toDateString()),
                    (int) $item->safety_days,
                );

                $leadTime = (int) (DB::table('suppliers')
                    ->where('id', $item->default_supplier_id)
                    ->value('lead_time_days') ?? 0);

                DB::table('material_requirements')->insert([
                    'mrp_run_id' => $runId,
                    'item_id' => $itemId,
                    'gross_req_qty' => $result['gross_req'],
                    'on_hand_qty' => $onHand,
                    'on_order_qty' => $onOrder,
                    'reserved_qty' => $reserved,
                    'net_req_qty' => $result['net_req'],
                    'suggested_po_qty' => $this->mrp->suggestedPurchaseQty(
                        $result['net_req'],
                        (float) $item->min_order_qty,
                        (float) $item->order_multiple,
                    ),
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

            return $runId;
        });

        return redirect()
            ->route('mrp.index', ['run' => $runId])
            ->with('success', 'MRP run complete.');
    }
}
