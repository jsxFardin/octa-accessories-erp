<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\States;

use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Dispatch\Services\DispatchService;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\States\SalesOrderStateMachine;
use App\Support\Calculators\SalesToleranceCalculator;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §10 — the delivery challan lifecycle. `issued` is THE dispatch event:
 * guard revalidates everything under locks, effect posts the movements, moves
 * delivered_qty, writes CoC output and pulls the packing list and sales order along —
 * one transaction, courtesy of the base class.
 *
 * @extends StateMachine<DeliveryChallan>
 */
class DeliveryChallanStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly DispatchService $dispatch,
        private readonly NumberAllocator $numbers,
        private readonly PackingListStateMachine $packingLists,
        private readonly SalesOrderStateMachine $salesOrders,
        private readonly SalesToleranceCalculator $tolerance,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['issued', 'cancelled'],
            'issued' => ['in_transit', 'delivered', 'returned'],
            'in_transit' => ['delivered', 'returned'],
            'delivered' => [],
            'returned' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'issued' => 'delivery_challan.issue',
            'in_transit' => 'delivery_challan.update',
            'delivered' => 'delivery_challan.deliver',
            'returned' => 'delivery_challan.return',
            'cancelled' => 'delivery_challan.delete',
        ];
    }

    /**
     * @param  DeliveryChallan  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'issued' => $this->dispatch->validateIssue($document, $context),
            'returned' => $this->guardReturned($context),
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    private function guardReturned(array $context): void
    {
        if (blank($context['return_reason'] ?? null)) {
            throw TransitionDenied::guard('05-workflows §10', 'A returned delivery needs a documented failure reason.');
        }
    }

    /**
     * @param  DeliveryChallan  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'issued' => $this->onIssued($document),
            'delivered' => $this->onDelivered($document, $context),
            'returned' => $this->onReturned($document, $context),
            default => null,
        };
    }

    private function onIssued(DeliveryChallan $challan): void
    {
        if ($challan->number === null) {
            $challan->forceFill(['number' => $this->numbers->next('delivery_challan')])->save();
        }

        // The stock boundary: ledger + delivered_qty + schedules + CoC output.
        $this->dispatch->postIssue($challan);

        // The packing list follows its challan out of the gate.
        $packingList = PackingList::query()->findOrFail($challan->packing_list_id);
        $this->packingLists->transition($packingList, 'dispatched');

        // The order reflects fulfilment — through its own machine, never by column write.
        if ($challan->sales_order_id !== null) {
            $order = SalesOrder::query()->findOrFail($challan->sales_order_id);

            if (in_array($order->status, ['confirmed', 'in_production'], true)) {
                $this->salesOrders->transition($order, 'partially_delivered');
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function onDelivered(DeliveryChallan $challan, array $context): void
    {
        $challan->forceFill(['remarks' => trim(($challan->remarks ?? '')."\nPOD: ".($context['pod_ref'] ?? 'captured')) ?: null])->save();

        $packingList = PackingList::query()->findOrFail($challan->packing_list_id);

        if ($packingList->status === 'dispatched') {
            $this->packingLists->transition($packingList, 'delivered');
        }

        // BR-45 — a line auto-closes once cumulative delivery reaches the bottom of its band.
        $lineIds = DB::table('delivery_challan_lines')
            ->where('delivery_challan_id', $challan->getKey())
            ->whereNotNull('sales_order_line_id')
            ->pluck('sales_order_line_id')->unique();

        foreach (DB::table('sales_order_lines')->whereIn('id', $lineIds)->get() as $line) {
            if ($this->tolerance->isClosable((float) $line->delivered_qty, (float) $line->ordered_qty, (float) $line->under_tolerance_pct)) {
                // The line-status vocabulary closes on `completed` (sales_order_lines_status_chk).
                DB::table('sales_order_lines')->where('id', $line->id)->update(['status' => 'completed']);
            }
        }

        // Every line completed (or short-closed / cancelled) → the order itself is delivered.
        if ($challan->sales_order_id !== null) {
            $open = DB::table('sales_order_lines')
                ->where('sales_order_id', $challan->sales_order_id)
                ->whereNotIn('status', ['completed', 'short_closed', 'cancelled'])
                ->exists();

            $order = SalesOrder::query()->findOrFail($challan->sales_order_id);

            if (! $open && $order->status === 'partially_delivered') {
                $this->salesOrders->transition($order, 'delivered');
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function onReturned(DeliveryChallan $challan, array $context): void
    {
        $this->dispatch->postReturn($challan);

        $challan->forceFill([
            'remarks' => trim(($challan->remarks ?? '')."\nReturned: ".$context['return_reason']),
        ])->save();
    }
}
