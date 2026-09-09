<?php

declare(strict_types=1);

namespace App\Support\Scoping;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * The same unit predicate `BelongsToFactoryUnit` applies to models, for the queries that are
 * not models.
 *
 * The global scope closed the lists and the detail pages, because those go through Eloquent.
 * A large part of what a user actually reads does not: the dashboard tiles, the work queue,
 * and the reports are all built with `DB::table()` for the joins and aggregates they need, and
 * a global scope cannot reach any of them. Leaving them alone would have meant a job card
 * hidden from the list it belongs to and counted in the tile above it.
 *
 * The three holes are deliberately identical to the scope's, so the two cannot disagree about
 * what a user may see: no authenticated user, no filter; no employee row, no filter; a row
 * with no unit is visible to everyone.
 *
 * Not applied to guards and calculators. `JobCardPlanningGuard` asks how much of an order line
 * is already committed and has to count every unit's commitment to answer, or two units would
 * each plan the same quantity.
 */
class FactoryUnitFilter
{
    /**
     * @param  string  $column  qualified when the query joins more than one table
     * @param  User|null  $user  whose unit to filter by; the authenticated one when omitted
     *
     * Explicit over implicit where a caller knows: `WorkQueue::for(User $user)` builds one
     * person's queue and is not always building it for whoever is signed in — an admin
     * previewing another user's queue, or a digest sent on a schedule, would otherwise have
     * been filtered by the wrong unit while looking correct.
     */
    public function apply(Builder $query, string $column = 'factory_unit_id', ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if ($user === null) {
            return $query;
        }

        $unitId = $user->factoryUnitId();

        if ($unitId === null) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where($column, $unitId)
            ->orWhereNull($column));
    }

    /** True when the current user is scoped at all — for a screen that wants to say so. */
    public function isActive(): bool
    {
        return auth()->user()?->factoryUnitId() !== null;
    }
}
