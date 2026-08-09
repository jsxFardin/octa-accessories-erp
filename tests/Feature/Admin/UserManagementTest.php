<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

it('creates a user with roles and an employee record', function (): void {
    $roleId = Role::query()->where('name', 'qc_inspector')->value('id');
    $unitId = DB::table('factory_units')->value('id');

    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'Nusrat Jahan',
            'email' => 'nusrat@maheenlabel.test',
            'password' => 'correct-horse-42',
            'password_confirmation' => 'correct-horse-42',
            'locale' => 'bn',
            'is_active' => true,
            'role_id' => $roleId,
            'employee_code' => 'EMP-9001',
            'card_no' => 'BADGE-9001',
            'designation' => 'QC inspector',
            'factory_unit_id' => $unitId,
        ])
        ->assertRedirect('/admin/users');

    $user = User::query()->where('email', 'nusrat@maheenlabel.test')->firstOrFail();

    expect($user->hasRole('qc_inspector'))->toBeTrue()
        ->and($user->locale)->toBe('bn')
        ->and(Hash::check('correct-horse-42', $user->password))->toBeTrue()
        // The employee row is what carries the factory-unit scope (06-rbac §4).
        ->and($user->fresh()->factoryUnitId())->toBe((int) $unitId)
        ->and(DB::table('employees')->where('user_id', $user->id)->value('card_no'))->toBe('BADGE-9001');
});

it('requires a password on create but not on edit', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'No Password', 'email' => 'nopass@maheenlabel.test',
            'locale' => 'en', 'role_id' => Role::query()->value('id'),
        ])
        ->assertSessionHasErrors('password');

    $lab = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $before = $lab->password;

    $this->actingAs($this->admin)
        ->put("/admin/users/{$lab->id}", [
            'name' => 'Farhana Yasmin', 'email' => $lab->email,
            'locale' => 'en', 'is_active' => true, 'role_id' => $lab->roles->first()->id,
        ])
        ->assertRedirect('/admin/users');

    // An empty password field means "leave it alone", not "blank it".
    expect($lab->fresh()->password)->toBe($before);
});

it('rejects a weak password', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'Weak', 'email' => 'weak@maheenlabel.test',
            'password' => 'password', 'password_confirmation' => 'password',
            'locale' => 'en', 'role_id' => Role::query()->value('id'),
        ])
        ->assertSessionHasErrors('password');
});

it('rejects a duplicate email', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'Clash', 'email' => 'lab@maheenlabel.test',
            'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
            'locale' => 'en', 'role_id' => Role::query()->value('id'),
        ])
        ->assertSessionHasErrors('email');
});

it('changes roles on edit and audit-logs the change', function (): void {
    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $before = $user->roleNames();
    $merchandiserId = Role::query()->where('name', 'merchandiser')->value('id');

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'locale' => 'en',
            'is_active' => true, 'role_id' => $merchandiserId,
        ])
        ->assertRedirect('/admin/users');

    expect($user->fresh()->hasPermission('sales_order.create'))->toBeTrue();

    $entry = DB::table('audit_logs')
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->id)
        ->latest('id')
        ->first();

    expect(json_decode($entry->old_values, true)['roles'])->toBe($before)
        ->and(json_decode($entry->new_values, true)['roles'])->toBe(['merchandiser']);
});

it('deactivates rather than deletes, so the audit trail still resolves', function (): void {
    $user = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();

    $this->actingAs($this->admin)->delete("/admin/users/{$user->id}")->assertRedirect();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('will not let an admin deactivate their own account', function (): void {
    $this->actingAs($this->admin)
        ->delete("/admin/users/{$this->admin->id}")
        ->assertSessionHas('error');

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('keeps user management behind its own permissions', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();

    $this->actingAs($merchandiser)->get('/admin/users')->assertForbidden();
    $this->actingAs($merchandiser)->post('/admin/users', [])->assertForbidden();
});

it('refuses a deactivated account at sign-in', function (): void {
    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $user->update(['is_active' => false]);

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('holds a user to exactly one role', function (): void {
    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $plannerId = Role::query()->where('name', 'planner')->value('id');

    // The pivot table admits many rows; the application admits one. Two roles would mean two
    // answers to "what may this person do", and the union is never what anyone intended.
    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'locale' => 'en',
            'is_active' => true, 'role_id' => $plannerId,
        ])
        ->assertRedirect('/admin/users');

    expect(DB::table('user_roles')->where('user_id', $user->id)->count())->toBe(1)
        ->and($user->fresh()->roleNames())->toBe(['planner']);
});

it('requires a role on both create and edit', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/users', [
            'name' => 'Roleless', 'email' => 'roleless@maheenlabel.test',
            'password' => 'correct-horse-42', 'password_confirmation' => 'correct-horse-42',
            'locale' => 'en',
        ])
        ->assertSessionHasErrors('role_id');

    $user = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/admin/users/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'locale' => 'en', 'is_active' => true,
        ])
        ->assertSessionHasErrors('role_id');
});

it('seeds every user with exactly one role', function (): void {
    $multiRole = DB::table('user_roles')
        ->select('user_id')
        ->groupBy('user_id')
        ->havingRaw('COUNT(*) > 1')
        ->pluck('user_id');

    expect($multiRole->all())->toBe([]);
});
