<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia;

/**
 * GAP S-07 — NFR-19: two-step sign-in with an authenticator app, mandatory for md, admin
 * and accounts.
 */
beforeEach(function (): void {
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();
});

function enrol(object $test, User $user): string
{
    $test->actingAs($user)->post('/profile/two-factor')->assertSessionHasNoErrors();
    $secret = Crypt::decryptString((string) $user->fresh()->two_factor_secret);
    $test->post('/profile/two-factor/confirm', ['code' => Totp::code($secret)])->assertSessionHasNoErrors();

    return $secret;
}

it('enrols from the profile page: a secret to add, then a code to confirm', function (): void {
    $this->actingAs($this->lab)->post('/profile/two-factor')->assertSessionHasNoErrors();

    $user = $this->lab->fresh();
    $secret = Crypt::decryptString((string) $user->two_factor_secret);

    expect($user->two_factor_confirmed_at)->toBeNull();

    $this->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('profile.two_factor.enabled', false)
        ->where('profile.two_factor.pending_secret', $secret)
        ->where('profile.two_factor.provisioning_uri', fn ($uri) => str_starts_with($uri, 'otpauth://totp/')));

    $this->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    expect($this->lab->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->post('/profile/two-factor/confirm', ['code' => Totp::code($secret)])->assertSessionHasNoErrors();
    expect($this->lab->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('asks for the code after the password and signs in only on the right one', function (): void {
    $secret = enrol($this, $this->lab);
    $this->post('/logout');

    $this->post('/login', ['email' => $this->lab->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post('/two-factor-challenge', ['code' => Totp::code($secret)])->assertRedirect();
    $this->assertAuthenticatedAs($this->lab);
});

it('cannot reach the challenge without a password step first', function (): void {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
});

it('keeps an md on the profile page until two-step sign-in is set up, and frees them once it is', function (): void {
    // Inside the first week the md is only told; the seeded demo accounts live there.
    $this->actingAs($this->md)->get('/dashboard')->assertOk();
    $this->get('/profile')->assertInertia(fn (AssertableInertia $page) => $page->where('profile.two_factor.days_left', fn ($d) => $d >= 6 && $d <= 7));

    $this->md->forceFill(['created_at' => now()->subDays(8)])->save();

    $this->get('/dashboard')->assertRedirect('/profile');
    $this->get('/profile')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('profile.two_factor.required', true));

    enrol($this, $this->md);

    $this->get('/dashboard')->assertOk();
});

it('leaves a role that does not require it alone, and lets that user turn it off with their password', function (): void {
    $this->actingAs($this->lab)->get('/dashboard');
    $this->get('/profile')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('profile.two_factor.required', false));

    enrol($this, $this->lab);

    $this->delete('/profile/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($this->lab->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->delete('/profile/two-factor', ['password' => 'password'])->assertSessionHasNoErrors();
    expect($this->lab->fresh()->two_factor_secret)->toBeNull();
});

it('refuses to turn it off for a role that requires it', function (): void {
    enrol($this, $this->md);

    $this->delete('/profile/two-factor', ['password' => 'password'])->assertSessionHas('error');
    expect($this->md->fresh()->two_factor_confirmed_at)->not->toBeNull();
});
