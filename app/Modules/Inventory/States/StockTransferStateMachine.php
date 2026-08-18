<?php

declare(strict_types=1);

namespace App\Modules\Inventory\States;

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\MasterData\Models\Warehouse;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * IN-4 — warehouse transfer. Draft writes no stock. Dispatch (`in_transit`) and receive
 * (`received`) are the only steps that call StockPostingService.
 *
 * Child lots are born at balance 0 and increased only through `post()`. They are not stored
 * on the line: they are resolved from parent_lot_id + ledger source + warehouse.
 *
 * @extends StateMachine<StockTransfer>
 */
class StockTransferStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly StockPostingService $posting,
        private readonly ReservationService $reservations,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            StockTransfer::DRAFT => [StockTransfer::IN_TRANSIT, StockTransfer::CANCELLED],
            StockTransfer::IN_TRANSIT => [StockTransfer::RECEIVED],
            StockTransfer::RECEIVED => [],
            StockTransfer::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            StockTransfer::IN_TRANSIT => 'stock_transfer.post',
            StockTransfer::RECEIVED => 'stock_transfer.post',
            StockTransfer::CANCELLED => 'stock_transfer.update',
        ];
    }

    /**
     * @param  StockTransfer  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        /** @var StockTransfer $locked */
        $locked = StockTransfer::query()->lockForUpdate()->findOrFail($document->getKey());

        $current = (string) $locked->getAttribute($this->statusColumn());

        if ($current !== $from) {
            throw TransitionDenied::notAllowed('StockTransfer', $current, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        if ($to === StockTransfer::IN_TRANSIT) {
            $this->guardDispatch($locked);
        }

        if ($to === StockTransfer::RECEIVED) {
            $this->guardReceive($locked, $context);
        }
    }

    /**
     * @param  StockTransfer  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            StockTransfer::IN_TRANSIT => $this->onDispatched($document),
            StockTransfer::RECEIVED => $this->onReceived($document),
            default => null,
        };
    }

    /**
     * Exactly one active, non-nettable transit warehouse. Fail closed otherwise.
     */
    public function transitWarehouse(): Warehouse
    {
        $rows = Warehouse::query()
            ->where('kind', 'transit')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($rows->count() !== 1) {
            throw TransitionDenied::guard(
                'IN-4',
                sprintf(
                    'Warehouse transfers need exactly one active transit warehouse; found %d.',
                    $rows->count(),
                ),
            );
        }

        /** @var Warehouse $warehouse */
        $warehouse = $rows->first();

        if ($warehouse->is_nettable) {
            throw TransitionDenied::guard(
                'IN-4',
                'The transit warehouse must be non-nettable so MRP does not double-count.',
            );
        }

        return $warehouse;
    }

    public function childLot(StockTransfer $transfer, int $sourceLotId, int $warehouseId): ?StockLot
    {
        $matches = $this->matchingChildren($transfer, $sourceLotId, $warehouseId);

        if ($matches->count() > 1) {
            throw TransitionDenied::guard(
                'IN-4',
                "Transfer {$transfer->number} has more than one child lot for source lot #{$sourceLotId}.",
            );
        }

        return $matches->first();
    }

    private function guardDispatch(StockTransfer $transfer): void
    {
        $this->assertWarehouses($transfer);
        $transit = $this->transitWarehouse();

        $lines = $this->lines($transfer);

        $this->assertUniqueSourceLots($lines);

        $lotIds = $lines->pluck('lot_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $lots = $this->lockLots($lotIds->all());
        $running = [];
        $claimed = [];

        foreach ($lotIds as $lotId) {
            $id = (int) $lotId;
            /** @var StockLot $lot */
            $lot = $lots->get($id);
            $running[$id] = (float) $lot->balance_qty;
            $claimed[$id] = $this->reservations->claimedByOthers($id);
        }

        foreach ($lines as $line) {
            $qty = (float) $line->qty;
            $lotId = (int) $line->lot_id;

            if ($qty <= 0.000001) {
                throw TransitionDenied::guard('IN-4', 'A transfer line of zero is not a transfer.');
            }

            /** @var StockLot $lot */
            $lot = $lots->get($lotId);

            if ((int) $lot->warehouse_id !== (int) $transfer->from_warehouse_id) {
                throw TransitionDenied::guard(
                    'IN-4',
                    "Lot {$lot->lot_no} is not in this transfer's source warehouse.",
                );
            }

            if ((int) $lot->warehouse_id === (int) $transit->id) {
                throw TransitionDenied::guard('IN-4', "Lot {$lot->lot_no} is already in transit.");
            }

            $this->assertSourceStatus($lot);

            $free = $running[$lotId] - $claimed[$lotId];

            if ($qty > $free + 0.000001) {
                throw TransitionDenied::guard(
                    'IN-4 · BR-38',
                    sprintf(
                        'Lot %s has %s on hand but %s is reserved — only %s is free to transfer.',
                        $lot->lot_no,
                        $this->formatQty($running[$lotId]),
                        $this->formatQty($claimed[$lotId]),
                        $this->formatQty(max(0, $free)),
                    ),
                );
            }

            $running[$lotId] -= $qty;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function guardReceive(StockTransfer $transfer, array $context): void
    {
        $this->assertWarehouses($transfer);
        $transit = $this->transitWarehouse();
        $lines = $this->lines($transfer);
        $this->assertUniqueSourceLots($lines);

        $childIdsBySource = [];

        foreach ($lines as $line) {
            $qty = (float) $line->qty;
            $requested = $this->requestedReceivedQty($line, $context);

            if (abs($requested - $qty) > 0.000001) {
                throw TransitionDenied::guard(
                    'IN-4',
                    sprintf(
                        'Short receipt is not allowed: line %d transferred %s and must be received in full.',
                        $line->line_no,
                        $this->formatQty($qty),
                    ),
                );
            }

            $child = $this->childLot($transfer, (int) $line->lot_id, (int) $transit->id);

            if ($child === null) {
                throw TransitionDenied::guard(
                    'IN-4',
                    sprintf('Transit stock for source lot #%d was not found.', (int) $line->lot_id),
                );
            }

            $childIdsBySource[(int) $line->lot_id] = (int) $child->id;
        }

        $locked = $this->lockLots(array_values(array_unique($childIdsBySource)));

        foreach ($lines as $line) {
            $qty = (float) $line->qty;
            $childId = $childIdsBySource[(int) $line->lot_id];
            /** @var StockLot $lot */
            $lot = $locked->get($childId);

            if ((int) $lot->warehouse_id !== (int) $transit->id) {
                throw TransitionDenied::guard('IN-4', "Lot {$lot->lot_no} is not in the transit warehouse.");
            }

            if ((float) $lot->balance_qty + 0.000001 < $qty) {
                throw TransitionDenied::guard(
                    'IN-4 · BR-38',
                    sprintf(
                        'Transit lot %s holds %s; this transfer needs %s.',
                        $lot->lot_no,
                        $this->formatQty((float) $lot->balance_qty),
                        $this->formatQty($qty),
                    ),
                );
            }
        }
    }

    private function onDispatched(StockTransfer $transfer): void
    {
        if ($transfer->number === null) {
            $transfer->forceFill(['number' => $this->numbers->next('stock_transfer')])->save();
        }

        $transit = $this->transitWarehouse();
        $lines = $this->lines($transfer);

        foreach ($lines as $line) {
            /** @var StockLot $source */
            $source = StockLot::query()->findOrFail($line->lot_id);
            $qty = (float) $line->qty;
            $child = $this->birthChild($source, (int) $transit->id, (int) $source->id);

            $this->posting->post($source, 'transfer_out', -$qty, $transfer, null, $transfer->remarks);
            $this->posting->post($child, 'transfer_in', $qty, $transfer, null, $transfer->remarks);
        }
    }

    private function onReceived(StockTransfer $transfer): void
    {
        $transit = $this->transitWarehouse();
        $lines = $this->lines($transfer);

        foreach ($lines as $line) {
            /** @var StockLot $source */
            $source = StockLot::query()->findOrFail($line->lot_id);
            $qty = (float) $line->qty;
            $transitLot = $this->childLot($transfer, (int) $source->id, (int) $transit->id);

            if ($transitLot === null) {
                throw TransitionDenied::guard(
                    'IN-4',
                    sprintf('Transit stock for source lot #%d was not found.', (int) $source->id),
                );
            }

            $destination = $this->birthChild($source, (int) $transfer->to_warehouse_id, (int) $source->id);

            $this->posting->post($transitLot, 'transfer_out', -$qty, $transfer, null, $transfer->remarks);
            $this->posting->post($destination, 'transfer_in', $qty, $transfer, null, $transfer->remarks);

            $line->forceFill(['received_qty' => $line->qty])->save();
        }
    }

    private function birthChild(StockLot $source, int $warehouseId, int $parentLotId): StockLot
    {
        return StockLot::query()->create([
            'lot_no' => $this->numbers->nextLotNumber(),
            'item_id' => $source->item_id,
            'product_id' => $source->product_id,
            'kind' => $source->kind,
            'warehouse_id' => $warehouseId,
            'bin_id' => null,
            'uom_id' => $source->uom_id,
            'received_qty' => 0,
            'balance_qty' => 0,
            'unit_cost' => $source->unit_cost,
            'parent_lot_id' => $parentLotId,
            'supplier_batch_no' => $source->supplier_batch_no,
            'shade_code' => $source->shade_code,
            'roll_length_m' => $source->roll_length_m,
            'received_on' => now()->toDateString(),
            'expiry_date' => $source->expiry_date,
            'cert_scheme' => $source->cert_scheme,
            'cert_claim_pct' => $source->cert_claim_pct,
            'cert_document_no' => $source->cert_document_no,
            'status' => 'available',
            'barcode' => null,
        ]);
    }

    /**
     * @return Collection<int, StockLot>
     */
    private function matchingChildren(StockTransfer $transfer, int $sourceLotId, int $warehouseId): Collection
    {
        $lotIds = DB::table('stock_ledger')
            ->where('source_type', StockTransfer::class)
            ->where('source_id', $transfer->getKey())
            ->where('warehouse_id', $warehouseId)
            ->where('movement_type', 'transfer_in')
            ->pluck('lot_id')
            ->unique()
            ->filter()
            ->values()
            ->all();

        if ($lotIds === []) {
            return collect();
        }

        return StockLot::query()
            ->whereIn('id', $lotIds)
            ->where('parent_lot_id', $sourceLotId)
            ->where('warehouse_id', $warehouseId)
            ->get();
    }

    /**
     * @param  list<int>  $lotIds
     * @return Collection<int, StockLot>
     */
    private function lockLots(array $lotIds): Collection
    {
        $lots = collect();
        sort($lotIds);

        foreach ($lotIds as $lotId) {
            $lot = StockLot::query()->whereKey($lotId)->lockForUpdate()->first();

            if ($lot === null) {
                throw TransitionDenied::guard('IN-4', "Lot #{$lotId} does not exist.");
            }

            $lots->put((int) $lot->id, $lot);
        }

        return $lots;
    }

    /** @return Collection<int, StockTransferLine> */
    private function lines(StockTransfer $transfer): Collection
    {
        $lines = StockTransferLine::query()
            ->where('stock_transfer_id', $transfer->getKey())
            ->orderBy('line_no')
            ->get();

        if ($lines->isEmpty()) {
            throw TransitionDenied::guard('IN-4', 'A transfer with no lines cannot be dispatched.');
        }

        return $lines;
    }

    /** @param  Collection<int, StockTransferLine>  $lines */
    private function assertUniqueSourceLots(Collection $lines): void
    {
        $ids = $lines->pluck('lot_id')->map(fn ($id): int => (int) $id);

        if ($ids->count() !== $ids->unique()->count()) {
            throw TransitionDenied::guard(
                'IN-4',
                'A source lot may appear only once on a transfer.',
            );
        }
    }

    private function assertWarehouses(StockTransfer $transfer): void
    {
        if ((int) $transfer->from_warehouse_id === (int) $transfer->to_warehouse_id) {
            throw TransitionDenied::guard('IN-4', 'Source and destination warehouses must be different.');
        }

        $from = Warehouse::query()->find($transfer->from_warehouse_id);
        $to = Warehouse::query()->find($transfer->to_warehouse_id);

        if ($from === null || $to === null) {
            throw TransitionDenied::guard('IN-4', 'Both warehouses must exist.');
        }

        if (! $from->is_active || ! $to->is_active) {
            throw TransitionDenied::guard('IN-4', 'Both warehouses must be active.');
        }

        if ($from->kind === 'transit' || $to->kind === 'transit') {
            throw TransitionDenied::guard(
                'IN-4',
                'The transit warehouse cannot be selected as the source or destination.',
            );
        }
    }

    private function assertSourceStatus(StockLot $lot): void
    {
        if ((string) $lot->status === 'available') {
            return;
        }

        throw TransitionDenied::guard(
            'IN-4',
            sprintf('Lot %s is %s and cannot be transferred.', $lot->lot_no, $lot->status),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function requestedReceivedQty(StockTransferLine $line, array $context): float
    {
        $lines = $context['lines'] ?? null;

        if (is_array($lines)) {
            foreach ($lines as $row) {
                if (! is_array($row)) {
                    continue;
                }

                if ((int) ($row['lot_id'] ?? 0) === (int) $line->lot_id && array_key_exists('received_qty', $row)) {
                    return (float) $row['received_qty'];
                }
            }
        }

        if (array_key_exists('received_qty', $context)) {
            return (float) $context['received_qty'];
        }

        return (float) $line->qty;
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
