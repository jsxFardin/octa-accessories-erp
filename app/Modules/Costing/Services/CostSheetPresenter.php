<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * BR-14/BR-47 — a stored cost sheet line, shaped so a reader can reconcile it.
 *
 * Two kinds of row live in `cost_sheet_lines` and they were rendered as one:
 *
 * - **Rate rows** (material, machine, labour, energy, tooling, packing) where the arithmetic
 *   is `qty × rate = amount`.
 * - **Percentage rows** (overhead, admin overhead, margin) where `qty` holds the percentage
 *   and `rate` holds the base it applies to. Printed under a "Qty × Rate" heading, `12.75`
 *   beside `20,000` beside `2,550` invites the reader to multiply and get 255,000.
 *
 * On top of that, machine and labour rows written before the calculator was corrected carry a
 * literal `0.000000` rate beside a real amount — the reported defect. Those sheets belong to
 * sent quotations and are snapshots (Q1): rewriting them would change what the customer was
 * quoted, so the stored row is left exactly as it is and the rate the job actually paid is
 * *derived* for display and labelled as derived. New sheets store the blended rate outright,
 * so the derivation is a bridge for history rather than a permanent second source of truth.
 */
class CostSheetPresenter
{
    /** Rows whose `qty` is a percentage and whose `rate` is the base it applies to. */
    private const PERCENTAGE_TYPES = ['overhead', 'admin_overhead', 'margin'];

    /**
     * One stored line, shaped for a screen or a printed sheet.
     *
     * @param  object|array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public function line(object|array $line): array
    {
        $row = $this->attributes($line);

        $costType = (string) ($row['cost_type'] ?? '');
        $qty = (float) ($row['qty'] ?? 0);
        $rate = (float) ($row['rate'] ?? 0);
        $amount = (float) ($row['amount'] ?? 0);

        $isPercentage = in_array($costType, self::PERCENTAGE_TYPES, true);

        // A rate that was never written, against an amount that plainly was. Recover it rather
        // than print a zero nobody can reconcile — and say that it was recovered.
        $derived = ! $isPercentage && $rate === 0.0 && $amount !== 0.0 && $qty > 0;

        // The other way a snapshotted row fails to foot: the rate is right and the *quantity*
        // is in the wrong unit. Energy rows written before the calculator booked kWh hold
        // machine hours under a `kWh` heading, so `hours × tariff` was never the amount beside
        // it. The amount was summed into the sheet total and the tariff is a rate card, so
        // between the three the quantity is the figure that cannot be trusted; it is recovered
        // as `amount ÷ rate` and said to be recovered.
        //
        // A sent quotation is a snapshot (Q1) and is never rewritten to make a screen foot.
        $qtyDerived = ! $isPercentage
            && ! $derived
            && $rate !== 0.0
            && $amount !== 0.0
            && abs($qty * $rate - $amount) > max(0.05, abs($amount) * 0.001);

        if ($qtyDerived) {
            $qty = round($amount / $rate, 6);
        }

        return [
            'sequence_no' => $row['sequence_no'] ?? null,
            'cost_type' => $costType,
            'description' => $row['description'] ?? null,
            'basis_uom' => $row['basis_uom'] ?? null,
            'qty' => $qty,
            'rate' => $derived ? round($amount / $qty, 6) : $rate,
            'amount' => $amount,
            'formula_ref' => $row['formula_ref'] ?? null,

            // How the row multiplies out, so the screen can lay it out honestly instead of
            // forcing a percentage into a Qty × Rate column.
            'basis' => $isPercentage ? 'percentage' : 'rate',

            // The base a percentage row applies to — `rate` holds it, which is why it must
            // never be printed as a rate.
            'percentage_of' => $isPercentage ? $rate : null,

            // True when the rate shown was recovered from amount ÷ qty rather than stored.
            'rate_is_derived' => $derived,

            // True when the quantity shown was recovered from amount ÷ rate because the stored
            // one was booked in a different unit from the one the row is labelled with.
            'qty_is_derived' => $qtyDerived,
        ];
    }

    /**
     * The row's own columns, whatever shape it arrived in.
     *
     * `(array)` on an Eloquent model does **not** return its attributes — it returns the
     * model's internal properties under null-byte-mangled keys (`\0*\0attributes` and the
     * rest), so every column lookup missed and every line rendered as a blank cost type with a
     * quantity and amount of zero. That is precisely how a screen full of real data came to
     * read as zeros while the database was untouched.
     *
     * Worse, it failed *quietly*: a row of zeros still satisfies `qty × rate = amount`, so a
     * check written to catch a mis-stated rate passed on data that had no values in it at all.
     *
     * @param  object|array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function attributes(object|array $line): array
    {
        if (is_array($line)) {
            return $line;
        }

        // A model knows how to hand over its own columns, casts applied.
        if ($line instanceof Model) {
            return $line->attributesToArray();
        }

        // A query-builder row is a plain stdClass and casts correctly.
        return (array) $line;
    }

    /**
     * @param  iterable<object|array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function lines(iterable $lines): array
    {
        $shaped = [];

        foreach ($lines as $line) {
            $shaped[] = $this->line($line);
        }

        return $shaped;
    }
}
