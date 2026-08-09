<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Manufacturing\Models\JobCard;
use App\Support\Calculators\MrpCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Reads availability. Nothing here is served from a TTL cache: a stock decision is made on
 * live numbers (08-architecture §7).
 */
class StockAvailability
{
    public function __construct(private readonly MrpCalculator $mrp) {}

    /**
     * BR-24 — on hand across **nettable** warehouses only. Scrap and transit stock exists but
     * cannot be planned against (02-database-schema §3.2).
     */
    public function onHand(int $itemId): float
    {
        return (float) DB::table('stock_balances as sb')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->where('sb.item_id', $itemId)
            ->where('w.is_nettable', true)
            ->sum('sb.balance_qty');
    }

    public function reserved(int $itemId, ?int $exceptJobCardId = null): float
    {
        return (float) DB::table('stock_reservations')
            ->where('item_id', $itemId)
            ->where('status', 'active')
            ->when($exceptJobCardId !== null, fn ($q) => $q->where('job_card_id', '!=', $exceptJobCardId))
            ->sum('qty');
    }

    public function onOrder(int $itemId): float
    {
        return (float) DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', 'po.id', '=', 'pol.po_id')
            ->where('pol.item_id', $itemId)
            ->whereIn('po.status', ['approved', 'sent', 'partially_received'])
            ->sum(DB::raw('GREATEST(pol.qty - pol.received_qty, 0)'));
    }

    /**
     * J1's material condition: the BOM exploded against live availability.
     *
     * @return list<array{item_id: int, item_code: string, item_name: string, required: float, available: float, on_order: float, short: float}>
     */
    public function shortagesFor(JobCard $jobCard): array
    {
        if ($jobCard->bom_id === null) {
            return [];
        }

        $lines = DB::table('bom_lines as bl')
            ->join('items as i', 'i.id', '=', 'bl.item_id')
            ->join('boms as b', 'b.id', '=', 'bl.bom_id')
            ->where('bl.bom_id', $jobCard->bom_id)
            ->select('bl.item_id', 'bl.qty_per_base', 'b.base_qty', 'i.code', 'i.name')
            ->get();

        $shortages = [];

        foreach ($lines as $line) {
            // BOM quantities are per `base_qty` finished pieces — 1000 by default, because
            // everything in this business is quoted and consumed per 1000 (BR-1).
            $required = (float) $line->qty_per_base * ((float) $jobCard->planned_qty / (float) $line->base_qty);

            $result = $this->mrp->netRequirement(
                grossReq: $required,
                onHandNettable: $this->onHand((int) $line->item_id),
                onOrder: $this->onOrder((int) $line->item_id),
                reserved: $this->reserved((int) $line->item_id, (int) $jobCard->getKey()),
            );

            if ($result['has_shortage']) {
                $shortages[] = [
                    'item_id' => (int) $line->item_id,
                    'item_code' => $line->code,
                    'item_name' => $line->name,
                    'required' => round($required, 6),
                    'available' => $result['available'],
                    'on_order' => $this->onOrder((int) $line->item_id),
                    'short' => $result['net_req'],
                ];
            }
        }

        return $shortages;
    }

    /**
     * BR-37 — candidate lots for an issue, shade-first for shade-critical items with a FIFO
     * fallback, filtered to the required certification claim.
     *
     * @return list<array{id: int, received_at: string, balance_qty: float, shade_code: ?string, claim_pct: float}>
     */
    public function candidateLots(int $itemId, ?int $warehouseId = null, ?float $requiredClaimPct = null): array
    {
        return DB::table('stock_lots')
            ->where('item_id', $itemId)
            ->where('status', 'available')
            ->where('balance_qty', '>', 0)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($requiredClaimPct !== null, fn ($q) => $q->where('cert_claim_pct', '>=', $requiredClaimPct))
            ->orderBy('received_on')
            ->get(['id', 'received_on', 'balance_qty', 'shade_code', 'cert_claim_pct'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'received_at' => (string) $row->received_on,
                'balance_qty' => (float) $row->balance_qty,
                'shade_code' => $row->shade_code,
                'claim_pct' => (float) $row->cert_claim_pct,
            ])
            ->all();
    }
}
