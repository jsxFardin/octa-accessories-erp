<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;

/**
 * 06-rbac §7 — every route carries permission middleware, every permission it names exists,
 * and the boundary is the middleware rather than the hidden button.
 */
it('defines every permission that a route references', function (): void {
    $catalogue = PermissionSeeder::catalogue();
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'can:')) {
                continue;
            }

            $permission = explode(',', substr($middleware, 4))[0];

            // Gate abilities composed of catalogue permissions, not permission rows of their
            // own: `trip.access` (view_any / view_own) and `operation.terminal` (whoever may
            // view or run an operation — see AppServiceProvider).
            if (in_array($permission, ['trip.access', 'operation.terminal'], true)) {
                continue;
            }

            if (! in_array($permission, $catalogue, true)) {
                $missing[] = "{$route->uri()} → {$permission}";
            }
        }
    }

    // A route referencing a permission nobody seeded is a route nobody can ever open.
    expect($missing)->toBe([]);
});

it('guards every application route with a permission', function (): void {
    // Login, logout, the health check and the device API authenticate differently; everything
    // a desk user can reach goes through `can:`.
    // `profile*` is deliberately ungated: changing your own password is not a permission.
    // `search` is the ⌘K palette. A single `can:` would be wrong either way — too strict and
    // nobody can search, too loose and it says nothing — so it authorises each source
    // separately inside SearchController and queries only what the caller may already read.
    $exempt = [
        'login', 'logout', 'up', '/', 'floor', 'portal', 'api/v1/device/session',
        // The kiosk's own credentials. `floor/session` is the badge-and-PIN post — it runs
        // before there is a session to hold a permission, and is rate limited instead
        // (`throttle:10,1`, matching the device API). `floor/session/end` is a logout: someone
        // who landed on the kiosk without terminal rights still has to be able to get off it.
        // `floor/session/continue` is *not* here — it carries `can:operation.terminal`.
        'floor/session', 'floor/session/end',
        // Installability. The browser fetches both of these itself, for itself: the manifest
        // whenever the badge screen is opened, the worker on registration and again on every
        // update check — all of it before anybody has signed in, and none of it able to send
        // a session cookie on a worker update. They carry no factory data: a company name and
        // some icon paths, and a list of hashed asset filenames the build already serves
        // publicly under /build.
        'floor/manifest.webmanifest', 'floor/sw.js',
        'profile', 'profile/password', 'profile/locale', 'search',
        // Own-user inbox: the row belongs to the caller, so a route-level `can:` would
        // either lock everyone out or say nothing (same reasoning as profile).
        'notifications', 'notifications/{notification}/read', 'notifications/read-all',
        // Export is gated per resource inside the controller — `sales_order.export` for one
        // list, `item.export` for the next — which a single route-level `can:` cannot express.
        'exports/{resource}', 'exports/{resource}/columns',
        // Import, for the same reason: `customer.import` for one list, `item.import` for the next.
        'imports/{resource}', 'imports/{resource}/fields', 'imports/{resource}/sample',
        // The 404 fallback renders the error page for any unmatched URL. A permission gate
        // here would turn "page not found" into "permission denied" — wrong answer, and it
        // must work for guests too.
        '{fallbackPlaceholder}',
    ];
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (str_starts_with($uri, '_') || str_starts_with($uri, 'storage/') || str_starts_with($uri, 'api/')) {
            continue;
        }

        // `floor` and `portal` are no longer skipped wholesale. The prefix exclusion hid the
        // fact that `floor/operations/{operation}` carried `auth` and nothing else, so any
        // authenticated employee could read a job card they are refused on `/job-cards/{id}`.
        // Only the two screens that are genuinely open to any signed-in user are exempt by
        // name: the kiosk sign-in page, and the portal stub that renders no data.
        if (in_array($uri, $exempt, true)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $hasPermission = collect($middleware)->contains(
            fn ($m): bool => is_string($m) && str_starts_with($m, 'can:'),
        );

        if (! $hasPermission) {
            $unguarded[] = "{$route->methods()[0]} {$uri}";
        }
    }

    expect($unguarded)->toBe([]);
});

/*
 * The device API is excluded from the `can:` sweep above because it authenticates differently:
 * a badge-and-PIN token, not a session and a permission. That exclusion was silent, so nothing
 * asserted the group was guarded *at all* — and the operation write endpoints were reachable
 * with any valid token regardless of which factory unit it belonged to. This states the rule
 * the exclusion assumes.
 */
it('guards every device API route with the device session middleware', function (): void {
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        // Issuing a session is the one route that cannot already hold one.
        if ($uri === 'api/v1/device/session' && in_array('POST', $route->methods(), true)) {
            continue;
        }

        if (! collect($route->gatherMiddleware())->contains('device')) {
            $unguarded[] = "{$route->methods()[0]} {$uri}";
        }
    }

    expect($unguarded)->toBe([]);
});

it('seeds a role for every role named in the specification', function (): void {
    $seeded = App\Models\Role::query()->pluck('name')->all();

    expect(array_diff(RoleSeeder::names(), $seeded))->toBe([]);
});

it('gives an operator exactly four permissions', function (): void {
    /** @var User $operator */
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    // 06-rbac §6 — the terminal opens nothing else, and that is a data fact, not a UI habit.
    expect($operator->permissionNames())->toEqualCanonicalizing([
        'operation.start',
        'operation.log',
        'operation.finish',
        'downtime.create',
    ]);
});

it('gives a driver only their own trips and the pod', function (): void {
    /** @var User $driver */
    $driver = User::query()->where('email', 'driver@octapussolution.com')->firstOrFail();

    expect($driver->permissionNames())->toEqualCanonicalizing([
        'trip.view_own',
        'trip_stop.update',
        'pod.create',
    ]);
});

it('lets read_only view everything but export nothing', function (): void {
    /** @var User $auditor */
    $auditor = User::query()->where('email', 'auditor@octapussolution.com')->firstOrFail();
    $permissions = $auditor->permissionNames();

    // Exporting is a data-exfiltration path and is granted deliberately (06-rbac §6).
    expect($permissions)->toContain('sales_order.view_any', 'job_card.view', 'stock_lot.view_any')
        ->and(array_filter($permissions, fn (string $p): bool => str_ends_with($p, '.export')))->toBe([])
        ->and(array_filter($permissions, fn (string $p): bool => str_ends_with($p, '.create')))->toBe([]);
});

it('lets the md approve exceptions but not enter transactional data', function (): void {
    /** @var User $md */
    $md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();

    // An MD who enters data is an MD who breaks the audit trail (06-rbac §6).
    expect($md->hasPermission('sales_order.release_credit_hold'))->toBeTrue()
        ->and($md->hasPermission('purchase_order.approve'))->toBeTrue()
        ->and($md->hasPermission('cost_sheet.override_margin'))->toBeTrue()
        ->and($md->hasPermission('sales_order.create'))->toBeFalse()
        ->and($md->hasPermission('job_card.create'))->toBeFalse();
});

it('lets super_admin through without holding a single permission row', function (): void {
    /** @var User $admin */
    $admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();

    // The escape hatch cannot be revoked by editing a role, which is what keeps the
    // implementer out of a lockout.
    expect($admin->permissionNames())->toBe([])
        ->and($admin->hasPermission('anything.at.all'))->toBeTrue();
});

it('rejects an unpermitted user at the route, not at the button', function (): void {
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    $this->actingAs($operator)->get('/sales-orders')->assertForbidden();
    $this->actingAs($operator)->get('/admin/settings')->assertForbidden();
});

it('lets a merchandiser reach the commercial screens', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();

    $this->actingAs($merchandiser)->get('/sales-orders')->assertOk();
    $this->actingAs($merchandiser)->get('/quotations')->assertOk();
    $this->actingAs($merchandiser)->get('/artworks')->assertOk();
});

it('sends a guest to the login screen rather than a 403', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('flushes a user permission cache when their roles change', function (): void {
    /** @var User $user */
    $user = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();

    expect($user->hasPermission('sales_order.create'))->toBeFalse();

    $merchandiserRoleId = App\Models\Role::query()->where('name', 'merchandiser')->value('id');

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->post("/admin/users/{$user->id}/roles", ['role_id' => $merchandiserRoleId])
        ->assertRedirect();

    expect($user->fresh()->hasPermission('sales_order.create'))->toBeTrue();
});
