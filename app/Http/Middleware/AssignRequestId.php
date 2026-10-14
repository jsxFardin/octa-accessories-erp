<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-58 — one id per request, on every log line it writes and on the response.
 *
 * A support ticket quotes the reference from the error page; without this nothing in the log
 * carried it, so the lines belonging to that request were found by timestamp and luck. An id
 * the caller sends (a proxy, a retrying device) is kept so the two sides agree on it.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) ($request->header('X-Request-Id') ?: Str::uuid());

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
