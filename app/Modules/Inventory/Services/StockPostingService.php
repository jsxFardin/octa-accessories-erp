<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockLedgerEntry;
use App\Modules\Inventory\Models\StockLot;
use App\Support\Calculators\InventoryValuator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only legitimate way stock moves.
 *
 * I1 — `stock_ledger` is append-only. No UPDATE, no DELETE; a correction is a reversing
 * entry. I3 — the authoritative balance is the sum of the ledger; `stock_lots.balance_qty`
 * and the `stock_balances` table are derived caches, updated in the same transaction under a
 * row lock and rebuildable from `v_stock_balances` at any time.
 *
 * Every other module writes stock through here (08-architecture §1, boundary rule 1):
 * Manufacturing does not call `StockLedgerEntry::create()`, it calls `issueToJob()`.
 */
class StockPostingService
{
    public function __construct(private readonly InventoryValuator $valuator) {}

    /**
     * Post one movement.
     *
     * @param  float  $qty  signed: positive adds to the lot, negative removes from it
     *
     * @throws NegativeStockException when the movement would drive the lot below zero (BR-38)
     */
    public function post(
        StockLot $lot,
        string $movementType,
        float $qty,
        Model $source,
        ?float $unitCost = null,
        ?string $remarks = null,
        ?int $binId = null,
    ): StockLedgerEntry {
        if (abs($qty) < 0.000001) {
            throw new \InvalidArgumentException('A stock movement of zero is not a movement (stock_ledger_qty_chk).');
        }

        return DB::transaction(function () use ($lot, $movementType, $qty, $source, $unitCost, $remarks, $binId): StockLedgerEntry {
            // Lock the lot for the duration: two operators issuing the same roll at the same
            // moment must serialise here, or both see the same balance and both succeed.
            /** @var StockLot $locked */
            $locked = StockLot::query()->lockForUpdate()->findOrFail($lot->getKey());

            if ($qty < 0 && $this->valuator->wouldGoNegative((float) $locked->balance_qty, abs($qty))) {
                throw NegativeStockException::forLot(
                    $locked->lot_no,
                    (float) $locked->balance_qty,
                    abs($qty),
                );
            }

            $rate = $unitCost ?? (float) $locked->unit_cost;

            $entry = StockLedgerEntry::query()->create([
                'lot_id' => $locked->getKey(),
                'item_id' => $locked->item_id,
                'product_id' => $locked->product_id,
                'warehouse_id' => $locked->warehouse_id,
                'bin_id' => $binId ?? $locked->bin_id,
                'movement_type' => $movementType,
                'qty' => $qty,
                'uom_id' => $locked->uom_id,
                'unit_cost' => $rate,
                'value' => round(abs($qty) * $rate, 4),
                'source_type' => $source::class,
                'source_id' => $source->getKey(),
                'occurred_at' => now(),
                'created_by' => auth()->id(),
                'remarks' => $remarks,
            ]);

            $newBalance = (float) $locked->balance_qty + $qty;

            $locked->forceFill([
                'balance_qty' => $newBalance,
                // A lot drawn to zero is consumed, not merely empty — the distinction is what
                // keeps the FIFO suggestion from offering it again.
                'status' => $newBalance <= 0.000001 && $qty < 0 ? 'consumed' : $locked->status,
            ])->save();

            $this->refreshBalanceSummary($locked);

            return $entry;
        });
    }

    /**
     * BR-37 — issue material to a job card across suggested lots.
     *
     * @param  list<array{lot: StockLot, qty: float, fifo_override_reason?: string|null}>  $picks
     * @return list<StockLedgerEntry>
     */
    public function issueToJob(array $picks, Model $source): array
    {
        return DB::transaction(function () use ($picks, $source): array {
            $entries = [];

            foreach ($picks as $pick) {
                $entries[] = $this->post(
                    $pick['lot'],
                    'issue_to_job',
                    -abs($pick['qty']),
                    $source,
                    remarks: $pick['fifo_override_reason'] ?? null,
                );
            }

            return $entries;
        });
    }

    /**
     * BR-36 — receive against a GRN line: create the lot, post the receipt, move the
     * item's weighted average.
     *
     * @param  array<string, mixed>  $lotAttributes
     */
    public function receive(array $lotAttributes, float $qty, float $unitCost, Model $source): StockLot
    {
        return DB::transaction(function () use ($lotAttributes, $qty, $unitCost, $source): StockLot {
            /** @var StockLot $lot */
            $lot = StockLot::query()->create([
                ...$lotAttributes,
                'received_qty' => $qty,
                'balance_qty' => 0,       // the ledger posting below moves it, so it is never
                'unit_cost' => $unitCost, // written twice from two different sources of truth
            ]);

            $this->post($lot, 'grn_receipt', $qty, $source, $unitCost);

            $this->updateItemAverage($lot, $qty, $unitCost);

            return $lot->refresh();
        });
    }

    /**
     * I1 — corrections are reversing entries, never edits.
     */
    public function reverse(StockLedgerEntry $entry, string $reason): StockLedgerEntry
    {
        /** @var StockLot $lot */
        $lot = StockLot::query()->findOrFail($entry->lot_id);

        return $this->post(
            $lot,
            $entry->movement_type,
            -(float) $entry->qty,
            $entry,
            (float) $entry->unit_cost,
            "Reversal: {$reason}",
        );
    }

    /**
     * BR-36 — weighted average per item, recomputed on receipt.
     */
    private function updateItemAverage(StockLot $lot, float $receivedQty, float $receivedRate): void
    {
        if ($lot->item_id === null) {
            return;
        }

        $onHand = (float) StockLot::query()
            ->where('item_id', $lot->item_id)
            ->where('id', '!=', $lot->getKey())
            ->sum('balance_qty');

        $currentAverage = (float) DB::table('items')->where('id', $lot->item_id)->value('avg_rate');

        DB::table('items')->where('id', $lot->item_id)->update([
            'avg_rate' => $this->valuator->newAverage($onHand, $currentAverage, $receivedQty, $receivedRate),
        ]);
    }

    /**
     * `stock_balances` is a summary table, not a materialised view — MySQL has none
     * (02-database-schema §4). A nightly job compares it against `v_stock_balances`; a
     * difference means a posting path bypassed this service, and is raised as a bug rather
     * than silently corrected.
     */
    private function refreshBalanceSummary(StockLot $lot): void
    {
        DB::table('stock_balances')->updateOrInsert(
            ['lot_id' => $lot->getKey()],
            [
                'item_id' => $lot->item_id,
                'product_id' => $lot->product_id,
                'warehouse_id' => $lot->warehouse_id,
                'lot_no' => $lot->lot_no,
                'shade_code' => $lot->shade_code,
                'cert_scheme' => $lot->cert_scheme,
                'cert_claim_pct' => $lot->cert_claim_pct,
                'balance_qty' => $lot->balance_qty,
                'received_on' => $lot->received_on,
                'refreshed_at' => now(),
            ],
        );
    }
}
