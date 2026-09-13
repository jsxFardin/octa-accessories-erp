<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Services;

use App\Modules\Compliance\Services\CocPeriodGuard;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\MasterData\Models\CustomerAddress;
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

        $this->guardConsignee($challan);
        $this->guardTolerance($lines, $context);
        $this->guardCertificate($challan);
    }

    /**
     * Re-resolve a draft challan's delivery address, for the case where there was none to
     * resolve when it was created.
     *
     * The same chain `DeliveryChallanController::store()` walks — packing list, then order,
     * then the customer's default — so a challan that is re-checked resolves to exactly what
     * a new one would. Only `draft`: an issued challan's address is the historical fact of
     * where the goods were sent, and nothing may rewrite it afterwards.
     */
    private function backfillDeliveryAddress(DeliveryChallan $challan): void
    {
        if ($challan->delivery_address_id !== null || $challan->status !== 'draft') {
            return;
        }

        $addressId = DB::table('packing_lists')
            ->where('id', $challan->packing_list_id)
            ->value('delivery_address_id');

        $addressId ??= $challan->sales_order_id === null
            ? null
            : DB::table('sales_orders')->where('id', $challan->sales_order_id)->value('delivery_address_id');

        $addressId ??= CustomerAddress::defaultDeliveryFor((int) $challan->customer_id)?->id;

        if ($addressId === null) {
            return;
        }

        $challan->forceFill(['delivery_address_id' => $addressId])->save();
    }

    /**
     * D4 — goods do not leave the gate without a destination on the paperwork.
     *
     * Every delivery note in the system read "Customer —" because the list never loaded the
     * relation, but underneath that there was a real hole: a challan could be issued with no
     * delivery address at all, and nothing checked that the buyer on the note was the buyer
     * on the order it fulfils. A driver, a gate guard and an auditor all read this document;
     * it has to say where the goods went and to whom.
     *
     * @throws TransitionDenied
     */
    public function guardConsignee(DeliveryChallan $challan, string $action = 'issued'): void
    {
        $blocked = [];

        // The address is resolved once, when the challan is created, and stored on the row.
        // That snapshot is right for an issued challan — it records where the goods actually
        // went, and must not drift when someone edits the customer months later — and wrong
        // for a draft that has not gone anywhere.
        //
        // A challan raised before the customer had a delivery address stored `null` and kept
        // it. Adding the address afterwards changed nothing, so the refusal below told the
        // dispatcher to "add a delivery address for the customer" and then refused again once
        // they had. The only way out was to cancel and start over — and a cancelled challan
        // used to hide the button that raises a new one, which stranded the packing list
        // completely. An instruction a document cannot act on is worse than no instruction.
        $this->backfillDeliveryAddress($challan);

        $customer = DB::table('customers')->where('id', $challan->customer_id)->first(['id', 'name']);

        if ($customer === null) {
            $blocked[] = 'This challan names no customer, so there is nobody to deliver it to.';
        }

        $order = $challan->sales_order_id === null
            ? null
            : DB::table('sales_orders')->where('id', $challan->sales_order_id)->first();

        if ($order !== null && (int) $order->customer_id !== (int) $challan->customer_id) {
            $blocked[] = sprintf(
                'The challan is addressed to a different customer from %s, the order it fulfils. A delivery note cannot re-address someone else\'s goods.',
                $order->number ?? "order #{$order->id}",
            );
        }

        $packingCustomer = DB::table('packing_lists')
            ->where('id', $challan->packing_list_id)
            ->value('customer_id');

        if ($packingCustomer !== null && (int) $packingCustomer !== (int) $challan->customer_id) {
            $blocked[] = 'The challan is addressed to a different customer from the packing list it carries.';
        }

        $address = DB::table('customer_addresses')
            ->where('id', $challan->delivery_address_id)
            ->first(['id', 'customer_id', 'label']);

        if ($address === null) {
            $orderName = $order !== null && $order->number !== null ? $order->number : 'the sales order';

            $blocked[] = sprintf(
                'This challan has no delivery address, so the driver has nowhere to take it. Set one on %s, or add a delivery address for %s.',
                $orderName,
                $customer === null ? 'the customer' : $customer->name,
            );
        } elseif ((int) $address->customer_id !== (int) $challan->customer_id) {
            $blocked[] = sprintf(
                'The delivery address "%s" belongs to another customer. Choose one of %s\'s own addresses.',
                $address->label,
                $customer === null ? 'this customer' : $customer->name,
            );
        }

        if ($blocked !== []) {
            throw TransitionDenied::guard(
                'D4',
                "This challan cannot be marked {$action}.\n• ".implode("\n• ", $blocked),
            );
        }
    }

    /**
     * D4 at the point of delivery.
     *
     * The rule was checked on `draft → issued` and nowhere else, so `issued → delivered` and
     * `in_transit → delivered` walked straight past it. Two challans in this database are
     * `delivered` with no delivery address at all — the document says the goods arrived
     * somewhere it cannot name.
     *
     * Re-checking here is not redundant: an address can be removed from the order after the
     * challan was issued, and a row can be inserted at `issued` without ever passing the
     * issue guard. Delivery is the last point at which the paperwork still means something.
     *
     * @throws TransitionDenied
     */
    public function validateDelivery(DeliveryChallan $challan): void
    {
        $this->guardConsignee($challan, 'delivered');
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

        $certificate = DB::table('certifications')
            ->where('scheme', $scheme)
            ->where('status', 'active')
            ->whereDate('issued_on', '<=', $challan->challan_date)
            ->whereDate('expires_on', '>=', $challan->challan_date)
            ->first(['certificate_no', 'document_path']);

        if ($certificate === null) {
            throw TransitionDenied::guard(
                'BR-43',
                "This shipment claims {$scheme}, but no active {$scheme} certificate is valid on the challan date. Ship without the claim or renew the certificate.",
            );
        }

        // Validity dates without the certificate behind them prove nothing. The registry
        // happily held rows reading "pending upload of the signed certificate" while shipments
        // claimed against them all year — and the signed PDF is the first thing an auditor
        // asks for.
        if (blank($certificate->document_path)) {
            throw TransitionDenied::guard(
                'BR-43',
                "Certificate {$certificate->certificate_no} has no document on file. Upload the signed certificate before shipping a {$scheme} claim.",
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

        // Gate 2 — goods that came back never left. A claim that stays on the output side
        // for a shipment sitting in the yard is exactly the overstatement the reconciliation
        // exists to catch.
        DB::table('coc_transactions')
            ->where('direction', 'output')
            ->where('packing_list_id', $challan->packing_list_id)
            ->delete();

        $this->draftReturnCreditNote($challan);
    }

    /**
     * P2-1 — a return AFTER invoicing needs a corrective document. The invoice stands
     * (invoiced_qty untouched); a draft credit note for the returned value is raised for
     * accounts to approve and apply. A return BEFORE invoicing needs nothing — a later
     * invoice bills only what stayed delivered.
     *
     * Duplicate protection is structural: `returned` is terminal in the challan graph, so
     * this runs at most once per challan.
     */
    private function draftReturnCreditNote(DeliveryChallan $challan): void
    {
        $invoice = DB::table('sales_invoices')
            ->where('delivery_challan_id', $challan->getKey())
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('number')   // issued at least once; drafts bill nothing yet
            ->first();

        if ($invoice === null) {
            return;
        }

        $amount = 0.0;

        foreach ($this->lines($challan) as $line) {
            $rate = (float) (DB::table('sales_order_lines')->where('id', $line->sales_order_line_id)->value('rate_per_m') ?? 0);
            // BR-1 — value from the per-1000 rate, same arithmetic the invoice line used.
            $amount += (float) $line->qty / 1000 * $rate;
        }

        if ($amount <= 0) {
            return;
        }

        \App\Modules\Finance\Models\CreditNote::query()->create([
            'customer_id' => $challan->customer_id,
            'sales_invoice_id' => $invoice->id,
            'note_date' => now()->toDateString(),
            'reason' => 'return',
            'currency_id' => $invoice->currency_id,
            'amount' => round($amount, 4),
            'status' => 'draft',
            'remarks' => "Auto-drafted: challan {$challan->number} returned.",
        ]);
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
     *
     * A mass balance only balances in one unit. Certified input is yarn in kilograms;
     * shipping labels in pieces put "180 in, 41,880 out" in front of an auditor and called it
     * a conversion factor. The shipped quantity is therefore expressed as the certified
     * *mass* behind it: this job's certified consumption, allocated by the share of the job's
     * output that is leaving. Ship everything and output equals consumption exactly.
     *
     * Without a consumption leg — a lot produced before this rule existed — there is no mass
     * to allocate, and the row falls back to the piece basis it always used.
     */
    private function writeCocOutput(DeliveryChallan $challan, object $line, StockLot $lot): void
    {
        if ($lot->cert_scheme === null || (float) $lot->cert_claim_pct <= 0) {
            return;
        }

        $shipped = (float) $line->qty;
        $basis = $this->certifiedMassBasis($lot, $shipped);

        // C3 — a closed period does not take new transactions.
        app(CocPeriodGuard::class)->assertOpenNow((string) $lot->cert_scheme);

        DB::table('coc_transactions')->insert([
            'scheme' => $lot->cert_scheme,
            'direction' => 'output',
            'packing_list_id' => $challan->packing_list_id,
            'lot_id' => $lot->getKey(),
            'job_card_id' => $lot->job_card_id,
            'product_id' => $line->product_id,
            'uom_id' => $basis['uom_id'] ?? $lot->uom_id,
            'qty' => $basis['qty'],
            'claim_pct' => $lot->cert_claim_pct,
            'period_year' => (int) $challan->challan_date->format('Y'),
            'period_month' => (int) $challan->challan_date->format('n'),
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    /**
     * The certified mass behind a shipped quantity, and the unit it is measured in.
     *
     * @return array{qty: float, uom_id: int|null}
     */
    private function certifiedMassBasis(StockLot $lot, float $shippedQty): array
    {
        $pieceBasis = ['qty' => round($shippedQty * (float) $lot->cert_claim_pct / 100, 6), 'uom_id' => $lot->uom_id];

        if ($lot->job_card_id === null) {
            return $pieceBasis;
        }

        $consumed = DB::table('coc_transactions')
            ->where('direction', 'conversion')
            ->where('scheme', $lot->cert_scheme)
            ->where('job_card_id', $lot->job_card_id)
            ->selectRaw('SUM(qty) as qty, MIN(uom_id) as uom_id')
            ->first();

        $produced = (float) DB::table('fg_receipts')
            ->where('job_card_id', $lot->job_card_id)
            ->where('status', 'posted')
            ->sum('qty');

        if ($consumed === null || (float) $consumed->qty <= 0 || $produced <= 0) {
            return $pieceBasis;
        }

        return [
            // Rounded down, like every other certified figure: a claim you cannot evidence is
            // worse than one that understates.
            'qty' => floor((float) $consumed->qty * $shippedQty / $produced * 1_000_000) / 1_000_000,
            'uom_id' => $consumed->uom_id !== null ? (int) $consumed->uom_id : $lot->uom_id,
        ];
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
