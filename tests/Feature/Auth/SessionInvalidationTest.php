<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * GAP S-02 / NFR-26 — a password change ends every other session.
 *
 * `AuthenticateSession` keeps the hash a session was opened with and signs the session out
 * when the account's hash no longer matches. The session that changed the password keeps
 * going; a stolen or shared one is gone on its next request.
 */
beforeEach(function (): void {
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

it('signs a session out once the account password has changed elsewhere', function (): void {
    // First request stores the current hash in the session.
    $this->actingAs($this->lab)->get('/profile')->assertOk();

    // The password is reset behind this session's back (an administrator, another browser).
    $this->lab->forceFill(['password' => 'reset-by-admin-2026'])->save();

    $this->get('/profile')->assertRedirect('/login');
});

it('keeps the session that changed its own password and signs the others out', function (): void {
    $this->actingAs($this->lab)->get('/profile')->assertOk();

    $this->put('/profile/password', [
        'current_password' => 'password',
        'password' => 'correct-horse-battery-2026',
        'password_confirmation' => 'correct-horse-battery-2026',
    ])->assertSessionHasNoErrors()->assertRedirect();

    // This session carries on.
    $this->get('/profile')->assertOk();

    expect(Hash::check('correct-horse-battery-2026', $this->lab->fresh()->password))->toBeTrue();

    // A session opened with the old hash is refused.
    $this->session(['password_hash_web' => 'stale-hash']);
    $this->get('/profile')->assertRedirect('/login');
});

it('cycles the remember token when an administrator resets a password', function (): void {
    $this->lab->forceFill(['remember_token' => 'old-remember-token'])->save();

    $this->actingAs($this->admin)->put("/admin/users/{$this->lab->id}", [
        'name' => $this->lab->name,
        'email' => $this->lab->email,
        'locale' => $this->lab->locale,
        'is_active' => true,
        'role_id' => $this->lab->roles->first()->id,
        'password' => 'reset-by-admin-2026',
        'password_confirmation' => 'reset-by-admin-2026',
    ])->assertSessionHasNoErrors();

    expect($this->lab->fresh()->remember_token)->not->toBe('old-remember-token');
});
