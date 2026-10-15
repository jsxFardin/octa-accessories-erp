<?php

declare(strict_types=1);

namespace App\Modules\Procurement\States;

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseReturn;
use App\Modules\Procurement\Models\PurchaseReturnLine;
use App\Support\Audit\AuditLogger;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * PR-7 — a purchase return: draft, then posted or cancelled.
 *
 * Only from a posted receipt and only up to what each lot still holds (AC1): the quantity is
 * checked under the lot's row lock at posting, because the balance is what the ledger says
 * and two returns, or a return and an issue, can race for the same roll. Posting writes a
 * negative `purchase_return` movement at the lot's cost (AC2). `posted` is terminal: the
 * ledger is append-only and a mistake is an adjustment, not an unpost.
 *
 * @extends StateMachine<PurchaseReturn>
 */
class PurchaseReturnStateMachine extends StateMachine
{
    public function __construct(
        AuditLogger $audit,
        private readonly NumberAllocator $numbers,
        private readonly StockPostingService $stock,
    ) {
        parent::__construct($audit);
    }

    /** @return array<string, list<string>> */
    protected function transitions(): array
    {
        return [
            PurchaseReturn::DRAFT => [PurchaseReturn::POSTED, PurchaseReturn::CANCELLED],
            PurchaseReturn::POSTED => [],
            PurchaseReturn::CANCELLED => [],
        ];
    }

    /** @return array<string, string> */
    protected function permissions(): array
    {
        return [
            PurchaseReturn::POSTED => 'purchase_return.post',
            PurchaseReturn::CANCELLED => 'purchase_return.update',
        ];
    }

    /**
     * @param  PurchaseReturn  $document
     * @param  array<string, mixed>  $context
     */
    protected function guard(Model $document, string $from, string $to, array $context): void
    {
        /** @var PurchaseReturn $locked */
        $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($document->getKey());

        if ((string) $locked->status !== $from) {
            throw TransitionDenied::notAllowed('PurchaseReturn', (string) $locked->status, $to);
        }

        $document->setRawAttributes($locked->getAttributes());
        $document->exists = true;

        if ($to === PurchaseReturn::CANCELLED && blank($context['reason'] ?? null)) {
            throw TransitionDenied::guard('PR-7', 'Say why the return is cancelled.');
        }

        if ($to === PurchaseReturn::POSTED) {
            $this->guardReceipt($locked);
            $this->guardLines($locked);
        }
    }

    /** AC1 — only from a posted receipt, and the receipt has to be this supplier's. */
    private function guardReceipt(PurchaseReturn $return): void
    {
        $grn = $return->grn_id === null ? null : Grn::query()->find($return->grn_id);

        if ($grn === null) {
            throw TransitionDenied::guard('PR-7', 'A purchase return is raised against a goods receipt; this one names none.');
        }

        if ($grn->status !== 'posted') {
            throw TransitionDenied::guard('PR-7', "Goods receipt {$grn->number} is not posted, so nothing from it is in stock to send back.");
        }

        if ((int) $grn->supplier_id !== (int) $return->supplier_id) {
            throw TransitionDenied::guard('PR-7', "Goods receipt {$grn->number} came from a different supplier.");
        }
    }

    /**
     * AC1 — every line names a lot this receipt created, and asks for no more than the lot
     * still holds. Decided under the lots' row locks, in id order so two returns cannot deadlock.
     */
    private function guardLines(PurchaseReturn $return): void
    {
        $lines = PurchaseReturnLine::query()->where('purchase_return_id', $return->getKey())->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw TransitionDenied::guard('PR-7', 'A return with no lines sends nothing back.');
        }

        $lotIds = $lines->pluck('lot_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        $lots = StockLot::query()->whereIn('id', $lotIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $receiptLotIds = DB::table('stock_lots as sl')
            ->join('grn_lines as gl', 'gl.id', '=', 'sl.grn_line_id')
            ->where('gl.grn_id', $return->grn_id)
            ->pluck('sl.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        /** @var array<int, float> $claimed */
        $claimed = [];

        foreach ($lines as $line) {
            if ($line->lot_id === null) {
                throw TransitionDenied::guard('PR-7', "Line {$line->line_no} names no lot. A return goes back from the lot the goods were received into.");
            }

            if (! in_array((int) $line->lot_id, $receiptLotIds, true)) {
                throw TransitionDenied::guard('PR-7', "Line {$line->line_no} names a lot that did not come in on this receipt.");
            }

            /** @var StockLot|null $lot */
            $lot = $lots->get((int) $line->lot_id);

            if ($lot === null) {
                throw TransitionDenied::guard('PR-7', "Line {$line->line_no} names a lot that no longer exists.");
            }

            if ((int) $lot->item_id !== (int) $line->item_id) {
                throw TransitionDenied::guard('PR-7', "Line {$line->line_no} names lot {$lot->lot_no}, which holds a different item.");
            }

            $claimed[(int) $lot->id] = ($claimed[(int) $lot->id] ?? 0.0) + (float) $line->qty;

            if ($claimed[(int) $lot->id] > (float) $lot->balance_qty + 0.000001) {
                throw TransitionDenied::guard('PR-7', sprintf(
                    'Lot %s holds %s; this return asks to send back %s. Only what is still in stock can go back.',
                    $lot->lot_no,
                    $this->formatQty((float) $lot->balance_qty),
                    $this->formatQty($claimed[(int) $lot->id]),
                ));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function auditContext(array $context): array
    {
        return [
            ...parent::auditContext($context),
            ...array_intersect_key($context, array_flip(['debit_note_no'])),
        ];
    }

    /**
     * @param  PurchaseReturn  $document
     * @param  array<string, mixed>  $context
     */
    protected function effect(Model $document, string $from, string $to, array $context): void
    {
        if ($to !== PurchaseReturn::POSTED) {
            return;
        }

        // BR-34 — numbered when it becomes a fact, not when the form opens.
        $document->forceFill([
            'number' => $document->number ?? $this->numbers->next('purchase_return'),
            // AC3 — the debit note Accounts raise against the supplier, when it is already known.
            'debit_note_no' => filled($context['debit_note_no'] ?? null) ? $context['debit_note_no'] : $document->debit_note_no,
        ])->save();

        // AC2 — a negative movement per line, at the lot's own cost, inside the transition's
        // transaction: a lot that refuses leaves nothing half-returned.
        foreach ($document->lines()->get() as $line) {
            /** @var StockLot $lot */
            $lot = StockLot::query()->findOrFail($line->lot_id);

            $this->stock->post(
                $lot,
                'purchase_return',
                -(float) $line->qty,
                $document,
                null,
                "Returned to supplier on {$document->number}: {$document->reason}",
            );
        }
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 6, '.', ''), '0'), '.');
    }
}
