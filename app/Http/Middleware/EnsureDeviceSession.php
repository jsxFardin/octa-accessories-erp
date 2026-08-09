<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Manufacturing\Services\DeviceSessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop-floor device authentication (07-api-contracts §2).
 *
 * Badge scan plus PIN issues a shift-length token. A loom does not stop when the wifi does,
 * so the token outlives connectivity gaps and the client replays its queued writes with the
 * same Idempotency-Key when the network returns.
 */
class EnsureDeviceSession
{
    public function __construct(private readonly DeviceSessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->header('X-Device-Token');

        if ($token === null) {
            return response()->json(['message' => 'Device token missing.'], 401);
        }

        $session = $this->sessions->resolve($token);

        if ($session === null) {
            return response()->json(['message' => 'Device session expired. Scan your badge again.'], 401);
        }

        auth()->setUser($session->user());
        $request->attributes->set('device_session', $session);

        return $next($request);
    }
}
