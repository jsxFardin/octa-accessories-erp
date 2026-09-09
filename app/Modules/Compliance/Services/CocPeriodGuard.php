<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * C3 — a closed chain-of-custody period does not take new transactions.
 *
 * Locking the rows that exist is only half of a close. Without this, a receipt, an issue or a
 * dispatch back-dated into a closed month would write a fresh `coc_transactions` row with
 * `is_locked` defaulting to false, and the period an auditor was shown would quietly stop
 * matching the period the system holds. The lock has to be a boundary going forward, not a
 * flag on the rows that happened to exist when someone pressed the button.
 */
class CocPeriodGuard
{
    /**
     * @throws ValidationException when the period is closed
     */
    public function assertOpen(string $scheme, int $year, int $month): void
    {
        $closed = DB::table('coc_transactions')
            ->where('scheme', $scheme)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('is_locked', true)
            ->exists();

        if (! $closed) {
            return;
        }

        throw ValidationException::withMessages([
            'coc_period' => sprintf(
                'C3: the %s chain of custody for %04d-%02d is closed. Reopening a certified period is a compliance decision, not a data entry one — raise it with the compliance officer.',
                $scheme,
                $year,
                $month,
            ),
        ]);
    }

    /** The period a movement belongs to is the month it happened in. */
    public function assertOpenNow(string $scheme): void
    {
        $this->assertOpen($scheme, (int) now()->format('Y'), (int) now()->format('n'));
    }
}
