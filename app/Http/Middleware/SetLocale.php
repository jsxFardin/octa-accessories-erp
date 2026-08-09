<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shop floor runs in Bangla by default (README §4, NFR-49). Locale comes from the user
 * record so an operator's terminal is Bangla while the merchandiser next door is in English.
 */
class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['en', 'bn'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $locale = $user?->locale
            ?: $request->session()->get('locale')
            ?? config('app.locale');

        if (in_array($locale, self::SUPPORTED, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
