<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Print\DocumentRegistry;
use App\Support\Reference\ReferenceRegistry;
use App\Support\Settings\Organisation;
use App\Support\Settings\Settings;
use App\Support\Text\Plain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Whether this user still signs in with the seeded password.
     *
     * A bcrypt check costs tens of milliseconds, far too much to pay on every request, so the
     * answer is kept in the session against the password hash it was worked out for: changing
     * the password changes the hash and the question is asked again, once.
     */
    private function usingSeedPassword(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $key = 'seed_password.'.sha1((string) $user->password);

        if (! $request->session()->has($key)) {
            $request->session()->put($key, Hash::check('password', (string) $user->password));
        }

        return (bool) $request->session()->get($key);
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
                    // Every seeded account starts on one shared password. The shell says so on
                    // every page until it is changed — it used to be mentioned only on the
                    // profile screen, which nobody opens unprompted.
                    'using_seed_password' => $this->usingSeedPassword($request, $user),
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
             * The Setup group of the sidebar: every reference list under its group. Static
             * data; the sidebar filters it by the same permissions the list pages enforce.
             */
            'setupMenu' => fn (): array => $user === null ? [] : ReferenceRegistry::menu(),

            /*
             * What each printable document is called, where it lives and the statuses in which it
             * may not leave the building — from DocumentRegistry, so a Print button and the
             * controller behind it cannot disagree about whether a draft is printable.
             */
            'documents' => DocumentRegistry::forFrontend(),

            /*
             * Said without the rule number. The code that refuses something names its rule
             * ("J3: …", "… (P1-1 · QC1)") because that is how the rule is found in the logs and
             * the documents; on a banner the number reads as an error code. The reference stays
             * in the rule tooltips, which is where a person can look it up.
             */
            'flash' => [
                'success' => fn () => Plain::message($request->session()->get('success')),
                'error' => fn () => Plain::message($request->session()->get('error')),
                'warning' => fn () => Plain::message($request->session()->get('warning')),
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
        ];
    }

    /**
     * Validation messages, likewise without their rule numbers — these are the sentences shown
     * under a field and in the summary beside Save.
     *
     * @return object
     */
    public function resolveValidationErrors(Request $request)
    {
        $errors = (array) parent::resolveValidationErrors($request);

        array_walk_recursive($errors, function (mixed &$message): void {
            if (is_string($message)) {
                $message = Plain::message($message);
            }
        });

        return (object) $errors;
    }
}
