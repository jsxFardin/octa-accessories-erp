<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/**
 * GAP S-07 — NFR-19: self-service password reset, with the same password rule as everywhere.
 */
beforeEach(function (): void {
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
});

it('sends a reset link to an account address and answers the same for an unknown one', function (): void {
    Notification::fake();

    $this->post('/forgot-password', ['email' => $this->lab->email])->assertSessionHas('success');
    Notification::assertSentTo($this->lab, ResetPassword::class);

    $this->post('/forgot-password', ['email' => 'nobody@octapussolution.com'])->assertSessionHas('success');
    Notification::assertCount(1);
});

it('changes the password with a valid token and cycles the remember token', function (): void {
    $this->lab->forceFill(['remember_token' => 'old-remember'])->save();
    $token = Password::broker()->createToken($this->lab);

    $this->get("/reset-password/{$token}?email={$this->lab->email}")->assertOk();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $this->lab->email,
        'password' => 'correct-horse-battery-2026',
        'password_confirmation' => 'correct-horse-battery-2026',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    $fresh = $this->lab->fresh();

    expect(Hash::check('correct-horse-battery-2026', $fresh->password))->toBeTrue()
        ->and($fresh->remember_token)->not->toBe('old-remember');
});

it('refuses a bad token and a weak password', function (): void {
    $this->post('/reset-password', [
        'token' => 'not-a-token', 'email' => $this->lab->email,
        'password' => 'correct-horse-battery-2026', 'password_confirmation' => 'correct-horse-battery-2026',
    ])->assertSessionHasErrors('email');

    $token = Password::broker()->createToken($this->lab);

    $this->post('/reset-password', [
        'token' => $token, 'email' => $this->lab->email, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $this->lab->fresh()->password))->toBeTrue();
});

it('hashes with Argon2id and upgrades a bcrypt hash on login', function (): void {
    expect(Hash::make('anything-at-all'))->toStartWith('$argon2id$');

    // Written past the `hashed` cast, which refuses to store a bcrypt hash under the Argon2id driver.
    DB::table('users')->where('id', $this->lab->id)->update(['password' => password_hash('password', PASSWORD_BCRYPT)]);

    $this->post('/login', ['email' => $this->lab->email, 'password' => 'password'])->assertRedirect();

    expect($this->lab->fresh()->password)->toStartWith('$argon2id$');
});
