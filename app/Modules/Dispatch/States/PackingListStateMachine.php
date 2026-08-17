<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\States;

use App\Modules\Dispatch\Models\PackingList;
use App\Support\Calculators\ClaimDilutionCalculator;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §10 — the packing list lifecycle.
 *
 * Packing is a *soft allocation*, never a stock movement: no ledger row is written here.
 * The `packed` guard is D1 — every lot in every carton must be finished goods, available,
 * and physically sufficient once every other undispatched packing list's claim on the same
 * lot is counted. The single physical exit is the challan's dispatch (P0-4.7).
 *
 * @extends StateMachine<PackingList>
 */
class PackingListStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly ClaimDilutionCalculator $coc,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['packed', 'cancelled'],
            'packed' => ['dispatched', 'cancelled'],
            'dispatched' => ['delivered'],
            'delivered' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'packed' => 'packing_list.pack',
            'cancelled' => 'packing_list.delete',
            // Driven by the challan's own transitions; the actor is whoever issues/delivers it.
            'dispatched' => 'delivery_challan.issue',
            'delivered' => 'delivery_challan.deliver',
        ];
    }

    /**
     * @param  PackingList  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'packed' => $this->guardPacked($document),
            'cancelled' => $this->guardCancelled($document),
            default => null,
        };
    }

    /** D1 + the allocation ceilings, all re-checked server-side under lot locks. */
    private function guardPacked(PackingList $packingList): void
    {
        $contents = $this->contents($packingList);

        if ($contents->isEmpty()) {
            throw TransitionDenied::guard('D1', 'A packing list needs at least one carton with contents.');
        }

        $byLot = $contents->groupBy('lot_id');

        // Deterministic ascending lot-id lock order — same discipline as every other stock path.
        $lots = DB::table('stock_lots')
            ->whereIn('id', $byLot->keys()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $blocked = [];

        foreach ($byLot as $lotId => $rows) {
            $lot = $lots[$lotId] ?? null;
            $needed = (float) $rows->sum('qty');

            if ($lot === null) {
                $blocked[] = "Lot #{$lotId} does not exist.";

                continue;
            }

            if ($lot->kind !== 'finished_goods' || $lot->status !== 'available') {
                // D1 — the block names why: quarantine, blocked, consumed, or not FG at all.
                $blocked[] = "Lot {$lot->lot_no} is {$lot->status} ".($lot->kind !== 'finished_goods' ? "({$lot->kind})" : '(final QC has not released it)').' — it cannot be packed.';

                continue;
            }

            foreach ($rows as $row) {
                if ((int) $row->product_id !== (int) $lot->product_id) {
                    $blocked[] = "Lot {$lot->lot_no} holds a different product than carton content row #{$row->id}.";
                }
            }

            // Physical ceiling: this list plus every other *packed, undispatched* list's claim
            // on the lot. Dispatched lists are excluded — their quantity already left balance_qty.
            $otherAllocations = (float) DB::table('carton_contents as cc')
                ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                ->join('packing_lists as pl', 'pl.id', '=', 'c.packing_list_id')
                ->where('cc.lot_id', $lotId)
                ->where('pl.id', '!=', $packingList->getKey())
                ->where('pl.status', 'packed')
                ->sum('cc.qty');

            if ($needed + $otherAllocations > (float) $lot->balance_qty + 0.000001) {
                $blocked[] = sprintf(
                    'Lot %s: %s wanted here plus %s already packed elsewhere exceeds the %s on hand.',
                    $lot->lot_no,
                    rtrim(rtrim(number_format($needed, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format($otherAllocations, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $lot->balance_qty, 6, '.', ''), '0'), '.'),
                );
            }
        }

        // Per order line: cumulative packing may not exceed the BR-44 upper band.
        foreach ($contents->whereNotNull('sales_order_line_id')->groupBy('sales_order_line_id') as $lineId => $rows) {
            $line = DB::table('sales_order_lines')->where('id', $lineId)->lockForUpdate()->first();

            if ($line === null) {
                continue;
            }

            $elsewhere = (float) DB::table('carton_contents as cc')
                ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
                ->join('packing_lists as pl', 'pl.id', '=', 'c.packing_list_id')
                ->where('cc.sales_order_line_id', $lineId)
                ->where('pl.id', '!=', $packingList->getKey())
                ->whereIn('pl.status', ['packed', 'dispatched', 'delivered'])
                ->sum('cc.qty');

            $ceiling = (float) $line->ordered_qty * (1 + (float) $line->over_tolerance_pct / 100);

            if ((float) $rows->sum('qty') + $elsewhere > $ceiling + 0.000001) {
                $blocked[] = sprintf(
                    'Order line %d: packing beyond the BR-44 band (max %s including %s%% over-tolerance).',
                    $line->line_no,
                    rtrim(rtrim(number_format($ceiling, 6, '.', ''), '0'), '.'),
                    rtrim(rtrim((string) $line->over_tolerance_pct, '0'), '.'),
                );
            }
        }

        if ($blocked !== []) {
            throw TransitionDenied::guard('D1', "This packing list cannot be confirmed.\n• ".implode("\n• ", $blocked));
        }
    }

    /** A list with a live challan is spoken for; cancel the challan first. */
    private function guardCancelled(PackingList $packingList): void
    {
        $liveChallan = DB::table('delivery_challans')
            ->where('packing_list_id', $packingList->getKey())
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if ($liveChallan) {
            throw TransitionDenied::guard('D3', 'A delivery challan exists against this packing list. Cancel it first.');
        }
    }

    /**
     * @param  PackingList  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to !== 'packed') {
            return;
        }

        if ($document->number === null) {
            $document->forceFill(['number' => $this->numbers->next('packing_list')])->save();
        }

        $contents = $this->contents($document);

        // AC4 — totals are computed, never typed.
        $weights = DB::table('cartons')->where('packing_list_id', $document->getKey())
            ->selectRaw('COALESCE(SUM(gross_weight_kg),0) as gross, COALESCE(SUM(net_weight_kg),0) as net, COUNT(*) as cartons')
            ->first();

        // BR-40 — the list's claim is the consumption-weighted claim of its member lots,
        // rounded down; mixed schemes cannot carry a single claim, so they carry none.
        $lots = DB::table('stock_lots')->whereIn('id', $contents->pluck('lot_id')->unique())->get()->keyBy('id');
        $schemes = $lots->pluck('cert_scheme')->filter()->unique();

        $claim = 0.0;

        if ($schemes->count() === 1) {
            $claim = $this->coc->dilutedClaimPct(
                $contents->map(fn ($row): array => [
                    'qty_consumed' => (float) $row->qty,
                    'claim_pct' => (float) ($lots[$row->lot_id]->cert_claim_pct ?? 0),
                ])->values()->all(),
            );
        }

        $document->forceFill([
            'total_cartons' => (int) $weights->cartons,
            'total_qty' => (float) $contents->sum('qty'),
            'gross_weight_kg' => (float) $weights->gross ?: null,
            'net_weight_kg' => (float) $weights->net ?: null,
            'cert_claim_scheme' => $claim > 0 ? $schemes->first() : null,
            'cert_claim_pct' => $claim,
        ])->save();
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    private function contents(PackingList $packingList): \Illuminate\Support\Collection
    {
        return DB::table('carton_contents as cc')
            ->join('cartons as c', 'c.id', '=', 'cc.carton_id')
            ->where('c.packing_list_id', $packingList->getKey())
            ->get(['cc.id', 'cc.carton_id', 'cc.sales_order_line_id', 'cc.product_id', 'cc.lot_id', 'cc.qty']);
    }
}
