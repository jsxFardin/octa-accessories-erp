<?php

declare(strict_types=1);

namespace App\Support\Scoping;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * AD-4 — `factory_unit_id` on every operational table, so a second production unit works from
 * day one without multi-tenancy.
 *
 * That was a schema promise the application did not keep. `User::factoryUnitId()` had exactly
 * two callers: itself, and `HandleInertiaRequests`, which shipped the value to the frontend
 * where nothing read it. The shop-floor terminal scoped properly — `FloorQueueController`
 * filters the queue, and `assertWithinUnit()` guards the four write endpoints — and every desk
 * list did not. Job cards, sales orders, purchase orders, requisitions, expenses and MRP runs
 * were all read across every unit by everyone.
 *
 * Invisible today because Maheen runs one unit and every seeded employee belongs to it, which
 * is exactly why it went unnoticed: the bug has no symptom until the day it has a serious one.
 *
 * Three deliberate holes:
 *
 *  - **No user, no scope.** Console commands, queued jobs, seeders and the test suite's own
 *    fixtures run without an authenticated user, and a scope that filtered them to nothing
 *    would break every one of them.
 *  - **No employee row, no scope.** An auditor or the implementer has no employee record and
 *    therefore no unit; `factoryUnitId()` is documented to leave them unscoped rather than
 *    locked out, and that is the behaviour relied on here.
 *  - **A row with no unit is visible to everyone.** `expenses.factory_unit_id` is nullable and
 *    the seed carries one such row. Hiding it would make it unreachable from any account,
 *    which is a worse failure than showing it.
 *
 * @implements Scope<Model>
 */
class BelongsToFactoryUnit implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $unitId = $user->factoryUnitId();

        if ($unitId === null) {
            return;
        }

        $column = $model->qualifyColumn('factory_unit_id');

        $builder->where(fn (Builder $query) => $query
            ->where($column, $unitId)
            ->orWhereNull($column));
    }
}
