<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-19 — two-step sign-in is mandatory for the roles that can move money or change who may
 * do what. A new account gets a week to enrol (the profile page says so from day one); after
 * that the desk is the profile page and nothing else, and the way out is to enrol, which
 * takes a minute.
 */
class RequireTwoFactor
{
    /** @var list<string> */
    public const REQUIRED_ROLES = ['super_admin', 'md', 'accounts'];

    public const GRACE_DAYS = 7;

    /** @var list<string> */
    private const ALLOWED_PREFIXES = ['profile', 'logout', 'up', 'floor', 'notifications'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! self::overdueFor($user)) {
            return $next($request);
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($request->is($prefix) || $request->is($prefix.'/*')) {
                return $next($request);
            }
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => 'Two-step sign-in must be set up for this account first.'], 403);
        }

        return redirect('/profile')->with('warning', 'Your role requires two-step sign-in. Set it up below before using the rest of the system.');
    }

    public static function requiredFor(User $user): bool
    {
        return array_intersect(self::REQUIRED_ROLES, $user->roleNames()) !== [];
    }

    /** Required, not yet set up, and the week since the account was created has run out. */
    public static function overdueFor(User $user): bool
    {
        return self::requiredFor($user)
            && $user->two_factor_confirmed_at === null
            && $user->created_at->lte(now()->subDays(self::GRACE_DAYS));
    }

    /** Days left to enrol, for the profile card; null once enrolled or when not required. */
    public static function daysLeftFor(User $user): ?int
    {
        if (! self::requiredFor($user) || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInDays($user->created_at->copy()->addDays(self::GRACE_DAYS), false)));
    }
}
