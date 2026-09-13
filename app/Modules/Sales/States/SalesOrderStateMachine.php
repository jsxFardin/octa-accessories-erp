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
            // `credit_hold` is reachable from `confirmed` because an *amendment* can breach the
            // limit after confirmation (BR-46). The order is real and the customer may genuinely
            // have increased it; what must stop is the order continuing to be executable on a
            // value nobody credit-checked.
            'confirmed' => ['in_production', 'partially_delivered', 'credit_hold', 'cancelled'],
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

        // BR-46 — a draft that breaches the credit limit does not become `confirmed`.
        //
        // The decision itself is taken in `SalesOrderController::transition()`, which diverts
        // such an order to `credit_hold` rather than refusing it — that is the intended
        // behaviour and it stays. What was missing is that the rule lived *only* there: every
        // other rule on this document (S3 above, BR-45, S2) is enforced inside the state
        // machine, so any other caller reaching `transition($order, 'confirmed')` — a bulk
        // action, an import, a future API — would have walked past the credit control without
        // touching it. There is no such caller today; this makes sure adding one cannot
        // quietly become a financial-control bypass.
        //
        // Only from `draft`: arriving from `credit_hold` *is* the release path, and it is
        // guarded on permission and reason immediately below.
        if ($from === 'draft' && $this->creditCheck($order)['on_hold']) {
            throw TransitionDenied::guard(
                'BR-46',
                'This order takes the customer past their credit limit. It must be held for Accounts or the Managing Director to release, not confirmed directly.',
            );
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
        // BR-46/BR-51 — the whole decision is made in the factory's own currency.
        //
        // `customers.credit_limit` is a base-currency figure, like every other commercial
        // guard rail on that row: BR-21's `min_order_value` is compared against the cost-sheet
        // subtotal, which BR-22 computes in the base currency. The customer's own
        // `currency_id` says what they are *traded* in, not what their limits are stated in.
        //
        // Both of the other operands were left in whatever currency their document happened to
        // be raised in. On this data most invoices and orders are USD, so:
        //
        // - `SUM(total - received_amount)` added dollars to taka at face value — the BR-50
        //   defect, in a financial control rather than a report; and
        // - a USD 10,000 order was measured against a taka limit as the number `10000`, an
        //   eighth of the BDT 1,225,000 it actually commits.
        //
        // Both understate exposure, so the failure is silent and always in the direction of
        // letting an order through. Each document converts at the rate it itself snapshotted
        // (BR-22) — never a live rate, which would restate the decision every time the screen
        // was opened.
        // P2-1 — the receivable is `total − received − applied credits`, which is what
        // `SalesInvoiceStateMachine::outstanding()` has always returned. This sum only ever
        // subtracted the money, so every invoice partly settled by a credit note was counted
        // here at more than it was owed. That was invisible while a credit could only reduce
        // the invoice it named — the overstatement and the credit moved together — and stops
        // being invisible the moment a credit can be applied somewhere else.
        $outstanding = (float) DB::table('sales_invoices as si')
            ->leftJoin(DB::raw('(SELECT sales_invoice_id, SUM(amount) AS applied
                                 FROM credit_note_applications GROUP BY sales_invoice_id) AS cna'),
                'cna.sales_invoice_id', '=', 'si.id')
            ->where('si.customer_id', $order->customer_id)
            ->whereIn('si.status', ['issued', 'partially_paid', 'overdue'])
            ->sum(DB::raw('(si.total - si.received_amount - COALESCE(cna.applied, 0)) * COALESCE(si.exchange_rate, 1)'));

        // Credit the customer holds and has not spent is the business owing them, so it nets
        // against what they owe. Without it a customer who has returned goods — the business
        // holding their money, having agreed to give it back — would be refused an order
        // against a limit they were, on balance, well inside.
        //
        // Spent credit is deliberately not counted: an applied note has already reduced an
        // invoice balance in the sum above, and a refunded one has left the bank. Either way
        // subtracting it here as well would relieve the same exposure twice.
        $credit = app(\App\Modules\Finance\Services\CreditNoteApplicationService::class)
            ->availableForCustomer((int) $order->customer_id);

        return $this->tolerance->creditCheck(
            round(max(0.0, $outstanding - $credit), 4),
            $this->baseValue($order),
            (float) $order->customer->credit_limit,
        );
    }

    /**
     * BR-51 — the order's value in the factory's own currency, which is the unit every credit
     * limit, approval band and settings threshold in this system is expressed in.
     *
     * The same shape as `PurchaseOrderStateMachine::baseValue()`, deliberately: one conversion
     * rule, stated the same way on both sides of the business.
     */
    public function baseValue(SalesOrder $order): float
    {
        $rate = (float) $order->exchange_rate;

        return round((float) $order->total * ($rate > 0 ? $rate : 1.0), 4);
    }

    /** The unit the credit decision is stated in, for a message a reader can act on. */
    public function baseCurrencyCode(): string
    {
        return (string) $this->settings->get('base_currency', 'BDT');
    }
}
