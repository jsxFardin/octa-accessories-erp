<?php

declare(strict_types=1);

namespace App\Support\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\FactoryUnit;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ListsResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    use ListsResources;

    public function index(Request $request): Response
    {
        $query = User::query()
            ->with(['roles:id,name,label', 'employee.factoryUnit:id,code,name', 'employee.department:id,code,name']);

        $this->applyListing(
            $query,
            $request,
            searchable: ['name', 'email'],
            filters: ['active' => 'is_active'],
            sortable: ['name', 'email', 'last_login_at'],
            defaultSort: 'name',
        );

        if ($roleId = $request->query('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('roles.id', $roleId));
        }

        return Inertia::render('Admin/Users', [
            'users' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'locale' => $user->locale,
                    'is_active' => $user->is_active,
                    'last_login_at' => $user->last_login_at,
                    'role_id' => $user->roles->first()?->id,
                    'role' => $user->roles->first()?->only(['id', 'name', 'label']),
                    'factory_unit' => $user->employee?->factoryUnit?->code,
                    'permission_count' => count($user->permissionNames()),
                    'employee' => $user->employee?->only([
                        'id', 'code', 'card_no', 'designation', 'factory_unit_id', 'department_id',
                    ]),
                ],
            ),
            'filters' => $this->listingFilters($request, ['active', 'role']),
            'roles' => Role::query()->orderByDesc('is_system')->orderBy('name')->get(['id', 'name', 'label']),
            'units' => FactoryUnit::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => Department::query()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data): User {
            /** @var User $user */
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'],
                'is_active' => $data['is_active'],
                'email_verified_at' => now(),
            ]);

            $this->assignRole($user, $data['role_id']);
            $this->syncEmployee($user, $data);

            return $user;
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->name} created.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);
        $before = $user->roleNames();

        DB::transaction(function () use ($user, $data): void {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'locale' => $data['locale'],
                'is_active' => $data['is_active'],
            ]);

            // An empty password field means "leave it alone", not "blank it".
            if (filled($data['password'] ?? null)) {
                $user->forceFill(['password' => $data['password']])->save();
            }

            $this->assignRole($user, $data['role_id']);
            $this->syncEmployee($user, $data);
        });

        $user->load('roles');
        $user->forgetPermissionCache();

        if ($before !== $user->roleNames()) {
            app(AuditLogger::class)->record(
                $user,
                'updated',
                ['roles' => $before],
                ['roles' => $user->roleNames()],
            );
        }

        return redirect()
            ->route('admin.users.index')
            ->with('success', "User {$user->name} updated.");
    }

    /**
     * Role changes are audit-logged with the old and new sets (06-rbac §7), and the user's
     * permission cache is flushed so the change takes effect on their next request rather
     * than after a TTL.
     */
    public function updateRoles(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $before = $user->roleNames();

        DB::transaction(function () use ($user, $data): void {
            $this->assignRole($user, $data['role_id']);
        });

        $user->load('roles');
        $user->forgetPermissionCache();

        // The event vocabulary is fixed by `audit_logs_event_chk`; a role change is an
        // update to the user, and the old/new role sets are what make it readable.
        app(AuditLogger::class)->record(
            $user,
            'updated',
            ['roles' => $before],
            ['roles' => $user->roleNames()],
        );

        return back()->with('success', "Roles updated for {$user->name}.");
    }

    /** Deactivate rather than delete: the audit trail points at this row. */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => false]);

        return back()->with('success', "{$user->name} deactivated. Their history is kept.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [
                $user === null ? 'required' : 'nullable',
                'confirmed',
                Password::min(10)->letters()->numbers(),
            ],
            'locale' => ['required', 'in:en,bn'],
            'is_active' => ['boolean'],
            // One user, one role. `user_roles` remains a pivot table because the schema
            // (docs/02a-schema.sql §1) declares it as one, but the application admits a single
            // row: two roles means two answers to "what may this person do", and the union of
            // them is never what anybody intended.
            'role_id' => ['required', 'integer', 'exists:roles,id'],

            // The employee row carries the factory-unit scope (06-rbac §4) and the badge the
            // shop-floor terminal signs in with.
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'code')->ignore($user?->employee?->id)],
            'card_no' => ['nullable', 'string', 'max:40', Rule::unique('employees', 'card_no')->ignore($user?->employee?->id)],
            'designation' => ['nullable', 'string', 'max:120'],
            'factory_unit_id' => ['nullable', 'integer', 'exists:factory_units,id', 'required_with:employee_code'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
    }

    /** One user, one role — enforced here rather than trusted to the form. */
    private function assignRole(User $user, int $roleId): void
    {
        $user->roles()->sync([$roleId]);
    }

    /** @param array<string, mixed> $data */
    private function syncEmployee(User $user, array $data): void
    {
        if (blank($data['employee_code'] ?? null)) {
            return;
        }

        DB::table('employees')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'factory_unit_id' => $data['factory_unit_id'],
                'department_id' => $data['department_id'] ?? null,
                'code' => $data['employee_code'],
                'name' => $data['name'],
                'designation' => $data['designation'] ?? null,
                'card_no' => $data['card_no'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ],
        );
    }
}
