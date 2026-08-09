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
            'approved' => $this->guardApproval($document),
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
     */
    private function guardApproval(PurchaseOrder $order): void
    {
        $band = $this->settings->decimal('po_approval_band_manager', 100000);
        $value = (float) $order->total;

        if ($value <= $band) {
            return;
        }

        $user = auth()->user();

        // Above the band only the MD (or the implementer) signs.
        if ($user === null || ! $user->hasRole('md')) {
            throw TransitionDenied::guard(
                '06-rbac §5',
                sprintf(
                    'This order is %s, above the %s band a purchase manager may approve. It needs the Managing Director.',
                    number_format($value, 2),
                    number_format($band, 2),
                ),
            );
        }
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
