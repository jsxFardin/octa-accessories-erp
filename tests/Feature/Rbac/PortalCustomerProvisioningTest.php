<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The `portal_customer` role cannot be handed out until customer scoping exists.
 *
 * 06-rbac §4 says a portal contact is confined to their own customer by a global scope resolved
 * from `customer_contacts.portal_user_id`. That scope does not exist: `EnsurePortalCustomer`
 * binds the id into `PortalContext` and nothing reads it, and authorisation here is
 * permission-only with no row filtering anywhere. Meanwhile the role already carries
 * `sales_order.view_any`, `sales_invoice.view_any` and `delivery_challan.view_any`.
 *
 * So the first `portal_customer` user created would read **every** customer's orders and
 * invoices through the ordinary routes — a cross-tenant leak created by an administrator doing
 * something the UI openly offers. The portal itself is a one-route stub with no data, which is
 * the only reason this is not live today; the role is what makes it reachable.
 *
 * The guard sits on the single choke point both the create form and the role-change action use,
 * so it cannot be walked around by picking the other screen.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->portalRole = Role::query()->where('name', 'portal_customer')->firstOrFail();
    $this->ordinaryRole = Role::query()->where('name', 'merchandiser')->firstOrFail();
});

it('portal: refuses to create a user holding the portal customer role', function (): void {
    $before = User::query()->count();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'QA Portal Contact',
        'email' => 'qa-portal@octapussolution.com',
        'password' => 'correct-horse-battery-9',
        'password_confirmation' => 'correct-horse-battery-9',
        'locale' => 'en',
        'is_active' => true,
        'role_id' => $this->portalRole->id,
    ])->assertSessionHasErrors('role_id');

    // Refused outright — no user, no role row.
    expect(User::query()->count())->toBe($before)
        ->and(User::query()->where('email', 'qa-portal@octapussolution.com')->exists())->toBeFalse();
});

it('portal: refuses to move an existing user onto the portal customer role', function (): void {
    $user = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $before = $user->roleNames();

    $this->actingAs($this->admin)
        ->post("/admin/users/{$user->id}/roles", ['role_id' => $this->portalRole->id])
        ->assertSessionHasErrors('role_id');

    expect($user->fresh()->roleNames())->toBe($before)
        ->and(DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role_id', $this->portalRole->id)
            ->exists())->toBeFalse();
});

it('portal: still allows every other role to be assigned', function (): void {
    $before = User::query()->count();

    $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'QA Ordinary User',
        'email' => 'qa-ordinary@octapussolution.com',
        'password' => 'correct-horse-battery-9',
        'password_confirmation' => 'correct-horse-battery-9',
        'locale' => 'en',
        'is_active' => true,
        'role_id' => $this->ordinaryRole->id,
    ])->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe($before + 1);
});

it('portal: no portal customer user exists in the seeded system', function (): void {
    expect(DB::table('user_roles')->where('role_id', $this->portalRole->id)->count())->toBe(0);
});
