<?php

declare(strict_types=1);

namespace App\Modules\Sales\States;

use App\Modules\Product\Models\ArtworkVersion;
use App\Modules\Product\Models\ProductSpec;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Audit\AuditLogger;
use App\Support\Calculators\MrpCalculator;
use App\Support\Calculators\SalesToleranceCalculator;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §3.
 *
 * The confirmation guard is S3 — every line needs a `current` spec **and** an `approved`
 * artwork version. That is Gate 1 reaching back into the commercial process: an order that
 * cannot be produced should never be promised.
 *
 * @extends StateMachine<SalesOrder>
 */
class SalesOrderStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly SalesToleranceCalculator $tolerance,
        private readonly MrpCalculator $mrp,
        private readonly Settings $settings,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['confirmed', 'credit_hold', 'cancelled'],
            'credit_hold' => ['confirmed', 'cancelled'],
            'confirmed' => ['in_production', 'partially_delivered', 'cancelled'],
            'in_production' => ['partially_delivered', 'delivered'],
            'partially_delivered' => ['delivered', 'closed'],
            'delivered' => ['closed'],
            'closed' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'confirmed' => 'sales_order.confirm',
            'credit_hold' => 'sales_order.confirm',
            // Progress targets carry their own narrow permission (P0-4.3): fulfilment roles
            // advance an order's status without holding the right to edit its lines.
            'in_production' => 'sales_order.progress',
            'partially_delivered' => 'sales_order.progress',
            'delivered' => 'sales_order.progress',
            'closed' => 'sales_order.close',
            'cancelled' => 'sales_order.cancel',
        ];
    }

    /**
     * @param  SalesOrder  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'confirmed') {
            $this->guardConfirmation($document, $from, $context);
        }

        if ($to === 'closed' && $from === 'partially_delivered') {
            // BR-45 — a short close is legitimate, but it is a decision someone signs for.
            if (blank($context['close_reason'] ?? null)) {
                throw TransitionDenied::guard('BR-45', 'Short-closing an order requires a reason.');
            }

            if (! (auth()->user()?->hasPermission('sales_order.short_close') ?? false)) {
                throw TransitionDenied::notPermitted('sales_order.short_close');
            }
        }

        if ($to === 'cancelled') {
            $produced = (float) $document->lines()->sum('produced_qty');

            if ($produced > 0 && blank($context['close_reason'] ?? null)) {
                throw TransitionDenied::guard(
                    'S2',
                    'This order has production against it. Cancelling needs a documented reason.',
                );
            }
        }
    }

    /**
     * S3 + BR-46. Both checks report *which* line or how much over the limit — a merchandiser
     * needs to know what to chase, not that "confirmation failed".
     *
     * @param  array<string, mixed>  $context
     */
    private function guardConfirmation(SalesOrder $order, string $from, array $context): void
    {
        $blocked = [];

        foreach ($order->lines()->with(['product.artworks.versions', 'spec'])->get() as $line) {
            $specCurrent = $line->spec?->status === ProductSpec::CURRENT;

            $approved = ArtworkVersion::query()
                ->whereIn('artwork_id', $line->product->artworks()->select('id'))
                ->where('status', ArtworkVersion::APPROVED)
                ->exists();

            if (! $specCurrent) {
                $blocked[] = "Line {$line->line_no} ({$line->product->code}): the referenced spec is not the current version (P2).";
            }

            if (! $approved) {
                $blocked[] = "Line {$line->line_no} ({$line->product->code}): no approved artwork version (Gate 1 / A2).";
            }
        }

        if ($blocked !== []) {
            throw TransitionDenied::guard('S3', "This order is not ready to confirm.\n• ".implode("\n• ", $blocked));
        }

        // Releasing a credit hold is a separate permission from confirming (06-rbac §5).
        if ($from === 'credit_hold') {
            if (! (auth()->user()?->hasPermission('sales_order.release_credit_hold') ?? false)) {
                throw TransitionDenied::notPermitted('sales_order.release_credit_hold');
            }

            if (blank($context['release_reason'] ?? null)) {
                throw TransitionDenied::guard('BR-46', 'Releasing a credit hold requires a documented reason.');
            }
        }
    }

    /**
     * @param  SalesOrder  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'confirmed' => $this->onConfirmed($document),
            'credit_hold' => null,
            'closed' => $document->forceFill([
                'closed_at' => now(),
                'close_reason' => $context['close_reason'] ?? null,
            ])->save(),
            'cancelled' => $document->forceFill([
                'closed_at' => now(),
                'close_reason' => $context['close_reason'] ?? 'Cancelled',
            ])->save(),
            default => null,
        };
    }

    private function onConfirmed(SalesOrder $order): void
    {
        if ($order->number === null) {
            $order->forceFill(['number' => $this->numbers->next('sales_order')])->save();
        }

        $order->forceFill(['confirmed_at' => now()])->save();

        // BR-29 — every open order shows a system-computed ETA (goal G3). Computed here so
        // it exists from the moment the order is confirmed, not when someone opens a report.
        $qcDays = $this->settings->int('qc_days', 1);
        $packingDays = $this->settings->int('packing_days', 1);
        $transitDays = (int) DB::table('customer_addresses')
            ->where('id', $order->delivery_address_id)
            ->value('transit_days') ?: 0;

        foreach ($order->lines as $line) {
            if ($line->promised_date !== null) {
                continue;
            }

            $line->forceFill([
                'promised_date' => $this->mrp->promisedDate(
                    $order->delivery_date ?? now()->addWeeks(3),
                    $transitDays,
                    $qcDays,
                    $packingDays,
                ),
            ])->save();
        }
    }

    /**
     * BR-46 — the credit decision, made once at confirmation time.
     *
     * @return array{on_hold: bool, exposure: float, credit_limit: float, excess: float}
     */
    public function creditCheck(SalesOrder $order): array
    {
        $outstanding = (float) DB::table('sales_invoices')
            ->where('customer_id', $order->customer_id)
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->sum(DB::raw('total - received_amount'));

        return $this->tolerance->creditCheck(
            $outstanding,
            (float) $order->total,
            (float) $order->customer->credit_limit,
        );
    }
}
