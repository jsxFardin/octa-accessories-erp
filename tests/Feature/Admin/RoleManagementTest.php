<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 06-rbac §2 — roles are bundles of permissions, editable by an admin without a deploy.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

it('creates a custom role with the permissions it was given', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/roles', [
            'name' => 'shift_supervisor',
            'label' => 'Shift supervisor',
            'permissions' => ['job_card.view_any', 'job_card.view', 'operation.start', 'downtime.create'],
        ])
        ->assertRedirect('/admin/roles');

    $role = Role::query()->where('name', 'shift_supervisor')->firstOrFail();

    expect($role->label)->toBe('Shift supervisor')
        // A role created through the screen is not a system role, whatever it is called.
        ->and($role->is_system)->toBeFalse()
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(['downtime.create', 'job_card.view', 'job_card.view_any', 'operation.start']);
});

it('rejects a role name that is not a slug', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/roles', ['name' => 'Shift Supervisor', 'label' => 'x', 'permissions' => []])
        ->assertSessionHasErrors('name');
});

it('rejects a duplicate role name', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/roles', ['name' => 'planner', 'label' => 'Another planner', 'permissions' => []])
        ->assertSessionHasErrors('name');
});

it('rejects a permission that is not in the catalogue', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/roles', [
            'name' => 'ghost', 'label' => 'Ghost', 'permissions' => ['nonsense.invent'],
        ])
        ->assertSessionHasErrors('permissions.0');
});

it('edits an existing role and flushes the permission cache of everyone holding it', function (): void {
    $planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();
    $role = Role::query()->where('name', 'planner')->firstOrFail();

    // Warm the cache so the flush has something to invalidate.
    expect($planner->hasPermission('mrp.run'))->toBeTrue();

    $this->actingAs($this->admin)
        ->put("/admin/roles/{$role->id}", [
            'name' => 'planner',
            'label' => 'Production planner',
            'permissions' => ['job_card.view_any'],
        ])
        ->assertRedirect('/admin/roles');

    // 06-rbac §7 — the change takes effect on the next request, not after a TTL.
    expect($planner->fresh()->hasPermission('mrp.run'))->toBeFalse()
        ->and($planner->fresh()->hasPermission('job_card.view_any'))->toBeTrue();
});

it('will not rename a system role', function (): void {
    $role = Role::query()->where('name', 'operator')->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/admin/roles/{$role->id}", [
            'name' => 'machine_operator',
            'label' => 'Machine operator',
            'permissions' => ['operation.start'],
        ])
        ->assertSessionHas('error');

    expect($role->fresh()->name)->toBe('operator');
});

it('will not delete a system role', function (): void {
    $role = Role::query()->where('name', 'operator')->firstOrFail();

    $this->actingAs($this->admin)->delete("/admin/roles/{$role->id}")->assertSessionHas('error');

    expect(Role::query()->where('name', 'operator')->exists())->toBeTrue();
});

it('will not delete a role that still has users', function (): void {
    $role = Role::query()->create(['name' => 'temp_role', 'label' => 'Temp', 'is_system' => false]);
    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $user->roles()->attach($role->id);

    $this->actingAs($this->admin)->delete("/admin/roles/{$role->id}")->assertSessionHas('error');

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

it('deletes an unused custom role', function (): void {
    $role = Role::query()->create(['name' => 'unused_role', 'label' => 'Unused', 'is_system' => false]);

    $this->actingAs($this->admin)->delete("/admin/roles/{$role->id}")->assertRedirect('/admin/roles');

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('keeps role management behind its own permissions', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();

    $this->actingAs($merchandiser)->get('/admin/roles')->assertForbidden();
    $this->actingAs($merchandiser)->post('/admin/roles', [
        'name' => 'sneaky', 'label' => 'Sneaky', 'permissions' => [],
    ])->assertForbidden();
});

it('exposes every catalogue permission to the editor', function (): void {
    $response = $this->actingAs($this->admin)->get('/admin/roles');

    $catalogue = $response->viewData('page')['props']['catalogue'];

    $offered = collect($catalogue)->sum(
        fn (array $row): int => count($row['actions']) + count($row['extras']),
    );

    // Nothing may be grantable only by editing the database.
    expect($offered)->toBe(DB::table('permissions')->count());
});
