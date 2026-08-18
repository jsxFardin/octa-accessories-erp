<?php

declare(strict_types=1);

namespace App\Modules\Inventory\States;

use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * IN-6 — physical count. Open and counting write no stock; posting is the approval effect
 * and the only step that calls StockPostingService.
 *
 * @extends StateMachine<PhysicalCount>
 */
class PhysicalCountStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly StockPostingService $posting,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            PhysicalCount::OPEN => [PhysicalCount::COUNTING, PhysicalCount::CANCELLED],
            PhysicalCount::COUNTING => [PhysicalCount::RECONCILED, PhysicalCount::CANCELLED],
            PhysicalCount::RECONCILED => [PhysicalCount::POSTED],
            PhysicalCount::POSTED => [],
            PhysicalCount::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            PhysicalCount::COUNTING => 'physical_count.update',
            PhysicalCount::RECONCILED => 'physical_count.update',
            PhysicalCount::POSTED => 'physical_count.approve',
            PhysicalCount::CANCELLED => 'physical_count.update',
        ];
    }

    /**
     * @param  PhysicalCount  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        /** @var PhysicalCount $locked */
        $locked = PhysicalCount::query()->lockForUpdate()->findOrFail($document->getKey());

        $current = (string) $locked->getAttribute($this->statusColumn());

        if ($current !== $from) {
            throw TransitionDenied::notAllowed('PhysicalCount', $current, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        match ($to) {
            PhysicalCount::COUNTING => $this->guardStartCounting($locked),
            PhysicalCount::RECONCILED => $this->guardReconciled($locked),
            PhysicalCount::POSTED => $this->guardPosted($locked),
            default => null,
        };
    }

    /**
     * @param  PhysicalCount  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            PhysicalCount::COUNTING => $this->onCounting($document),
            PhysicalCount::POSTED => $this->onPosted($document),
            PhysicalCount::CANCELLED => $this->onCancelled($document, $from),
            default => null,
        };
    }

    private function guardStartCounting(PhysicalCount $count): void
    {
        $warehouse = DB::table('warehouses')->where('id', $count->warehouse_id)->first();

        if ($warehouse === null || ! (bool) $warehouse->is_active) {
            throw TransitionDenied::guard('IN-6', 'The warehouse is not active.');
        }

        $this->assertNoOverlappingCount($count);
    }

    private function guardReconciled(PhysicalCount $count): void
    {
        $lines = PhysicalCountLine::query()
            ->where('physical_count_id', $count->getKey())
            ->get();

        foreach ($lines as $index => $line) {
            if ($line->counted_qty === null) {
                throw TransitionDenied::guard(
                    'IN-6',
                    sprintf('Line %d still needs a counted quantity.', $index + 1),
                );
            }

            if ((float) $line->counted_qty < -0.000001) {
                throw TransitionDenied::guard('IN-6', 'A counted quantity cannot be negative.');
            }

            $lot = StockLot::query()->find($line->lot_id);

            if ($lot === null || (int) $lot->warehouse_id !== (int) $count->warehouse_id) {
                throw TransitionDenied::guard('IN-6', 'A count line references a lot outside this warehouse.');
            }
        }
    }

    private function guardPosted(PhysicalCount $count): void
    {
        $lines = PhysicalCountLine::query()
            ->where('physical_count_id', $count->getKey())
            ->orderBy('lot_id')
            ->get();

        if ($lines->isEmpty()) {
            return;
        }

        $lots = $this->lockLots($lines->pluck('lot_id')->map(fn ($id): int => (int) $id)->all());

        foreach ($lines as $line) {
            /** @var StockLot $lot */
            $lot = $lots->get((int) $line->lot_id);
            $variance = (float) $line->counted_qty - (float) $line->system_qty;

            if (abs($variance) < 0.000001) {
                continue;
            }

            if ($variance < 0 && ((float) $lot->balance_qty + $variance) < -0.000001) {
                throw TransitionDenied::guard(
                    'IN-6 · BR-38',
                    sprintf(
                        'Lot %s only holds %s — a shortage of %s would drive it negative.',
                        $lot->lot_no,
                        $this->formatQty((float) $lot->balance_qty),
                        $this->formatQty(abs($variance)),
                    ),
                );
            }
        }
    }

    private function onCounting(PhysicalCount $count): void
    {
        $this->assertNoOverlappingCount($count);

        $lotIds = StockLot::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->where('status', 'available')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $lots = $this->lockLots($lotIds);

        foreach ($lotIds as $lotId) {
            /** @var StockLot $lot */
            $lot = $lots->get($lotId);

            if ((string) $lot->status !== 'available') {
                continue;
            }

            $line = new PhysicalCountLine;
            $line->forceFill([
                'physical_count_id' => $count->id,
                'lot_id' => $lot->id,
                'system_qty' => $lot->balance_qty,
                'counted_qty' => null,
            ])->save();

            $lot->forceFill(['status' => 'blocked'])->save();
        }

        if ($count->number === null) {
            $count->forceFill(['number' => $this->numbers->next('physical_count')])->save();
        }
    }

    private function onPosted(PhysicalCount $count): void
    {
        $lines = PhysicalCountLine::query()
            ->where('physical_count_id', $count->getKey())
            ->orderBy('lot_id')
            ->get();

        $frozenLotIds = $lines->pluck('lot_id')->map(fn ($id): int => (int) $id)->all();
        $lots = $this->lockLots($frozenLotIds);

        foreach ($lines as $line) {
            /** @var StockLot $lot */
            $lot = $lots->get((int) $line->lot_id);
            $variance = round((float) $line->counted_qty - (float) $line->system_qty, 6);

            if (abs($variance) < 0.000001) {
                continue;
            }

            $wasConsumed = (string) $lot->status === 'consumed';

            $this->posting->post(
                $lot,
                'count_variance',
                $variance,
                $count,
                null,
                $line->remarks,
            );

            $lot->refresh();

            if ($wasConsumed && (float) $lot->balance_qty > 0.000001 && (string) $lot->status === 'consumed') {
                $lot->forceFill(['status' => 'available'])->save();
            }
        }

        $this->unfreezeLots($frozenLotIds);
    }

    private function onCancelled(PhysicalCount $count, string $from): void
    {
        if ($from === PhysicalCount::OPEN) {
            return;
        }

        $lotIds = PhysicalCountLine::query()
            ->where('physical_count_id', $count->getKey())
            ->pluck('lot_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($lotIds === []) {
            return;
        }

        $this->unfreezeLots($lotIds);
    }

    /**
     * @param  list<int>  $lotIds
     * @return \Illuminate\Support\Collection<int, StockLot>
     */
    private function lockLots(array $lotIds): \Illuminate\Support\Collection
    {
        $unique = array_values(array_unique($lotIds));
        sort($unique, SORT_NUMERIC);

        $lots = collect();

        foreach ($unique as $lotId) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->whereKey($lotId)->lockForUpdate()->firstOrFail();
            $lots->put((int) $lot->id, $lot);
        }

        return $lots;
    }

    /** @param  list<int>  $lotIds */
    private function unfreezeLots(array $lotIds): void
    {
        $lots = $this->lockLots($lotIds);

        foreach ($lotIds as $lotId) {
            /** @var StockLot $lot */
            $lot = $lots->get($lotId);

            if ((string) $lot->status === 'blocked') {
                $lot->forceFill(['status' => 'available'])->save();
            }
        }
    }

    private function assertNoOverlappingCount(PhysicalCount $count): void
    {
        $exists = PhysicalCount::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->where('id', '!=', $count->getKey())
            ->whereIn('status', PhysicalCount::NON_TERMINAL)
            ->exists();

        if ($exists) {
            throw TransitionDenied::guard(
                'IN-6',
                'This warehouse already has an open physical count.',
            );
        }
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
