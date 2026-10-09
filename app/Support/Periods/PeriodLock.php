<?php

declare(strict_types=1);

namespace App\Support\Periods;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A closed month takes no new entries.
 *
 * Every posting that puts a dated figure into the books passes through here first: stock
 * movements, invoices, credit notes, supplier bills, receipts, payments, refunds and
 * expenses. The month is the one on the document; a receipt dated into last month after
 * last month was closed is refused, whatever today is.
 *
 * The refusal is a validation error on `period`, the same shape every form already shows,
 * so a back-dated document fails on the date field rather than in a toast.
 */
class PeriodLock
{
    /** @var array<string, bool> closed months seen this request, keyed "YYYY-MM" */
    private array $closed = [];

    public function __construct(private readonly AuditLogger $audit) {}

    public function isClosed(int $year, int $month): bool
    {
        $key = sprintf('%04d-%02d', $year, $month);

        return $this->closed[$key] ??= DB::table('accounting_periods')
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('status', 'closed')
            ->exists();
    }

    /**
     * @param  \DateTimeInterface|string|null  $date  the document's date; null means today
     * @param  string  $what  "a goods receipt", "an invoice" — the thing being refused
     *
     * @throws ValidationException when the month is closed
     */
    public function assertOpen(\DateTimeInterface|string|null $date, string $what): void
    {
        $when = $date === null ? CarbonImmutable::now() : CarbonImmutable::parse($date);

        if (! $this->isClosed((int) $when->format('Y'), (int) $when->format('n'))) {
            return;
        }

        throw ValidationException::withMessages([
            'period' => sprintf(
                'The accounting period %s is closed, so %s cannot be dated into it. Date it in an open month, or ask accounts to reopen %s.',
                $when->format('F Y'),
                $what,
                $when->format('F Y'),
            ),
        ]);
    }

    /** @throws ValidationException when the month is in the future or already closed */
    public function close(int $year, int $month, User $by, ?string $note = null): void
    {
        $this->assertMonth($year, $month);

        if ($this->isClosed($year, $month)) {
            throw ValidationException::withMessages(['period' => sprintf('%04d-%02d is already closed.', $year, $month)]);
        }

        if (CarbonImmutable::create($year, $month, 1)->startOfMonth()->greaterThan(CarbonImmutable::now()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'A month that has not started cannot be closed.']);
        }

        DB::table('accounting_periods')->updateOrInsert(
            ['period_year' => $year, 'period_month' => $month],
            ['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id, 'note' => $note],
        );

        $row = DB::table('accounting_periods')->where('period_year', $year)->where('period_month', $month)->first();
        $this->audit->recordTable('accounting_periods', (int) $row->id, 'status_changed', ['status' => 'open'], ['status' => 'closed', 'period' => sprintf('%04d-%02d', $year, $month), 'note' => $note]);
        $this->closed = [];
    }

    /** @throws ValidationException when the month is not closed */
    public function reopen(int $year, int $month, User $by, ?string $note = null): void
    {
        $this->assertMonth($year, $month);

        if (! $this->isClosed($year, $month)) {
            throw ValidationException::withMessages(['period' => sprintf('%04d-%02d is not closed.', $year, $month)]);
        }

        DB::table('accounting_periods')
            ->where('period_year', $year)->where('period_month', $month)
            ->update(['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $by->id, 'note' => $note]);

        $row = DB::table('accounting_periods')->where('period_year', $year)->where('period_month', $month)->first();
        $this->audit->recordTable('accounting_periods', (int) $row->id, 'status_changed', ['status' => 'closed'], ['status' => 'open', 'period' => sprintf('%04d-%02d', $year, $month), 'note' => $note]);
        $this->closed = [];
    }

    private function assertMonth(int $year, int $month): void
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw ValidationException::withMessages(['period' => 'That is not a month.']);
        }
    }
}
