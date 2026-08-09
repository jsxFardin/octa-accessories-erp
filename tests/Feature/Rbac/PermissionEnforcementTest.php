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

            // `trip.access` is a Gate ability composed of two catalogue permissions
            // (view_any / view_own), not a permission row of its own.
            if ($permission === 'trip.access') {
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
    $exempt = [
        'login', 'logout', 'up', '/', 'floor', 'portal', 'api/v1/device/session',
        'profile', 'profile/password',
    ];
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (str_starts_with($uri, '_') || str_starts_with($uri, 'storage/') || str_starts_with($uri, 'api/')) {
            continue;
        }

        if (in_array($uri, $exempt, true) || str_starts_with($uri, 'floor') || str_starts_with($uri, 'portal')) {
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

it('seeds a role for every role named in the specification', function (): void {
    $seeded = App\Models\Role::query()->pluck('name')->all();

    expect(array_diff(RoleSeeder::names(), $seeded))->toBe([]);
});

it('gives an operator exactly four permissions', function (): void {
    /** @var User $operator */
    $operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();

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
    $driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();

    expect($driver->permissionNames())->toEqualCanonicalizing([
        'trip.view_own',
        'trip_stop.update',
        'pod.create',
    ]);
});

it('lets read_only view everything but export nothing', function (): void {
    /** @var User $auditor */
    $auditor = User::query()->where('email', 'auditor@maheenlabel.test')->firstOrFail();
    $permissions = $auditor->permissionNames();

    // Exporting is a data-exfiltration path and is granted deliberately (06-rbac §6).
    expect($permissions)->toContain('sales_order.view_any', 'job_card.view', 'stock_lot.view_any')
        ->and(array_filter($permissions, fn (string $p): bool => str_ends_with($p, '.export')))->toBe([])
        ->and(array_filter($permissions, fn (string $p): bool => str_ends_with($p, '.create')))->toBe([]);
});

it('lets the md approve exceptions but not enter transactional data', function (): void {
    /** @var User $md */
    $md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();

    // An MD who enters data is an MD who breaks the audit trail (06-rbac §6).
    expect($md->hasPermission('sales_order.release_credit_hold'))->toBeTrue()
        ->and($md->hasPermission('purchase_order.approve'))->toBeTrue()
        ->and($md->hasPermission('cost_sheet.override_margin'))->toBeTrue()
        ->and($md->hasPermission('sales_order.create'))->toBeFalse()
        ->and($md->hasPermission('job_card.create'))->toBeFalse();
});

it('lets super_admin through without holding a single permission row', function (): void {
    /** @var User $admin */
    $admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();

    // The escape hatch cannot be revoked by editing a role, which is what keeps the
    // implementer out of a lockout.
    expect($admin->permissionNames())->toBe([])
        ->and($admin->hasPermission('anything.at.all'))->toBeTrue();
});

it('rejects an unpermitted user at the route, not at the button', function (): void {
    $operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();

    $this->actingAs($operator)->get('/sales-orders')->assertForbidden();
    $this->actingAs($operator)->get('/admin/settings')->assertForbidden();
});

it('lets a merchandiser reach the commercial screens', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();

    $this->actingAs($merchandiser)->get('/sales-orders')->assertOk();
    $this->actingAs($merchandiser)->get('/quotations')->assertOk();
    $this->actingAs($merchandiser)->get('/artworks')->assertOk();
});

it('sends a guest to the login screen rather than a 403', function (): void {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('flushes a user permission cache when their roles change', function (): void {
    /** @var User $user */
    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();

    expect($user->hasPermission('sales_order.create'))->toBeFalse();

    $merchandiserRoleId = App\Models\Role::query()->where('name', 'merchandiser')->value('id');

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail())
        ->post("/admin/users/{$user->id}/roles", ['role_ids' => [$merchandiserRoleId]])
        ->assertRedirect();

    expect($user->fresh()->hasPermission('sales_order.create'))->toBeTrue();
});
