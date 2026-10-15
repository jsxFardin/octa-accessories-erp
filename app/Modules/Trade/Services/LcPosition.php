<?php

declare(strict_types=1);

namespace App\Modules\Trade\Services;

use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * What an export credit has been drawn and paid against.
 *
 *   available = current amount × (1 + tolerance) − drawn
 *   drawn     = the invoices issued under the credit (a draft has drawn nothing yet)
 *   realised  = the receipts taken under it
 *
 * The bank honours documents up to the credit's tolerance and not a cent past it, so an invoice
 * that would take the credit beyond that is refused at issue: an invoice the bank will not pay
 * is not an invoice, it is a dispute.
 */
class LcPosition
{
    /** @return array{limit: float, drawn: float, realised: float, available: float, invoices: int} */
    public function for(LetterOfCredit $letter): array
    {
        $limit = $this->limit($letter);
        $drawn = $this->drawn($letter);

        return [
            'limit' => $limit,
            'drawn' => $drawn,
            'realised' => round((float) DB::table('receipts')->where('lc_id', $letter->id)->where('status', 'posted')->sum('amount'), 4),
            'available' => round($limit - $drawn, 4),
            'invoices' => (int) DB::table('sales_invoices')->where('lc_id', $letter->id)->whereNotIn('status', ['draft', 'cancelled'])->count(),
        ];
    }

    /** The face value after amendments, plus the tolerance the bank honours. */
    public function limit(LetterOfCredit $letter): float
    {
        return round($letter->currentAmount() * (1 + (float) $letter->tolerance_pct / 100), 4);
    }

    public function drawn(LetterOfCredit $letter, ?int $exceptInvoiceId = null): float
    {
        return round((float) DB::table('sales_invoices')
            ->where('lc_id', $letter->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->when($exceptInvoiceId !== null, fn ($q) => $q->where('id', '!=', $exceptInvoiceId))
            ->sum('total'), 4);
    }

    /**
     * Issuing an invoice under a credit draws on it; refused when the credit cannot take it.
     *
     * @throws TransitionDenied
     */
    public function assertCanDraw(SalesInvoice $invoice): void
    {
        if ($invoice->lc_id === null) {
            return;
        }

        /** @var LetterOfCredit|null $letter */
        $letter = LetterOfCredit::query()->lockForUpdate()->find($invoice->lc_id);

        if ($letter === null) {
            return;
        }

        if ((int) $letter->currency_id !== (int) $invoice->currency_id) {
            throw TransitionDenied::guard('BR-56', "Export LC {$letter->number} is in another currency; an invoice can only draw on a credit in its own currency.");
        }

        if (in_array($letter->status, [LetterOfCredit::CLOSED, LetterOfCredit::CANCELLED, LetterOfCredit::REALISED], true)) {
            throw TransitionDenied::guard('TR-E5', "Export LC {$letter->number} is ".str_replace('_', ' ', $letter->status).'; nothing more can be drawn on it.');
        }

        $limit = $this->limit($letter);
        $drawn = $this->drawn($letter, (int) $invoice->getKey());
        $after = $drawn + (float) $invoice->total;

        if ($after > $limit + 0.0001) {
            throw TransitionDenied::guard('TR-E5', sprintf(
                'Export LC %s can be drawn to %s (face value after amendments plus %s%% tolerance); %s is already drawn and this invoice of %s would take it to %s. Amend the credit, or invoice the excess outside it.',
                $letter->number,
                number_format($limit, 2),
                rtrim(rtrim(number_format((float) $letter->tolerance_pct, 2, '.', ''), '0'), '.'),
                number_format($drawn, 2),
                number_format((float) $invoice->total, 2),
                number_format($after, 2),
            ));
        }
    }
}
