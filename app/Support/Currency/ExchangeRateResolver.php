<?php

declare(strict_types=1);

namespace App\Support\Currency;

use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BR-58 — the one place that decides what rate a document is booked at.
 *
 * Every money document in this system snapshots a rate (BR-22) and every base-currency figure
 * derived from it — a BR-50 report total, a BR-51 approval band comparison, the "in the books"
 * line on a screen — is that amount multiplied by that rate. The rate was nevertheless a free
 * text field on ten forms, defaulted to `1` wherever a form did not send one, and hard-coded
 * to `1` on the RFQ → purchase-order path.
 *
 * A rate of `1` on a USD document is not a rounding problem. It is:
 *
 * - an authorisation bypass — at the reference rate a USD 5,000 order is BDT 612,500 and needs
 *   the Managing Director under BR-51; recorded at `1` it is the number `5000`, comfortably
 *   inside a purchase manager's own band; and
 * - a reporting error in the same direction everywhere — receivables, payables and stock
 *   valuation all understate the foreign document by the whole of the rate.
 *
 * The `exchange_rates` reference table already held the rates and nothing read it. This does.
 *
 * The rule:
 *
 * - a document in the **base currency** is booked at `1`, and nothing else is accepted;
 * - a document in **another currency** is booked at the reference rate effective on or before
 *   its own date — the snapshot, never today's rate re-read later;
 * - a submitted rate is accepted when it is within `exchange_rate_tolerance_pct` of that
 *   reference, because a contracted or bank rate legitimately differs from the card by a
 *   little and not by a factor of a hundred; and
 * - a currency with no rate on file cannot be used to raise a document at all, which is a
 *   better failure than booking it at parity.
 */
class ExchangeRateResolver
{
    public function __construct(private readonly Settings $settings) {}

    /** The factory's own currency, whose documents carry no conversion. */
    public function baseCurrencyId(): ?int
    {
        $code = (string) $this->settings->get('base_currency', 'BDT');

        $id = DB::table('currencies')->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function isBase(int $currencyId): bool
    {
        return $currencyId === $this->baseCurrencyId();
    }

    /**
     * The reference rate for a currency as at a date — the latest one effective on or before
     * it, so a document raised today is not restated by a rate published tomorrow.
     */
    public function reference(int $currencyId, ?string $onDate = null): ?float
    {
        if ($this->isBase($currencyId)) {
            return 1.0;
        }

        $rate = DB::table('exchange_rates')
            ->where('currency_id', $currencyId)
            ->where('effective_on', '<=', $onDate ?? now()->toDateString())
            ->orderByDesc('effective_on')
            ->orderByDesc('id')
            ->value('rate_to_base');

        // Nothing effective yet on that date: fall back to the earliest rate on file rather
        // than to parity. A document back-dated before the first published rate is a data-entry
        // choice, not a reason to value it at 1.
        $rate ??= DB::table('exchange_rates')
            ->where('currency_id', $currencyId)
            ->orderBy('effective_on')
            ->value('rate_to_base');

        return $rate === null ? null : (float) $rate;
    }

    /** How far a booked rate may sit from the reference before it has to be wrong. */
    public function tolerancePct(): float
    {
        return $this->settings->decimal('exchange_rate_tolerance_pct', 5.0);
    }

    /**
     * The rate to store on a document, given whatever the request supplied.
     *
     * @param  string  $field  the request field to attach a failure to
     *
     * @throws ValidationException
     */
    public function resolve(int $currencyId, float|string|null $submitted, ?string $onDate = null, string $field = 'exchange_rate'): float
    {
        $submitted = $submitted === null || $submitted === '' ? null : (float) $submitted;

        if ($this->isBase($currencyId)) {
            // A base-currency document converts to itself. A rate of anything else against it
            // is a mis-keyed form, and silently storing it would restate the document.
            if ($submitted !== null && abs($submitted - 1.0) > 0.000001) {
                throw ValidationException::withMessages([
                    $field => 'A document in the base currency is booked at a rate of 1.',
                ]);
            }

            return 1.0;
        }

        $reference = $this->reference($currencyId, $onDate);
        $code = (string) DB::table('currencies')->where('id', $currencyId)->value('code');

        if ($reference === null || $reference <= 0.0) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'No exchange rate is on file for %s. Record one under reference data before raising a document in that currency.',
                    $code !== '' ? $code : '#'.$currencyId,
                ),
            ]);
        }

        if ($submitted === null || $submitted <= 0.0) {
            return round($reference, 8);
        }

        $tolerance = $this->tolerancePct();
        $drift = abs($submitted - $reference) / $reference * 100;

        if ($drift > $tolerance) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'The rate for %s on file is %s. A booked rate of %s is %s%% away from it, beyond the %s%% tolerance — correct the rate, or publish the new one under reference data.',
                    $code !== '' ? $code : '#'.$currencyId,
                    number_format($reference, 6),
                    number_format($submitted, 6),
                    number_format($drift, 2),
                    number_format($tolerance, 2),
                ),
            ]);
        }

        return round($submitted, 8);
    }
}
