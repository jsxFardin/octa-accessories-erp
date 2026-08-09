<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles are bundles of permissions, editable by an admin without a deploy (06-rbac §2).
 *
 * The screen is a module × action matrix because that is how the specification's own matrix
 * reads. The exceptional actions — `override_margin`, `waive_material`, `release_credit_hold`
 * — are listed separately rather than folded into an "all" checkbox: 06-rbac §1 calls them
 * out precisely so that granting one is a deliberate act.
 */
class RoleController extends Controller
{
    /** The columns of the matrix, in the order the specification lists them. */
    private const MATRIX_ACTIONS = ['view_any', 'view', 'create', 'update', 'delete', 'export'];

    public function index(): Response
    {
        $roles = Role::query()
            ->with('permissions:id,name,module')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'is_system' => $role->is_system,
                'users_count' => $role->users_count,
                'permission_count' => $role->permissions->count(),
                'permissions' => $role->permissions->pluck('name')->values(),
                'modules' => $role->permissions->pluck('module')->unique()->values(),
                // super_admin holds no rows — the escape hatch is in code, not in data, so it
                // cannot be revoked by editing a role (06-rbac §2).
                'is_implicit_full_access' => $role->name === Role::SUPER_ADMIN,
            ]);

        return Inertia::render('Admin/Roles', [
            'roles' => $roles,
            'catalogue' => $this->catalogue(),
            'matrixActions' => self::MATRIX_ACTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $role = DB::transaction(function () use ($data): Role {
            /** @var Role $role */
            $role = Role::query()->create([
                'name' => $data['name'],
                'label' => $data['label'],
                // Only the seeder marks a role as a system role; one created here is not.
                'is_system' => false,
            ]);

            $this->syncPermissions($role, $data['permissions']);

            return $role;
        });

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Role “{$role->label}” created with ".count($data['permissions']).' permission(s).');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request, $role);

        if ($role->is_system && $data['name'] !== $role->name) {
            return back()->with('error', "“{$role->label}” is a system role; its name is referenced by the seeder and cannot change.");
        }

        DB::transaction(function () use ($role, $data): void {
            $role->update(['name' => $data['name'], 'label' => $data['label']]);
            $this->syncPermissions($role, $data['permissions']);
        });

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Role “{$role->label}” updated.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        // A system role is part of the specification's matrix; deleting it would leave the
        // seeder recreating a role nobody expected to come back.
        if ($role->is_system) {
            return back()->with('error', "“{$role->label}” is a system role and cannot be deleted.");
        }

        if ($role->users()->exists()) {
            return back()->with(
                'error',
                "“{$role->label}” is assigned to {$role->users()->count()} user(s). Reassign them first.",
            );
        }

        $role->delete();

        return redirect()
            ->route('admin.roles.index')
            ->with('success', "Role “{$role->label}” deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            // Code checks ask for permissions, never role names — but the name is still the
            // seeder's handle on the row, so it stays a slug.
            'name' => [
                'required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->ignore($role?->id),
            ],
            'label' => ['required', 'string', 'max:120'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], [
            'name.regex' => 'The role name is a slug: lower-case letters, numbers and underscores (e.g. shift_supervisor).',
        ]);
    }

    /** @param list<string> $permissions */
    private function syncPermissions(Role $role, array $permissions): void
    {
        $ids = Permission::query()->whereIn('name', $permissions)->pluck('id')->all();

        $role->permissions()->sync($ids);

        // 06-rbac §7 — permission caches are flushed on a role change, or the people holding
        // this role keep the old set until the cache expires.
        User::query()
            ->whereIn('id', $role->users()->select('users.id'))
            ->each(fn (User $user) => $user->forgetPermissionCache());
    }

    /**
     * The permission catalogue as the screen needs it: one row per resource, the standard
     * actions as columns, and the exceptional actions listed separately.
     *
     * @return list<array<string, mixed>>
     */
    private function catalogue(): array
    {
        $grouped = [];

        foreach (Permission::query()->orderBy('module')->orderBy('name')->get() as $permission) {
            [$resource, $action] = explode('.', $permission->name, 2);

            $grouped[$resource] ??= [
                'resource' => $resource,
                'label' => ucfirst(str_replace('_', ' ', $resource)),
                'module' => $permission->module,
                'actions' => [],
                'extras' => [],
            ];

            if (in_array($action, self::MATRIX_ACTIONS, true)) {
                $grouped[$resource]['actions'][$action] = $permission->name;
            } else {
                $grouped[$resource]['extras'][] = [
                    'action' => $action,
                    'name' => $permission->name,
                    'label' => ucfirst(str_replace('_', ' ', $action)),
                ];
            }
        }

        return array_values($grouped);
    }
}
