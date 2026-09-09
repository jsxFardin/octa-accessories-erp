<?php

declare(strict_types=1);

namespace App\Support\Scoping;

/**
 * Applies `BelongsToFactoryUnit` to a model whose table carries `factory_unit_id`.
 *
 * Operational documents only. Master data — machines, warehouses, departments, shifts — is
 * deliberately left unscoped: a planner has to see the machine list to plan against it, and a
 * store keeper has to see the warehouse a transfer is going to. Those are configuration, and
 * the unit column on them describes where a thing lives rather than who may read it.
 */
trait ScopedToFactoryUnit
{
    public static function bootScopedToFactoryUnit(): void
    {
        static::addGlobalScope(new BelongsToFactoryUnit);
    }
}
