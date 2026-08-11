<?php

declare(strict_types=1);

namespace App\Modules\Trade\Services;

use App\Modules\Trade\Models\ImportShipment;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BR-36, at the level the factory actually buys — the shipment.
 *
 * The GRN form already apportions freight, duty and clearing typed onto that one receipt.
 * That is the local case. The import case is different in three ways, and each of them is why
 * this class exists:
 *
 *  1. **The costs arrive after the goods.** The C&F agent bills three weeks later. A cost
 *     model that demands the duty figure at receipt time gets zeros typed into it.
 *  2. **One cost covers several receipts.** A container holds two POs and lands as two GRNs;
 *     the freight bill belongs to the container, not to either receipt.
 *  3. **It gets corrected.** Bills are revised. Allocation is therefore idempotent: running it
 *     again wipes the previous allocation rows and recomputes from the current costs, so the
 *     lot cost is always a function of what is recorded rather than of how many times somebody
 *     pressed the button.
 *
 * What it deliberately does *not* do is write to `stock_ledger`. That ledger is append-only
 * and records movements of quantity (I1); a revaluation moves no quantity. The audit of what
 * changed lives in `landed_cost_allocations` — cost by cost, line by line — and in the audit
 * log.
 */
class LandedCostAllocator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Spread a shipment's allocable costs over the lines it was received as.
     *
     * @param  string  $basis  'value' — a kilo of UK ink and a kilo of carton board do not
     *                         carry the same share of a duty bill; 'qty' where the cost really
     *                         is per unit, such as inland transport charged per drum.
     * @return array{lines: int, allocated: float, unallocated: float}
     */
    public function allocate(ImportShipment $shipment, string $basis = 'value'): array
    {
        if (! in_array($basis, ['value', 'qty'], true)) {
            throw new RuntimeException("Unknown allocation basis [{$basis}].");
        }

        return DB::transaction(function () use ($shipment, $basis): array {
            $lines = $this->receivedLines($shipment);
            $costs = $shipment->costs()->where('is_allocable', true)->get();

            $allocable = round((float) $costs->sum('base_amount'), 4);

            // Wiped first: a second run must not stack on top of the first.
            DB::table('landed_cost_allocations')->where('shipment_id', $shipment->id)->delete();

            if ($lines === [] || $costs->isEmpty()) {
                $this->resetToSupplierRate($lines);
                $this->refreshShipmentTotals($shipment, 0.0);

                return ['lines' => count($lines), 'allocated' => 0.0, 'unallocated' => $allocable];
            }

            $weights = $this->weights($lines, $basis);
            $totalWeight = array_sum($weights);

            if ($totalWeight <= 0) {
                $this->resetToSupplierRate($lines);
                $this->refreshShipmentTotals($shipment, 0.0);

                return ['lines' => count($lines), 'allocated' => 0.0, 'unallocated' => $allocable];
            }

            /** @var array<int, float> $addition grn_line_id => landed cost added */
            $addition = array_fill_keys(array_column($lines, 'id'), 0.0);
            $rows = [];
            $allocated = 0.0;

            foreach ($costs as $cost) {
                $amount = round((float) $cost->base_amount, 4);
                $running = 0.0;
                $last = count($lines) - 1;

                foreach ($lines as $index => $line) {
                    // The last line takes the remainder rather than its own rounded share, so
                    // the parts sum to the bill exactly. A 0.01 that vanishes here is a 0.01
                    // that never reconciles against the C&F invoice.
                    $share = $index === $last
                        ? round($amount - $running, 4)
                        : round($amount * ($weights[$index] / $totalWeight), 4);

                    $running += $share;
                    $addition[$line['id']] += $share;
                    $allocated += $share;

                    $rows[] = [
                        'shipment_id' => $shipment->id,
                        'import_cost_id' => $cost->id,
                        'grn_line_id' => $line['id'],
                        'stock_lot_id' => $line['lot_id'],
                        'basis' => $basis,
                        'basis_value' => $weights[$index],
                        'amount' => $share,
                        'allocated_at' => now(),
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('landed_cost_allocations')->insert($chunk);
            }

            $this->applyToLines($lines, $addition);
            $this->refreshShipmentTotals($shipment, round($allocated, 4));

            $this->audit->recordTable('import_shipments', $shipment->id, 'updated', null, [
                'action' => 'landed_cost_allocated',
                'basis' => $basis,
                'lines' => count($lines),
                'allocated' => round($allocated, 4),
            ]);

            return [
                'lines' => count($lines),
                'allocated' => round($allocated, 4),
                'unallocated' => round($allocable - $allocated, 4),
            ];
        });
    }

    /**
     * The GRN lines this shipment was received as, with the lot each one created.
     *
     * Cancelled receipts are excluded: a cancelled GRN's lines are not stock, and spreading
     * freight over them puts cost where there is nothing to carry it.
     *
     * @return list<array{id: int, lot_id: int|null, qty: float, rate: float, value: float}>
     */
    private function receivedLines(ImportShipment $shipment): array
    {
        $rows = DB::table('grn_lines as gl')
            ->join('grns as g', 'g.id', '=', 'gl.grn_id')
            ->leftJoin('stock_lots as sl', 'sl.grn_line_id', '=', 'gl.id')
            ->where('g.import_shipment_id', $shipment->id)
            ->where('g.status', '!=', 'cancelled')
            ->orderBy('gl.id')
            ->get(['gl.id', 'gl.received_qty', 'gl.rate', 'sl.id as lot_id']);

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'lot_id' => $row->lot_id === null ? null : (int) $row->lot_id,
            'qty' => (float) $row->received_qty,
            'rate' => (float) $row->rate,
            'value' => round((float) $row->received_qty * (float) $row->rate, 4),
        ])->all();
    }

    /**
     * @param  list<array{qty: float, value: float}>  $lines
     * @return list<float>
     */
    private function weights(array $lines, string $basis): array
    {
        return array_map(
            fn (array $line): float => $basis === 'qty' ? $line['qty'] : $line['value'],
            $lines,
        );
    }

    /**
     * Write the landed rate onto the GRN line and the lot it created.
     *
     * @param  list<array{id: int, lot_id: int|null, qty: float, rate: float}>  $lines
     * @param  array<int, float>  $addition
     */
    private function applyToLines(array $lines, array $addition): void
    {
        $items = [];

        foreach ($lines as $line) {
            $qty = $line['qty'];
            $landedRate = round($line['rate'] + ($qty > 0 ? ($addition[$line['id']] ?? 0) / $qty : 0), 4);

            DB::table('grn_lines')->where('id', $line['id'])->update(['landed_rate' => $landedRate]);

            if ($line['lot_id'] !== null) {
                DB::table('stock_lots')->where('id', $line['lot_id'])->update(['unit_cost' => $landedRate]);

                $itemId = DB::table('stock_lots')->where('id', $line['lot_id'])->value('item_id');

                if ($itemId !== null) {
                    $items[(int) $itemId] = true;
                }
            }
        }

        foreach (array_keys($items) as $itemId) {
            $this->refreshItemAverage($itemId);
        }
    }

    /**
     * Costs removed or a shipment with none: the line falls back to the supplier's rate rather
     * than keeping a landed rate that nothing supports.
     *
     * @param  list<array{id: int, lot_id: int|null, rate: float}>  $lines
     */
    private function resetToSupplierRate(array $lines): void
    {
        foreach ($lines as $line) {
            DB::table('grn_lines')->where('id', $line['id'])->update(['landed_rate' => $line['rate']]);

            if ($line['lot_id'] !== null) {
                DB::table('stock_lots')->where('id', $line['lot_id'])->update(['unit_cost' => $line['rate']]);
            }
        }
    }

    /**
     * BR-36 — the item's weighted average, recomputed from the lots that still hold stock.
     *
     * Recomputed rather than nudged: the incremental form assumes every movement passed
     * through it in order, which a revaluation weeks after the receipt did not.
     */
    private function refreshItemAverage(int $itemId): void
    {
        $row = DB::table('stock_lots')
            ->where('item_id', $itemId)
            ->where('balance_qty', '>', 0)
            ->selectRaw('SUM(balance_qty) as qty, SUM(balance_qty * unit_cost) as value')
            ->first();

        $qty = (float) ($row->qty ?? 0);

        if ($qty <= 0) {
            return;
        }

        DB::table('items')->where('id', $itemId)->update([
            'avg_rate' => round((float) $row->value / $qty, 6),
        ]);
    }

    private function refreshShipmentTotals(ImportShipment $shipment, float $allocated): void
    {
        $shipment->forceFill([
            'cost_total' => round((float) $shipment->costs()->sum('base_amount'), 4),
            'allocated_amount' => $allocated,
            // 'costed' is a state the shipment reaches, not one somebody sets: it means the
            // bills are in and the stock carries them.
            'status' => in_array($shipment->status, ['cleared', 'costed'], true) && $allocated > 0
                ? 'costed'
                : $shipment->status,
        ])->save();
    }
}
