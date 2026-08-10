<?php

declare(strict_types=1);

namespace App\Modules\Procurement\States;

use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * 05-workflows §7 — the requisition lifecycle.
 *
 * This was previously written inline in the controller, which was fine until a second caller
 * needed it: bulk approval would have meant a second copy of the "needs lines" and "needs the
 * approve permission" checks, and two copies of a rule are one rule and one bug waiting.
 *
 * @extends StateMachine<PurchaseRequisition>
 */
class PurchaseRequisitionStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['submitted', 'cancelled'],
            'submitted' => ['approved', 'rejected', 'draft', 'cancelled'],
            'approved' => ['converted', 'cancelled'],
            'rejected' => ['draft'],
            'converted' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'submitted' => 'purchase_requisition.submit',
            'approved' => 'purchase_requisition.approve',
            'rejected' => 'purchase_requisition.approve',
            'draft' => 'purchase_requisition.update',
            'converted' => 'purchase_order.create',
            'cancelled' => 'purchase_requisition.update',
        ];
    }

    /**
     * @param  PurchaseRequisition  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'submitted' && $document->lines()->doesntExist()) {
            throw TransitionDenied::guard('BR-24', 'A requisition with no lines cannot be submitted.');
        }
    }

    /**
     * @param  PurchaseRequisition  $document
     * @param  array<string, mixed>  $context
     */
    protected function prepare(Model $document, string $from, string $to, array $context): void
    {
        // BR-34 — numbered on the first transition out of draft, never on form open.
        if ($document->number === null && $to !== 'cancelled') {
            $document->forceFill(['number' => $this->numbers->next('purchase_requisition')])->save();
        }
    }

    /**
     * @param  PurchaseRequisition  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'approved') {
            $document->forceFill([
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ])->save();
        }

        if (isset($context['remarks'])) {
            $document->forceFill(['remarks' => $context['remarks']])->save();
        }
    }
}
