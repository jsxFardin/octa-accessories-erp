<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Compliance\Services\CocPeriodGuard;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * Putting returned goods back into stock.
 *
 * Deliberately **not** `DispatchService::postReturn()`, which answers a different question.
 * That one reverses a consignment that never completed delivery: it walks every `dispatch`
 * ledger entry for the challan and reverses it whole, decrements `delivered_qty` because the
 * goods were never really delivered, and deletes the CoC output row outright. All three are
 * right for a refused consignment and wrong for a customer return — the goods here *were*
 * delivered, the delivery stands, and only part of it came back.
 *
 * So this posts forward rather than reversing: a fresh `sales_return` movement into the lot
 * the goods left on, at that lot's own cost, and a proportional reduction of the certified
 * output rather than its deletion.
 */
class SalesReturnPostingService
{
    public function __construct(
        private readonly StockPostingService $posting,
        private readonly CocPeriodGuard $periods,
    ) {}

    /**
     * SR-5 — a return line may only go back into stock against the lot it left on.
     *
     * Checked before anything posts, because half a return in the ledger is worse than none.
     * `lot_id` is nullable on the line so that paperwork can be raised before the goods
     * physically arrive and be inspected — but stock cannot be put back somewhere unnamed, and
     * the lot is also where the cost comes from. Returning at anything other than the original
     * lot's cost would revalue the inventory on the strength of a customer sending something
     * back, which is not a purchase and must not move an average.
     */
    public function assertPostable(SalesReturn $return): void
    {
        $dispatched = $this->dispatchedQuantities($return);

        foreach ($return->lines()->get() as $line) {
            if ($line->lot_id === null) {
                throw TransitionDenied::guard('SR-5', sprintf(
                    'Line %d does not say which lot it came back from. Stock cannot be returned to an unnamed lot, and the lot is where its cost comes from.',
                    (int) $line->line_no,
                ));
            }

            /** @var StockLot|null $lot */
            $lot = StockLot::query()->find($line->lot_id);

            if ($lot === null) {
                throw TransitionDenied::guard('SR-5', "Line {$line->line_no} names a lot that does not exist.");
            }

            if ($line->product_id !== null && (int) $lot->product_id !== (int) $line->product_id) {
                throw TransitionDenied::guard('SR-5', sprintf(
                    'Lot %s is not this line\'s product. Goods can only go back into the lot they were made and shipped on.',
                    $lot->lot_no,
                ));
            }

            // When the invoice names a challan, the lot has to be one that actually left on
            // it, and not for more than left. Without this a return could put stock into any
            // lot in the warehouse and inflate it using a customer's name.
            if ($dispatched !== null) {
                $shipped = $dispatched[(int) $line->lot_id] ?? 0.0;

                if ($shipped <= 0.000001) {
                    throw TransitionDenied::guard('SR-5', sprintf(
                        'Lot %s was never dispatched on the challan behind this invoice.',
                        $lot->lot_no,
                    ));
                }
            }
        }
    }

    /**
     * Post the stock. Runs inside the state machine's transaction, so a failure anywhere
     * takes the whole posting — and the status change that caused it — back with it.
     */
    public function post(SalesReturn $return): void
    {
        $dispatched = $this->dispatchedQuantities($return);

        foreach ($return->lines()->get() as $line) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($line->lot_id);

            // Unit cost is left null so the service takes the lot's own — the cost the goods
            // were carried at when they shipped. A return is not a receipt and must not touch
            // the weighted average.
            $this->posting->post(
                $lot,
                'sales_return',
                (float) $line->qty,
                $return,
                null,
                "Returned on {$return->reference()}",
            );

            $this->quarantine($lot);

            if ($dispatched !== null) {
                $this->reduceCocOutput($return, $line, $lot, $dispatched[(int) $line->lot_id] ?? 0.0);
            }
        }
    }

    /**
     * Customer-returned goods are not resaleable until somebody looks at them.
     *
     * The same convention `FgReceiptService` applies to output that has no accepted final
     * inspection behind it: the stock exists and is counted, but it is not `available`, so
     * BR-37's lot suggestion will not offer it to the next job or the next packing list. A lot
     * drawn to zero on dispatch was marked `consumed`; putting goods back into it without this
     * would leave a lot with a balance that no screen would ever propose.
     */
    private function quarantine(StockLot $lot): void
    {
        DB::table('stock_lots')
            ->where('id', $lot->getKey())
            ->update(['status' => 'quarantine']);
    }

    /**
     * BR-42 / Gate 2 — the certified output side, reduced by what came back.
     *
     * `DispatchService::postReturn()` deletes the whole output row, which is right when the
     * entire consignment is turned back and wrong here: shipping 10,000 certified labels and
     * having 2,000 returned leaves 8,000 legitimately shipped, and deleting the row would
     * understate the output side of the mass balance by all of it.
     *
     * The row is therefore scaled by the share of that lot's shipment that has come back.
     * `coc_qty_chk` requires `qty > 0`, so a return of everything removes the row rather than
     * zeroing it — at which point deleting and scaling agree.
     *
     * C3 — a locked row belongs to a closed certified period and is left exactly as it is.
     * Rewriting a closed period is a compliance decision, not a side effect of a goods receipt.
     */
    private function reduceCocOutput(SalesReturn $return, SalesReturnLine $line, StockLot $lot, float $shipped): void
    {
        if ($lot->cert_scheme === null || $shipped <= 0.000001) {
            return;
        }

        $packingListId = DB::table('delivery_challans')
            ->where('id', $return->delivery_challan_id ?? $this->challanId($return))
            ->value('packing_list_id');

        if ($packingListId === null) {
            return;
        }

        $row = DB::table('coc_transactions')
            ->where('direction', 'output')
            ->where('packing_list_id', $packingListId)
            ->where('lot_id', $lot->getKey())
            ->lockForUpdate()
            ->first();

        if ($row === null || (bool) $row->is_locked) {
            return;
        }

        $this->periods->assertOpen((string) $row->scheme, (int) $row->period_year, (int) $row->period_month);

        $remainingShare = max(0.0, ($shipped - (float) $line->qty) / $shipped);
        $newQty = round((float) $row->qty * $remainingShare, 6);

        if ($newQty <= 0.000001) {
            DB::table('coc_transactions')->where('id', $row->id)->delete();

            return;
        }

        DB::table('coc_transactions')->where('id', $row->id)->update(['qty' => $newQty]);
    }

    /**
     * What left on the challan behind this invoice, by lot, read from the ledger rather than
     * from the packing list — the ledger is what actually moved.
     *
     * Null when there is no challan to measure against: an invoice raised by hand has no
     * dispatch to check a return against, and inventing one would be worse than admitting it.
     *
     * @return array<int, float>|null
     */
    private function dispatchedQuantities(SalesReturn $return): ?array
    {
        $challanId = $return->delivery_challan_id ?? $this->challanId($return);

        if ($challanId === null) {
            return null;
        }

        $rows = DB::table('stock_ledger')
            ->where('source_type', \App\Modules\Dispatch\Models\DeliveryChallan::class)
            ->where('source_id', $challanId)
            ->where('movement_type', 'dispatch')
            ->groupBy('lot_id')
            ->selectRaw('lot_id, SUM(ABS(qty)) AS qty')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row->lot_id] = (float) $row->qty;
        }

        return $totals;
    }

    /** The challan the invoice was raised from, when the return does not name one itself. */
    private function challanId(SalesReturn $return): ?int
    {
        $id = DB::table('sales_invoices')
            ->where('id', $return->sales_invoice_id)
            ->value('delivery_challan_id');

        return $id === null ? null : (int) $id;
    }
}
