<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One report: a labelled query, a column list, optional totals. Read-only.
 */
abstract class ReportQuery
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function subtitle(): string;

    /**
     * @return list<array{key: string, label: string, align?: string, format?: string, total?: bool}>
     */
    abstract public function columns(): array;

    /**
     * FilterBar select fields. Date range (`from`/`to`) is prepended by `filters()`.
     *
     * @return list<array{key: string, label: string, type?: string, options?: list<array{value: mixed, label: string}>}>
     */
    public function filterFields(): array
    {
        return [];
    }

    /**
     * @return list<array{key: string, label: string, type?: string, options?: list<array{value: mixed, label: string}>}>
     */
    public function filters(): array
    {
        return array_merge([
            ['key' => 'from', 'label' => 'From', 'type' => 'date', 'options' => []],
            ['key' => 'to', 'label' => 'To', 'type' => 'date', 'options' => []],
        ], $this->filterFields());
    }

    /** Path prefix for the source document, e.g. `/sales-orders`. Null if the row is not a document. */
    public function documentPath(): ?string
    {
        return null;
    }

    /**
     * BR-50 — the row column holding the document's own currency code, when the report spans
     * more than one.
     *
     * A report over documents raised in different currencies has no single unit. Selecting
     * `total` and letting the screen label it with the factory's currency turned a USD 11.63
     * invoice into BDT 11.63, and `SUM(total)` added dollars to taka at face value — on this
     * data, receivables of 36,040 where the real exposure was 44,254.
     *
     * Returning a column here makes the report currency-aware: each row is labelled with the
     * currency it is actually in, and totals stop being a naive sum.
     */
    public function currencyColumn(): ?string
    {
        return null;
    }

    /**
     * BR-50 — the row column holding that document's rate to the factory's base currency.
     *
     * This is the rate **snapshotted on the document** (BR-22), not today's. Converting with a
     * rate the document itself records is a documented conversion; reaching for a live rate
     * would silently restate history every time the report was opened.
     */
    public function baseRateColumn(): ?string
    {
        return null;
    }

    abstract protected function base(Request $request): Builder;

    /**
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $perPage = min(100, max(10, (int) $request->query('per_page', '25')));

        return $this->base($request)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Column totals.
     *
     * BR-50 — on a currency-aware report the money totals are **converted to the factory's
     * base currency** at each document's own snapshotted rate, because that is the only
     * aggregate that means anything across a mixed set. Quantities are summed as they are:
     * pieces are pieces whatever the invoice was raised in.
     *
     * @return array<string, float|int>
     */
    public function totals(Request $request): array
    {
        [$money, $quantities] = $this->totalledColumns();

        if ($money === [] && $quantities === []) {
            return [];
        }

        $rate = $this->baseRateColumn();
        $convert = $this->currencyColumn() !== null && $rate !== null;

        $parts = [];

        foreach ($quantities as $key) {
            $parts[] = 'SUM(`'.$key.'`) as `'.$key.'`';
        }

        foreach ($money as $key) {
            $parts[] = $convert
                ? 'SUM(`'.$key.'` * `'.$rate.'`) as `'.$key.'`'
                : 'SUM(`'.$key.'`) as `'.$key.'`';
        }

        $inner = $this->base($request);
        $inner->reorder();

        $row = DB::query()->fromSub($inner, 'report_rows')->selectRaw(implode(', ', $parts))->first();

        $totals = [];

        foreach ([...$quantities, ...$money] as $key) {
            $totals[$key] = (float) ($row->{$key} ?? 0);
        }

        return $totals;
    }

    /**
     * What the totals row means, so the screen can say it rather than leave the reader to
     * assume.
     *
     * @return array<string, mixed>
     */
    public function totalsMeta(Request $request): array
    {
        $rate = $this->baseRateColumn();
        $currency = $this->currencyColumn();

        if ($currency === null || $rate === null) {
            return ['converted' => false, 'by_currency' => []];
        }

        [$money] = $this->totalledColumns();

        if ($money === []) {
            return ['converted' => false, 'by_currency' => []];
        }

        // The same figures before conversion, per currency, so the reader can see what the
        // converted total is made of instead of being asked to trust it.
        $inner = $this->base($request);
        $inner->reorder();

        $select = ['`'.$currency.'` as currency'];

        foreach ($money as $key) {
            $select[] = 'SUM(`'.$key.'`) as `'.$key.'`';
        }

        $rows = DB::query()->fromSub($inner, 'report_rows')
            ->selectRaw(implode(', ', $select))
            ->groupBy($currency)
            ->orderBy($currency)
            ->get();

        $byCurrency = [];

        foreach ($rows as $group) {
            $code = $group->currency;

            if ($code === null) {
                continue;
            }

            $amounts = [];

            foreach ($money as $key) {
                $amounts[$key] = (float) ($group->{$key} ?? 0);
            }

            $byCurrency[$code] = $amounts;
        }

        return [
            'converted' => count($byCurrency) > 0,
            // Only worth explaining when there is genuinely more than one currency in the set.
            'mixed' => count($byCurrency) > 1,
            'by_currency' => $byCurrency,
        ];
    }

    /**
     * The money and quantity columns that carry a total, kept apart because only one of them
     * needs converting.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function totalledColumns(): array
    {
        $money = [];
        $quantities = [];

        foreach ($this->columns() as $column) {
            if (($column['total'] ?? true) === false) {
                continue;
            }

            match ($column['format'] ?? null) {
                'money' => $money[] = $column['key'],
                'qty' => $quantities[] = $column['key'],
                default => null,
            };
        }

        return [$money, $quantities];
    }

    /**
     * Extra payload (reconciliation, movement summary). Empty for most reports.
     *
     * @return array<string, mixed>
     */
    public function extras(Request $request): array
    {
        return [];
    }

    protected function applyDate(Builder $query, Request $request, string $column): Builder
    {
        $from = $request->query('from');
        $to = $request->query('to');

        if (is_string($from) && $from !== '') {
            $query->whereDate($column, '>=', $from);
        }

        if (is_string($to) && $to !== '') {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    protected function applySearch(Builder $query, Request $request, string ...$columns): Builder
    {
        $term = $request->query('q');

        if (! is_string($term) || $term === '' || $columns === []) {
            return $query;
        }

        $query->where(function (Builder $inner) use ($term, $columns): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $inner->where($column, 'like', '%'.$term.'%')
                    : $inner->orWhere($column, 'like', '%'.$term.'%');
            }
        });

        return $query;
    }
}
