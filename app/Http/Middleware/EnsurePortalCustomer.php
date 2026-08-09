<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\MasterData\Models\CustomerContact;
use App\Support\Scoping\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal isolation (06-rbac §4). The customer id is resolved once, here, and every
 * portal-exposed model applies it as a global scope — never as a controller-level `where`,
 * because a missing `where` in one controller is a data leak while a global scope fails safe.
 */
class EnsurePortalCustomer
{
    public function __construct(private readonly PortalContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403, 'Portal access requires a customer contact login.');
        }

        $contact = CustomerContact::query()
            ->where('portal_user_id', $user->getKey())
            ->first();

        if ($contact === null) {
            abort(403, 'This login is not linked to a customer contact.');
        }

        $this->context->bind((int) $contact->customer_id);

        return $next($request);
    }
}
