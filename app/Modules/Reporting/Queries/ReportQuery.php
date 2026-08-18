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
     * @return array<string, float|int>
     */
    public function totals(Request $request): array
    {
        $numeric = [];

        foreach ($this->columns() as $column) {
            $format = $column['format'] ?? null;
            $include = $column['total'] ?? true;

            if ($include && ($format === 'qty' || $format === 'money')) {
                $numeric[] = $column['key'];
            }
        }

        if ($numeric === []) {
            return [];
        }

        $select = implode(', ', array_map(
            fn (string $key): string => 'SUM(`'.$key.'`) as `'.$key.'`',
            $numeric,
        ));

        $inner = $this->base($request);
        $inner->reorder();

        $row = DB::query()->fromSub($inner, 'report_rows')->selectRaw($select)->first();

        $totals = [];

        foreach ($numeric as $key) {
            $totals[$key] = (float) ($row->{$key} ?? 0);
        }

        return $totals;
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
