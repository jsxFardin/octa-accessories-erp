<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = User::query()->with(['roles:id,name,label', 'employee.factoryUnit:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['name', 'email'],
            filters: ['active' => 'is_active'],
            sortable: ['name', 'email', 'last_login_at'],
            defaultSort: 'name',
        );

        return Inertia::render('Admin/Users', [
            'users' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at,
                    'roles' => $user->roles->map->only(['id', 'name', 'label']),
                    'factory_unit' => $user->employee?->factoryUnit?->code,
                    'permission_count' => count($user->permissionNames()),
                ],
            ),
            'filters' => $this->listingFilters($request, ['active']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name', 'label']),
        ]);
    }

    /**
     * Role changes are audit-logged with the old and new sets (06-rbac §7), and the user's
     * permission cache is flushed so the change takes effect on their next request rather
     * than after a TTL.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $before = $user->roleNames();

        DB::transaction(function () use ($user, $data): void {
            $user->roles()->sync($data['role_ids']);
        });

        $user->load('roles');
        $user->forgetPermissionCache();

        // The event vocabulary is fixed by `audit_logs_event_chk`; a role change is an
        // update to the user, and the old/new role sets are what make it readable.
        app(\App\Support\Audit\AuditLogger::class)->record(
            $user,
            'updated',
            ['roles' => $before],
            ['roles' => $user->roleNames()],
        );

        return back()->with('success', "Roles updated for {$user->name}.");
    }
}
