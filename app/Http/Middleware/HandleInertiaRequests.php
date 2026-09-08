<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Print\DocumentRegistry;
use App\Support\Settings\Organisation;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared props.
     *
     * Every page receives the user's permission set: the frontend hides what the user cannot
     * do, while the route middleware remains the security boundary (06-rbac §7).
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'roles' => $user->roleNames(),
                    'factory_unit_id' => $user->factoryUnitId(),
                ],
                'permissions' => $user?->permissionNames() ?? [],
            ],

            /*
             * Branding and formatting come from the organisation profile, not from config —
             * an administrator changes the company name and the timezone without a deploy,
             * and every date on every screen has to follow.
             */
            'app' => fn (): array => [
                ...app(Organisation::class)->forFrontend(),
                'base_currency' => app(Settings::class)->get('base_currency', 'BDT'),
                'locale' => app()->getLocale(),
            ],

            /*
             * What each printable document is called, where it lives and the statuses in which it
             * may not leave the building — from DocumentRegistry, so a Print button and the
             * controller behind it cannot disagree about whether a draft is printable.
             */
            'documents' => DocumentRegistry::forFrontend(),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],

            /*
             * Badge count only. The full list was fetched-and-mapped here on every Inertia
             * request — including each debounced filter keystroke — while the bell already
             * refetches `/notifications` when it is opened. One COUNT(*) instead of two
             * queries per request.
             */
            'notifications' => fn (): array => [
                'unread' => $user?->unreadNotifications()->count() ?? 0,
                'notifications' => [],
            ],

            /*
             * Scoped by config/ziggy.php. Route names are not a secret — every route is
             * authorised server-side regardless — but publishing the full admin and destroy
             * surface with every page load is needless reconnaissance material.
             */
            'ziggy' => fn (): array => [
                ...(new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ];
    }
}
