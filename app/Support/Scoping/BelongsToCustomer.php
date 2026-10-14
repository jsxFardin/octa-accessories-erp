<?php

declare(strict_types=1);

namespace App\Support\Scoping;

use Illuminate\Database\Eloquent\Builder;

/**
 * Portal isolation (06-rbac §4, NFR-21/31).
 *
 * While a request is a portal request — `EnsurePortalCustomer` has bound a customer into
 * `PortalContext` — every query on a model that carries this trait is confined to that
 * customer's rows. A global scope, not a controller `where`: a `where` forgotten in one
 * controller is a leak, a scope that fails safe is not. Off the portal the context is empty
 * and the scope adds nothing, so the desk sees everything as before.
 *
 * `routes/portal.php` and the user form said this scope was the prerequisite for portal
 * contacts; it exists now. The role stays withheld until the portal has data routes with
 * cross-customer tests behind them.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToCustomer
{
    public static function bootBelongsToCustomer(): void
    {
        static::addGlobalScope('portal_customer', function (Builder $query): void {
            $context = app(PortalContext::class);

            if ($context->isActive()) {
                $query->where($query->getModel()->getTable().'.customer_id', $context->customerId());
            }
        });
    }
}
