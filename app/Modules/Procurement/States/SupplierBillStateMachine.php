<?php

declare(strict_types=1);

namespace App\Modules\Procurement\States;

use App\Modules\Procurement\Models\SupplierBill;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * FN-4 — supplier bill lifecycle. Approval runs a three-way match (PO ↔ GRN ↔ Bill);
 * payment statuses are driven by payment allocation, never typed.
 *
 * @extends StateMachine<SupplierBill>
 */
class SupplierBillStateMachine extends StateMachine
{
    public function __construct(
        \App\Support\Audit\AuditLogger $audit,
        private readonly NumberAllocator $numbers,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            'draft' => ['approved', 'cancelled'],
            'approved' => ['partially_paid', 'paid'],
            'partially_paid' => ['paid'],
            'paid' => [],
            'cancelled' => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            'approved' => 'supplier_bill.post',
            'cancelled' => 'supplier_bill.post',
            'partially_paid' => 'payment.allocate',
            'paid' => 'payment.allocate',
        ];
    }

    /**
     * @param  SupplierBill  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'approved') {
            if ($document->lines()->doesntExist()) {
                throw TransitionDenied::guard('FN-4', 'A bill with no lines cannot be approved.');
            }

            $this->threeWayMatch($document, $context);
        }
    }

    /**
     * @param  SupplierBill  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to === 'approved' && $document->number === null) {
            $document->forceFill(['number' => $this->numbers->next('supplier_bill')])->save();
        }
    }

    /**
     * Three-way match: PO ↔ GRN ↔ Bill (06-procurement §three-way-match).
     *
     * @param  array<string, mixed>  $context
     */
    private function threeWayMatch(SupplierBill $bill, array $context): void
    {
        if ($bill->po_id === null || $bill->grn_id === null) {
            return;
        }

        $poLines = DB::table('purchase_order_lines')
            ->where('purchase_order_id', $bill->po_id)
            ->get()
            ->keyBy('item_id');

        $grnLines = DB::table('grn_lines')
            ->where('grn_id', $bill->grn_id)
            ->get()
            ->keyBy('item_id');

        $tolerancePct = (float) (DB::table('settings')->where('key', 'supplier_bill_rate_tolerance_pct')->value('value') ?? 2);

        $warnings = [];

        foreach ($bill->lines()->get() as $line) {
            if ($line->item_id === null) {
                continue;
            }

            $grnLine = $grnLines->get($line->item_id);

            if ($grnLine !== null && (float) $line->qty > (float) $grnLine->accepted_qty + 0.0001) {
                throw TransitionDenied::guard(
                    'FN-4',
                    sprintf('Billed qty for item #%d (%s) exceeds received qty (%s).', $line->item_id, $line->qty, $grnLine->accepted_qty),
                );
            }

            $poLine = $poLines->get($line->item_id);

            if ($poLine !== null) {
                $poRate = (float) $poLine->rate;
                $billRate = (float) $line->rate;

                if ($poRate > 0) {
                    $variance = abs($billRate - $poRate) / $poRate * 100;

                    if ($variance > $tolerancePct) {
                        $warnings[] = sprintf('Item #%d: billed rate %s vs PO rate %s (%.1f%% variance).', $line->item_id, $line->rate, $poLine->rate, $variance);
                    }
                }
            }
        }

        if ($warnings !== [] && empty($context['override'])) {
            $user = auth()->user();

            if ($user === null || ! $user->hasPermission('supplier_bill.approve_variance')) {
                throw TransitionDenied::guard(
                    'FN-4',
                    'Rate variance exceeds tolerance: '.implode(' ', $warnings).' — requires supplier_bill.approve_variance permission or override.',
                );
            }
        }
    }

    /**
     * Outstanding = total − paid_amount. Called by the payment controller.
     */
    public function outstanding(SupplierBill $bill): float
    {
        return round((float) $bill->total - (float) $bill->paid_amount, 4);
    }

    /**
     * Payment allocated; derive status and walk the machine.
     */
    public function reflectPayment(SupplierBill $bill): void
    {
        $paid = (float) $bill->paid_amount;

        $target = match (true) {
            $paid >= (float) $bill->total - 0.0001 => 'paid',
            $paid > 0 => 'partially_paid',
            default => null,
        };

        if ($target !== null && $bill->status !== $target) {
            $this->transition($bill, $target);
        }
    }
}
