<?php

declare(strict_types=1);

namespace App\Modules\Trade\Services;

use App\Modules\Trade\Models\LetterOfCredit;
use Illuminate\Support\Facades\DB;

/**
 * Records an amendment on a credit of either direction — more money, a later date, and what
 * the bank charged for it. Appended, never merged: the credit's dates move too, because every
 * other screen reads them from the credit, but the row keeps the history of the move.
 */
class LcAmendmentService
{
    /**
     * @param  array{amended_on: string, amount_delta?: float|int|string|null, new_expiry_date?: string|null, new_last_shipment_date?: string|null, charges_amount?: float|int|string|null, narrative?: string|null}  $data
     */
    public function record(LetterOfCredit $letter, array $data, int $userId): void
    {
        DB::transaction(function () use ($letter, $data, $userId): void {
            $next = (int) DB::table('lc_amendments')->where('lc_id', $letter->id)->lockForUpdate()->max('amendment_no') + 1;

            DB::table('lc_amendments')->insert([
                'lc_id' => $letter->id,
                'amendment_no' => $next,
                'amended_on' => $data['amended_on'],
                'amount_delta' => $data['amount_delta'] ?? 0,
                'new_expiry_date' => $data['new_expiry_date'] ?? null,
                'new_last_shipment_date' => $data['new_last_shipment_date'] ?? null,
                'charges_amount' => $data['charges_amount'] ?? 0,
                'narrative' => $data['narrative'] ?? null,
                'created_at' => now(),
                'created_by' => $userId,
            ]);

            $letter->forceFill(array_filter([
                'expiry_date' => $data['new_expiry_date'] ?? null,
                'last_shipment_date' => $data['new_last_shipment_date'] ?? null,
            ]))->save();
        });
    }
}
