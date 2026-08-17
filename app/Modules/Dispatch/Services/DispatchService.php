<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Support\Calculators\SalesToleranceCalculator;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * P0-4.7 — the critical stock boundary. Dispatch is the ONE physical exit for finished goods:
 * every challan line becomes exactly one `dispatch` ledger movement through
 * StockPostingService, `delivered_qty` moves in the same transaction, and the CoC output side
 * is written here and nowhere earlier (05-workflows §10).
 *
 * Called from DeliveryChallanStateMachine's guard/effect — both already run inside the
 * transition's transaction, so a failure anywhere rolls back stock, quantities, CoC and the
 * status change together.
 */
class DispatchService
{
    public function __construct(
        private readonly StockPostingService $posting,
        private readonly SalesToleranceCalculator $tolerance,
    ) {}

    /**
     * Guard half: lock, then revalidate everything the frontend claimed.
     *
     * Lock order — SO lines ascending, then lots ascending, matching the documented order for
     * this feature; no existing path takes lot → SO line, so no cycle.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws TransitionDenied
     */
    public function validateIssue(DeliveryChallan $challan, array $context): void
    {
        $lines = $this->lines($challan);

        if ($lines->isEmpty()) {
            throw TransitionDenied::guard('D3', 'A challan with no lines cannot be issued.');
        }

        $packingStatus = DB::table('packing_lists')->where('id', $challan->packing_list_id)->value('status');

        if ($packingStatus !== 'packed') {
            throw TransitionDenied::guard('D3', "The packing list must be packed before its challan is issued (it is {$packingStatus}).");
        }

        DB::table('sales_order_lines')
            ->whereIn('id', $lines->pluck('sales_order_line_id')->filter()->sort()->values())
            ->orderBy('id')->lockForUpdate()->get();

        $lots = DB::table('stock_lots')
            ->whereIn('id', $lines->pluck('lot_id')->filter()->sort()->values())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $blocked = [];

        foreach ($lines->groupBy('lot_id') as $lotId => $rows) {
            $lot = $lots[$lotId] ?? null;
            $qty = (float) $rows->sum('qty');

            if ($lot === null) {
                $blocked[] = "Lot #{$lotId} does not exist.";

                continue;
            }

            if ($lot->kind !== 'finished_goods' || $lot->status !== 'available') {
                $blocked[] = "Lot {$lot->lot_no} is {$lot->status} — it cannot leave the factory.";

                continue;
            }

            if ($qty > (float) $lot->balance_qty + 0.000001) {
                $blocked[] = sprintf(
                    'Lot %s: dispatching %s but only %s remains.',
                    $lot->lot_no,
                    rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $lot->balance_qty, 6, '.', ''), '0'), '.'),
                );
            }

            foreach ($rows as $row) {
                if ((int) $row->product_id !== (int) $lot->product_id) {
                    $blocked[] = "Lot {$lot->lot_no} does not hold the product on challan line {$row->line_no}.";
                }
            }
        }

        // A challan may never carry more of a line than its packing list packed (I6).
        foreach ($lines->whereNotNull('sales_order_line_id')->groupBy('sales_order_line_id') as $lineId => $rows) {
            $packed = (float) DB::table('carton_contents as cc')
                ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                ->where('c.packing_list_id', $challan->packing_list_id)
                ->where('cc.sales_order_line_id', $lineId)
                ->sum('cc.qty');

            if ((float) $rows->sum('qty') > $packed + 0.000001) {
                $blocked[] = "Challan carries more of order line #{$lineId} than the packing list packed.";
            }
        }

        if ($blocked !== []) {
            throw TransitionDenied::guard('D3', "This challan cannot be issued.\n• ".implode("\n• ", $blocked));
        }

        $this->guardTolerance($lines, $context);
        $this->guardCertificate($challan);
    }

    /**
     * BR-44 — cumulative delivery per line must land inside the band; over the top needs the
     * named override permission and a typed reason.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $lines
     * @param  array<string, mixed>  $context
     */
    private function guardTolerance($lines, array $context): void
    {
        foreach ($lines->whereNotNull('sales_order_line_id')->groupBy('sales_order_line_id') as $lineId => $rows) {
            $line = DB::table('sales_order_lines')->where('id', $lineId)->first();

            $result = $this->tolerance->check(
                deliveredQty: (float) $line->delivered_qty + (float) $rows->sum('qty'),
                orderedQty: (float) $line->ordered_qty,
                underTolerancePct: (float) $line->under_tolerance_pct,
                overTolerancePct: (float) $line->over_tolerance_pct,
            );

            if ($result['direction'] !== 'over') {
                continue;
            }

            if (blank($context['override_reason'] ?? null)) {
                throw TransitionDenied::guard(
                    'BR-44',
                    sprintf(
                        'Line %d would be delivered %.2f%% over the ordered quantity — beyond the %s%% tolerance. Shipping it needs an override with a reason.',
                        $line->line_no,
                        $result['variance_pct'],
                        rtrim(rtrim((string) $line->over_tolerance_pct, '0'), '.'),
                    ),
                );
            }

            // The catalogue already names this act: sales_order.override_tolerance. Reused
            // rather than minting a challan-side twin — the tolerance belongs to the order.
            if (! (auth()->user()?->hasPermission('sales_order.override_tolerance') ?? false)) {
                throw TransitionDenied::notPermitted('sales_order.override_tolerance');
            }
        }
    }

    /** BR-43 — a certified shipment needs a certificate valid on the challan date. */
    private function guardCertificate(DeliveryChallan $challan): void
    {
        $scheme = DB::table('packing_lists')->where('id', $challan->packing_list_id)->value('cert_claim_scheme');

        if ($scheme === null) {
            return;
        }

        $valid = DB::table('certifications')
            ->where('scheme', $scheme)
            ->whereDate('issued_on', '<=', $challan->challan_date)
            ->whereDate('expires_on', '>=', $challan->challan_date)
            ->exists();

        if (! $valid) {
            throw TransitionDenied::guard(
                'BR-43',
                "This shipment claims {$scheme}, but no {$scheme} certificate is valid on the challan date. Ship without the claim or renew the certificate.",
            );
        }
    }

    /**
     * Effect half: the postings. Locks are already held from validateIssue (same transaction).
     */
    public function postIssue(DeliveryChallan $challan): void
    {
        $lines = $this->lines($challan);

        foreach ($lines as $line) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($line->lot_id);

            // I1 — the only stock writer. One signed movement per challan line.
            $this->posting->post($lot, 'dispatch', -abs((float) $line->qty), $challan);

            if ($line->sales_order_line_id !== null) {
                // Same pattern as produced_qty (P0-2): atomic increment, same transaction
                // as the ledger row — I9 by construction.
                DB::table('sales_order_lines')
                    ->where('id', $line->sales_order_line_id)
                    ->increment('delivered_qty', (float) $line->qty);

                $this->rollupSchedules((int) $line->sales_order_line_id, (float) $line->qty);
            }

            $this->writeCocOutput($challan, $line, $lot);
        }
    }

    /**
     * DC → returned: reverse every dispatch entry of this challan (I1 — corrections are
     * reversing entries), give the quantities back to the same lots, and walk delivered_qty
     * back down. Atomic with the status change.
     */
    public function postReturn(DeliveryChallan $challan): void
    {
        $entries = DB::table('stock_ledger')
            ->where('source_type', DeliveryChallan::class)
            ->where('source_id', $challan->getKey())
            ->where('movement_type', 'dispatch')
            ->where('qty', '<', 0)
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $model = \App\Modules\Inventory\Models\StockLedgerEntry::query()->findOrFail($entry->id);
            $this->posting->reverse($model, "Challan {$challan->number} returned");
        }

        foreach ($this->lines($challan) as $line) {
            if ($line->sales_order_line_id !== null) {
                DB::table('sales_order_lines')
                    ->where('id', $line->sales_order_line_id)
                    ->decrement('delivered_qty', (float) $line->qty);

                $this->rollupSchedules((int) $line->sales_order_line_id, -((float) $line->qty));
            }
        }
    }

    /** Delivery schedules fill in due-date order; a return unwinds in reverse. */
    private function rollupSchedules(int $salesOrderLineId, float $qty): void
    {
        $schedules = DB::table('so_delivery_schedules')
            ->where('sales_order_line_id', $salesOrderLineId)
            ->orderBy($qty > 0 ? 'due_date' : 'due_date', $qty > 0 ? 'asc' : 'desc')
            ->lockForUpdate()
            ->get();

        $remaining = abs($qty);

        foreach ($schedules as $schedule) {
            if ($remaining <= 0.000001) {
                break;
            }

            if ($qty > 0) {
                $room = max(0, (float) $schedule->qty - (float) $schedule->delivered_qty);
                $take = min($room, $remaining);
            } else {
                $take = min((float) $schedule->delivered_qty, $remaining);
            }

            if ($take > 0) {
                DB::table('so_delivery_schedules')->where('id', $schedule->id)
                    ->increment('delivered_qty', $qty > 0 ? $take : -$take);
                $remaining -= $take;
            }
        }
    }

    /**
     * BR-42 — the certified *output* side of the reconciliation, written at shipment and
     * nowhere earlier. Claim comes from the dispatched lot itself; nothing is invented.
     */
    private function writeCocOutput(DeliveryChallan $challan, object $line, StockLot $lot): void
    {
        if ($lot->cert_scheme === null || (float) $lot->cert_claim_pct <= 0) {
            return;
        }

        DB::table('coc_transactions')->insert([
            'scheme' => $lot->cert_scheme,
            'direction' => 'output',
            'packing_list_id' => $challan->packing_list_id,
            'lot_id' => $lot->getKey(),
            'job_card_id' => $lot->job_card_id,
            'product_id' => $line->product_id,
            'uom_id' => $lot->uom_id,
            'qty' => round((float) $line->qty * (float) $lot->cert_claim_pct / 100, 6),
            'claim_pct' => $lot->cert_claim_pct,
            'period_year' => (int) $challan->challan_date->format('Y'),
            'period_month' => (int) $challan->challan_date->format('n'),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function lines(DeliveryChallan $challan): \Illuminate\Support\Collection
    {
        return DB::table('delivery_challan_lines')
            ->where('delivery_challan_id', $challan->getKey())
            ->orderBy('line_no')
            ->get();
    }
}
