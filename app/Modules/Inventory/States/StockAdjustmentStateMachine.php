<?php

declare(strict_types=1);

namespace App\Modules\Inventory\States;

use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentLine;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Inventory\Services\StockPostingService;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * IN-5 — stock adjustment. Draft and submit write no stock; posting is the approval
 * effect and the only step that calls StockPostingService.
 *
 * @extends StateMachine<StockAdjustment>
 */
class StockAdjustmentStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly Settings $settings,
        private readonly StockPostingService $posting,
        private readonly ReservationService $reservations,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            StockAdjustment::DRAFT => [StockAdjustment::PENDING_APPROVAL, StockAdjustment::CANCELLED],
            StockAdjustment::PENDING_APPROVAL => [
                StockAdjustment::DRAFT,
                StockAdjustment::POSTED,
                StockAdjustment::CANCELLED,
            ],
            StockAdjustment::POSTED => [],
            StockAdjustment::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            StockAdjustment::PENDING_APPROVAL => 'stock_adjustment.update',
            StockAdjustment::DRAFT => 'stock_adjustment.update',
            StockAdjustment::POSTED => 'stock_adjustment.approve',
            StockAdjustment::CANCELLED => 'stock_adjustment.update',
        ];
    }

    /**
     * @param  StockAdjustment  $document
     */
    public function can(Model $document, string $to): bool
    {
        if (! parent::can($document, $to)) {
            return false;
        }

        if ($to !== StockAdjustment::POSTED) {
            return true;
        }

        $band = $this->approvalBand($document);

        if ($band['value'] <= $band['band']) {
            return true;
        }

        return auth()->user()?->hasRole('md') ?? false;
    }

    /**
     * @return array{value: float, band: float, approver: string}
     */
    public function approvalBand(StockAdjustment $document): array
    {
        $band = $this->settings->decimal('adjustment_approval_band_manager', 25000);
        $value = $this->documentValue($document);

        return [
            'value' => $value,
            'band' => $band,
            'approver' => $value > $band ? 'Managing Director' : 'Store manager',
        ];
    }

    /**
     * @param  StockAdjustment  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        /** @var StockAdjustment $locked */
        $locked = StockAdjustment::query()->lockForUpdate()->findOrFail($document->getKey());

        $current = (string) $locked->getAttribute($this->statusColumn());

        if ($current !== $from) {
            throw TransitionDenied::notAllowed('StockAdjustment', $current, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        if (in_array($to, [StockAdjustment::PENDING_APPROVAL, StockAdjustment::POSTED], true)) {
            $this->guardLines($locked, lockLots: $to === StockAdjustment::POSTED);
        }

        if ($to === StockAdjustment::POSTED) {
            $this->guardPosted($locked);
        }
    }

    /**
     * @param  StockAdjustment  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            StockAdjustment::PENDING_APPROVAL => $this->onSubmitted($document),
            StockAdjustment::POSTED => $this->onPosted($document),
            default => null,
        };
    }

    private function onSubmitted(StockAdjustment $adjustment): void
    {
        if ($adjustment->number === null) {
            $adjustment->forceFill(['number' => $this->numbers->next('stock_adjustment')])->save();
        }
    }

    private function onPosted(StockAdjustment $adjustment): void
    {
        $adjustment->forceFill(['approved_by' => auth()->id()])->save();

        $adjustment->load('lines');

        foreach ($adjustment->lines as $line) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($line->lot_id);
            $qty = (float) $line->qty_delta;
            $type = $qty > 0 ? 'adjustment_in' : 'adjustment_out';

            $this->posting->post(
                $lot,
                $type,
                $qty,
                $adjustment,
                null,
                $line->remarks,
            );
        }
    }

    private function guardPosted(StockAdjustment $adjustment): void
    {
        $band = $this->approvalBand($adjustment);

        if ($band['value'] <= $band['band']) {
            return;
        }

        $user = auth()->user();

        if ($user === null || ! $user->hasRole('md')) {
            throw TransitionDenied::guard(
                '06-rbac §5',
                sprintf(
                    'This adjustment is %s, above the %s band a store manager may approve. It needs the Managing Director.',
                    number_format($band['value'], 2),
                    number_format($band['band'], 2),
                ),
            );
        }
    }

    private function guardLines(StockAdjustment $adjustment, bool $lockLots): void
    {
        if (blank($adjustment->reason)) {
            throw TransitionDenied::guard('IN-5', 'A stock adjustment needs a reason.');
        }

        $lines = StockAdjustmentLine::query()
            ->where('stock_adjustment_id', $adjustment->getKey())
            ->orderBy('line_no')
            ->get();

        if ($lines->isEmpty()) {
            throw TransitionDenied::guard('IN-5', 'A stock adjustment with no lines cannot be submitted.');
        }

        $lotIds = $lines->pluck('lot_id')->unique()->sort()->values();

        $lots = collect();
        $running = [];
        $claimed = [];

        foreach ($lotIds as $lotId) {
            $query = StockLot::query()->whereKey($lotId);

            if ($lockLots) {
                $query->lockForUpdate();
            }

            $lot = $query->first();

            if ($lot === null) {
                throw TransitionDenied::guard('IN-5', "Lot #{$lotId} does not exist.");
            }

            $id = (int) $lot->id;
            $lots->put($id, $lot);
            $running[$id] = (float) $lot->balance_qty;
            $claimed[$id] = $lockLots ? $this->reservations->claimedByOthers($id) : 0.0;
        }

        foreach ($lines as $line) {
            $qty = (float) $line->qty_delta;
            $lotId = (int) $line->lot_id;

            if (abs($qty) < 0.000001) {
                throw TransitionDenied::guard('IN-5', 'An adjustment line of zero is not an adjustment.');
            }

            /** @var StockLot $lot */
            $lot = $lots->get($lotId);

            if ((int) $lot->warehouse_id !== (int) $adjustment->warehouse_id) {
                throw TransitionDenied::guard(
                    'IN-5',
                    "Lot {$lot->lot_no} is not in this adjustment's warehouse.",
                );
            }

            $this->assertLotStatus($lot, $qty);

            if ($lockLots && $qty < 0) {
                $free = $running[$lotId] - $claimed[$lotId];

                if (abs($qty) > $free + 0.000001) {
                    throw TransitionDenied::guard(
                        'IN-5 · BR-38',
                        sprintf(
                            'Lot %s has %s on hand but %s is reserved — only %s is free to write off.',
                            $lot->lot_no,
                            $this->formatQty($running[$lotId]),
                            $this->formatQty($claimed[$lotId]),
                            $this->formatQty(max(0, $free)),
                        ),
                    );
                }
            }

            $running[$lotId] += $qty;
        }
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }

    private function assertLotStatus(StockLot $lot, float $qty): void
    {
        $status = (string) $lot->status;

        if ($status === 'available') {
            return;
        }

        if ($status === 'blocked' && $qty < 0) {
            return;
        }

        throw TransitionDenied::guard(
            'IN-5',
            sprintf(
                'Lot %s is %s and cannot take this adjustment.',
                $lot->lot_no,
                $status,
            ),
        );
    }

    private function documentValue(StockAdjustment $adjustment): float
    {
        $rows = DB::table('stock_adjustment_lines as sal')
            ->join('stock_lots as sl', 'sl.id', '=', 'sal.lot_id')
            ->where('sal.stock_adjustment_id', $adjustment->getKey())
            ->selectRaw('sal.qty_delta, sl.unit_cost')
            ->get();

        $value = 0.0;

        foreach ($rows as $row) {
            $value += abs((float) $row->qty_delta) * (float) $row->unit_cost;
        }

        return round($value, 4);
    }
}
