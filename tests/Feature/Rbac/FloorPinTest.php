<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 06-rbac §6 — the floor PIN is a secret, not a function of the badge.
 *
 * It used to be the badge's last four characters, and the badge is printed on the card an
 * operator wears on their chest. Reading it over a shoulder was enough to sign in as them and
 * book their output, their waste and their downtime — which makes every production record on
 * the floor unattributable to anyone in particular.
 */
it('rejects a PIN derived from the badge once a real one is set', function (): void {
    $employee = DB::table('employees')->whereNotNull('card_no')->firstOrFail();
    $derived = substr((string) $employee->card_no, -4);

    DB::table('employees')->where('id', $employee->id)->update(['pin_hash' => Hash::make('820461')]);

    $this->postJson('/api/v1/device/session', [
        'card_no' => $employee->card_no,
        'pin' => $derived,
    ])->assertUnauthorized();

    $this->postJson('/api/v1/device/session', [
        'card_no' => $employee->card_no,
        'pin' => '820461',
    ])->assertCreated();
});

it('refuses a badge with no PIN set at all', function (): void {
    $employee = DB::table('employees')->whereNotNull('card_no')->firstOrFail();

    DB::table('employees')->where('id', $employee->id)->update(['pin_hash' => null]);

    $this->postJson('/api/v1/device/session', [
        'card_no' => $employee->card_no,
        'pin' => substr((string) $employee->card_no, -4),
    ])->assertUnauthorized();
});

it('stores the PIN as a hash when an administrator sets one', function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $user = User::query()->whereHas('employee')->firstOrFail();
    $employee = DB::table('employees')->where('user_id', $user->id)->firstOrFail();

    $this->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'locale' => $user->locale ?? 'en',
        'is_active' => true,
        'role_id' => $user->roles()->value('roles.id'),
        'employee_code' => $employee->code,
        'card_no' => $employee->card_no,
        'floor_pin' => '735104',
        'factory_unit_id' => $employee->factory_unit_id,
        'department_id' => $employee->department_id,
    ])->assertSessionHasNoErrors();

    $stored = (string) DB::table('employees')->where('id', $employee->id)->value('pin_hash');

    expect($stored)->not->toBe('735104')
        ->and(Hash::check('735104', $stored))->toBeTrue();
});
