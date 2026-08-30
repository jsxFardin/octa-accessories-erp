<?php

declare(strict_types=1);

namespace App\Modules\Procurement\States;

use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\Settings\Settings;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §7 — the purchase order lifecycle.
 *
 * The interesting guard is approval by value band (06-rbac §5): up to the threshold the
 * purchase manager signs, above it the MD does. Both thresholds live in `settings`, so a
 * change is a data edit rather than a deploy.
 *
 * @extends StateMachine<PurchaseOrder>
 */
class PurchaseOrderStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly Settings $settings,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['pending_approval', 'cancelled'],
            'pending_approval' => ['approved', 'draft'],
            'approved' => ['sent', 'cancelled'],
            'sent' => ['partially_received', 'received', 'cancelled'],
            'partially_received' => ['received', 'closed'],
            'received' => ['closed'],
            'closed' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'pending_approval' => 'purchase_order.submit',
            'approved' => 'purchase_order.approve',
            'draft' => 'purchase_order.update',
            'sent' => 'purchase_order.send',
            'partially_received' => 'grn.post',
            'received' => 'grn.post',
            'closed' => 'purchase_order.close',
            'cancelled' => 'purchase_order.cancel',
        ];
    }

    /**
     * @param  PurchaseOrder  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            'pending_approval' => $this->guardSubmission($document),
            'approved' => $this->guardApproved($document, $context),
            default => null,
        };
    }

    /** A PO may not be submitted to a supplier nobody has approved (05-workflows §7). */
    private function guardSubmission(PurchaseOrder $order): void
    {
        if ($order->lines()->doesntExist()) {
            throw TransitionDenied::guard('BR-25', 'A purchase order with no lines cannot be submitted.');
        }

        $approved = (bool) DB::table('suppliers')->where('id', $order->supplier_id)->value('is_approved');

        if (! $approved) {
            throw TransitionDenied::guard(
                '05-workflows §7',
                'This supplier is not approved. Purchasing must approve them before an order is raised.',
            );
        }
    }

    /**
     * 06-rbac §5 — the approver's band has to cover the value. A purchase manager cannot sign
     * off a 400,000 BDT order simply because they hold `purchase_order.approve`.
     *
     * BR-51 — the band is a **base-currency** figure and the order's total is in whatever
     * currency the order was raised in, so the two were not comparable. A USD 1,000 order is
     * BDT 122,500 and sailed under a BDT 100,000 band as the number `1000`: a purchase manager
     * could approve, alone, an order that needed the Managing Director. The order is converted
     * at the rate it itself records (BR-22) before the comparison.
     */
    /**
     * The two things approval asks: has this order been competitively quoted (PR-2 AC4), and
     * is the person signing it allowed to sign for this much (06-rbac §5).
     *
     * @param  array<string, mixed>  $context
     */
    private function guardApproved(PurchaseOrder $order, array $context): void
    {
        $this->guardQuotations($order, $context);
        $this->guardApproval($order);
    }

    /**
     * PR-2 AC4 — above a value threshold an order needs at least three supplier quotations
     * before it is approved, or a documented override reason.
     *
     * The rule was enforced only where a *winning quotation was selected* on an RFQ, so it
     * governed one route into a purchase order and not the other. An order raised directly —
     * which the application fully supports and which needs no RFQ at all — reached approval
     * with nothing to compare against, and `guardApproval()` below asked only who was signing,
     * never whether anyone had shopped around. A control that the buyer can step around by
     * not using the RFQ screen is not a control.
     *
     * Counted from `supplier_quotations` against the RFQ the order records. No RFQ means no
     * quotations, which above the threshold is exactly the case the rule is about — and which
     * an override reason legitimately answers, because a sole-source or urgent replacement
     * order is a real thing that must stay possible.
     *
     * The threshold is a base-currency figure and the order is in whatever it was raised in,
     * so it converts first (BR-51) — the same trap this rule already fell into once on the RFQ
     * side, where a USD 600 quotation read as `600` against a BDT 50,000 control.
     *
     * @param  array<string, mixed>  $context
     */
    private function guardQuotations(PurchaseOrder $order, array $context): void
    {
        $threshold = $this->settings->decimal('rfq_three_quote_value_threshold', 50000);

        if ($this->baseValue($order) <= $threshold) {
            return;
        }

        $quotations = $order->rfq_id === null
            ? 0
            : (int) DB::table('supplier_quotations')->where('rfq_id', $order->rfq_id)->count();

        if ($quotations >= 3) {
            return;
        }

        $reason = trim((string) ($context['override_reason'] ?? ''));

        if ($reason !== '') {
            // Recorded on the order, not merely accepted: the next person to open it can see
            // why a competitive process was skipped. The audit row is written by the state
            // machine around this guard.
            $order->forceFill([
                'remarks' => trim(($order->remarks ? $order->remarks."\n" : '')
                    .'Three-quote override at approval: '.$reason),
            ])->save();

            return;
        }

        throw TransitionDenied::guard(
            'PR-2',
            sprintf(
                'This order is worth %s %s, above the %s %s threshold that requires three supplier quotations before approval. It has %s. Record the quotations against an RFQ, or approve with an override reason.',
                $this->baseCurrencyCode(),
                number_format($this->baseValue($order), 2),
                $this->baseCurrencyCode(),
                number_format($threshold, 2),
                $quotations === 0
                    ? 'none'
                    : ($quotations === 1 ? 'one' : 'two'),
            ),
        );
    }

    private function guardApproval(PurchaseOrder $order): void
    {
        $band = $this->settings->decimal('po_approval_band_manager', 100000);
        $value = $this->baseValue($order);

        if ($value <= $band) {
            return;
        }

        $user = auth()->user();

        // Above the band only the MD (or the implementer) signs.
        if ($user === null || ! $user->hasRole('md')) {
            throw TransitionDenied::guard(
                '06-rbac §5',
                sprintf(
                    'This order is worth %s %s, above the %s %s band a purchase manager may approve. It needs the Managing Director.',
                    $this->baseCurrencyCode(),
                    number_format($value, 2),
                    $this->baseCurrencyCode(),
                    number_format($band, 2),
                ),
            );
        }
    }

    /**
     * BR-51 — the order's value in the factory's own currency, which is the unit every
     * approval band, credit limit and settings threshold in this system is expressed in.
     */
    public function baseValue(PurchaseOrder $order): float
    {
        $rate = (float) $order->exchange_rate;

        return round((float) $order->total * ($rate > 0 ? $rate : 1.0), 4);
    }

    private function baseCurrencyCode(): string
    {
        return (string) $this->settings->get('base_currency', 'BDT');
    }

    /**
     * @param  PurchaseOrder  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        match ($to) {
            // BR-34 — the number is assigned on the first transition out of draft.
            'pending_approval' => $this->onSubmitted($document),
            'approved' => $document->forceFill([
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save(),
            default => null,
        };
    }

    private function onSubmitted(PurchaseOrder $order): void
    {
        if ($order->number === null) {
            $order->forceFill(['number' => $this->numbers->next('purchase_order')])->save();
        }
    }

    /**
     * Who this order needs, for the screen to say so before anyone presses submit.
     *
     * @return array{value: float, band: float, approver: string}
     */
    public function approvalBand(PurchaseOrder $order): array
    {
        $band = $this->settings->decimal('po_approval_band_manager', 100000);

        return [
            'value' => (float) $order->total,
            'band' => $band,
            'approver' => (float) $order->total > $band ? 'Managing Director' : 'Purchase manager',
        ];
    }
}
