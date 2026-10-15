<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GAP S-01 — who may change whose role.
 *
 * `PUT /admin/users/{user}` sits behind `user.update`, but it used to sync whatever `role_id`
 * came with the form. A holder of `user.update` alone could make any account — their own
 * included — a super_admin. The edit form is now held to the same rules as the role action:
 * a role change needs `user.assign_role`, nobody changes their own role, and `super_admin`
 * is granted and removed only by a super_admin.
 */
function grantPermissions(User $user, string ...$permissions): User
{
    foreach ($permissions as $permission) {
        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $user->roles->first()->id,
            'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'),
        ]);
    }
    cache()->flush();

    return $user->fresh();
}

function userEditPayload(User $user, int $roleId): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => $user->locale,
        'is_active' => true,
        'role_id' => $roleId,
    ];
}

beforeEach(function (): void {
    $this->superAdmin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->editor = User::query()->where('email', 'auditor@octapussolution.com')->firstOrFail();
    $this->target = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->superAdminRole = Role::query()->where('name', Role::SUPER_ADMIN)->firstOrFail();
    $this->merchandiserRole = Role::query()->where('name', 'merchandiser')->firstOrFail();
});

it('refuses a role change from the edit form without user.assign_role', function (): void {
    $editor = grantPermissions($this->editor, 'user.update');
    $before = $this->target->roleNames();

    $this->actingAs($editor)
        ->put("/admin/users/{$this->target->id}", userEditPayload($this->target, $this->merchandiserRole->id))
        ->assertSessionHasErrors('role_id');

    expect($this->target->fresh()->roleNames())->toBe($before);
});

it('still saves the rest of the edit form when the role is unchanged', function (): void {
    $editor = grantPermissions($this->editor, 'user.update');
    $currentRole = $this->target->roles->first()->id;

    $this->actingAs($editor)
        ->put("/admin/users/{$this->target->id}", [
            ...userEditPayload($this->target, $currentRole),
            'name' => 'Farhana Y.',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/admin/users');

    expect($this->target->fresh()->name)->toBe('Farhana Y.');
});

it('never lets a non-super-admin grant super_admin, even with user.assign_role', function (): void {
    $editor = grantPermissions($this->editor, 'user.update', 'user.assign_role');

    $this->actingAs($editor)
        ->put("/admin/users/{$this->target->id}", userEditPayload($this->target, $this->superAdminRole->id))
        ->assertSessionHasErrors('role_id');

    $this->actingAs($editor)
        ->post("/admin/users/{$this->target->id}/roles", ['role_id' => $this->superAdminRole->id])
        ->assertSessionHasErrors('role_id');

    expect($this->target->fresh()->hasRole(Role::SUPER_ADMIN))->toBeFalse();
});

it('never lets a non-super-admin demote a super_admin', function (): void {
    $editor = grantPermissions($this->editor, 'user.update', 'user.assign_role');

    $this->actingAs($editor)
        ->post("/admin/users/{$this->superAdmin->id}/roles", ['role_id' => $this->merchandiserRole->id])
        ->assertSessionHasErrors('role_id');

    expect($this->superAdmin->fresh()->hasRole(Role::SUPER_ADMIN))->toBeTrue();
});

it('refuses a self-service role change', function (): void {
    $editor = grantPermissions($this->editor, 'user.update', 'user.assign_role');

    $this->actingAs($editor)
        ->put("/admin/users/{$editor->id}", userEditPayload($editor, $this->merchandiserRole->id))
        ->assertSessionHasErrors('role_id');

    $this->actingAs($editor)
        ->post("/admin/users/{$editor->id}/roles", ['role_id' => $this->merchandiserRole->id])
        ->assertSessionHasErrors('role_id');

    expect($editor->fresh()->hasRole('read_only'))->toBeTrue();
});

it('lets a super_admin do all of it', function (): void {
    $this->actingAs($this->superAdmin)
        ->put("/admin/users/{$this->target->id}", userEditPayload($this->target, $this->superAdminRole->id))
        ->assertSessionHasNoErrors();

    expect($this->target->fresh()->hasRole(Role::SUPER_ADMIN))->toBeTrue();

    $this->actingAs($this->superAdmin)
        ->post("/admin/users/{$this->target->id}/roles", ['role_id' => $this->merchandiserRole->id])
        ->assertSessionHasNoErrors();

    expect($this->target->fresh()->roleNames())->toBe(['merchandiser']);
});
