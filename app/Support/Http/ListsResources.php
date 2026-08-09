<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The list-screen pattern: search, filter, sort, paginate — written once so 150 index screens
 * behave identically (10-roadmap, Phase 0 "table/filter/pagination pattern").
 */
trait ListsResources
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $searchable  columns matched against the `q` parameter
     * @param  array<string, string>  $filters  request key => column
     * @param  list<string>  $sortable
     * @return Builder<TModel>
     */
    protected function applyListing(
        Builder $query,
        Request $request,
        array $searchable = [],
        array $filters = [],
        array $sortable = [],
        string $defaultSort = '-id',
    ): Builder {
        $term = trim((string) $request->string('q'));

        if ($term !== '' && $searchable !== []) {
            $query->where(function (Builder $sub) use ($searchable, $term): void {
                foreach ($searchable as $column) {
                    $sub->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        foreach ($filters as $key => $column) {
            $value = $request->query($key);

            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        $sort = (string) ($request->query('sort') ?: $defaultSort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        // An unlisted sort column is a typo or a probe; either way it falls back rather than
        // reaching the query builder.
        if (in_array($column, $sortable, true) || $column === 'id') {
            $query->orderBy($column, $direction);
        } else {
            $query->orderByDesc('id');
        }

        return $query;
    }

    /**
     * The filter state echoed back to the page, so the UI can render what is applied without
     * parsing the query string itself.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function listingFilters(Request $request, array $keys = []): array
    {
        return $request->only([...$keys, 'q', 'sort']);
    }

    protected function perPage(Request $request, int $default = 25): int
    {
        return min(200, max(10, (int) $request->query('per_page', (string) $default)));
    }
}
