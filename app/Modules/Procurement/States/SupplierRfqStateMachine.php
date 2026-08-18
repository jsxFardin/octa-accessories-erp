<?php

declare(strict_types=1);

namespace App\Modules\Procurement\States;

use App\Modules\Procurement\Models\SupplierRfq;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;

/**
 * PR-2 — RFQ lifecycle. Numbered on issue (BR-34). Closing happens after a winner is chosen.
 *
 * @extends StateMachine<SupplierRfq>
 */
class SupplierRfqStateMachine extends StateMachine
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
            SupplierRfq::DRAFT => [SupplierRfq::ISSUED, SupplierRfq::CANCELLED],
            SupplierRfq::ISSUED => [SupplierRfq::CLOSED, SupplierRfq::CANCELLED],
            SupplierRfq::CLOSED => [],
            SupplierRfq::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            SupplierRfq::ISSUED => 'rfq.send',
            SupplierRfq::CLOSED => 'rfq.update',
            SupplierRfq::CANCELLED => 'rfq.update',
        ];
    }

    /**
     * @param  SupplierRfq  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === SupplierRfq::ISSUED && $document->lines()->doesntExist()) {
            throw TransitionDenied::guard('PR-2', 'An RFQ with no lines cannot be issued.');
        }

        if ($to === SupplierRfq::CLOSED && $document->quotations()->where('is_selected', true)->doesntExist()) {
            throw TransitionDenied::guard('PR-2', 'Select a winning quotation before closing this RFQ.');
        }
    }

    /**
     * @param  SupplierRfq  $document
     * @param  array<string, mixed>  $context
     */
    protected function prepare(Model $document, string $from, string $to, array $context): void
    {
        if ($document->number === null && $to === SupplierRfq::ISSUED) {
            $document->forceFill(['number' => $this->numbers->next('rfq')])->save();
        }
    }
}
