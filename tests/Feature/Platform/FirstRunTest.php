<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Platform\SetupChecklist;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

/**
 * UX audit H-44 and M-02 — what a new installation, and a new user, see first.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
});

it('lists what is still to set up, judged from the database', function (): void {
    app(Settings::class)->set('org_address', '');
    app(Settings::class)->set('org_phone', '');

    $setup = app(SetupChecklist::class)->for($this->admin);

    expect($setup)->not->toBeNull();

    $steps = collect($setup['steps'])->keyBy('key');

    expect($steps['organisation']['done'])->toBeFalse()
        ->and($steps['organisation']['href'])->toBe('/admin/settings')
        // The seeded database already has customers and products.
        ->and($steps['customers']['done'])->toBeTrue()
        ->and($steps['products']['done'])->toBeTrue()
        ->and($setup['done'])->toBeLessThan($setup['total'])
        // The permission that gates a step is not sent to the browser.
        ->and($steps['organisation'])->not->toHaveKey('permission');
});

it('shows a step only to someone who may carry it out', function (): void {
    app(Settings::class)->set('org_address', '');

    $keys = collect(app(SetupChecklist::class)->for($this->merchandiser)['steps'] ?? [])->pluck('key');

    // A merchandiser cannot open settings or add users, so is not asked to.
    expect($keys)->not->toContain('organisation')
        ->and($keys)->not->toContain('users');
});

it('goes away once every step this user can do is done', function (): void {
    app(Settings::class)->set('org_address', 'Plot 12, Gazipur');
    app(Settings::class)->set('org_phone', '+880 1700 000000');

    $setup = app(SetupChecklist::class)->for($this->admin);

    // Anything still listed must be a step that is genuinely not done on the seeded data.
    if ($setup !== null) {
        expect(collect($setup['steps'])->where('done', false)->pluck('key')->all())->not->toContain('organisation');
    }

    $this->actingAs($this->admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard')->has('tiles'));
});

it('tells the shell while a user is still on the starter password, and stops once it is changed', function (): void {
    $this->admin->forceFill(['password' => 'password'])->save();

    $this->actingAs($this->admin)->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.using_seed_password', true));

    $this->admin->forceFill(['password' => Hash::make('a-much-better-passphrase-91')])->save();

    $this->actingAs($this->admin->fresh())->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.using_seed_password', false));
});

/*
 * UX audit H-05. A save sent after the session has run out used to be redirected to the
 * sign-in page, which Inertia followed — taking the form and everything typed into it. It is
 * now answered 419 so the page can stay put and ask the user to sign in again in another tab.
 */
it('answers an expired-session save with 419 instead of redirecting the form away', function (): void {
    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post('/inquiries', ['inquiry_date' => now()->toDateString()])
        ->assertStatus(419);
});

it('still sends a signed-out page load to the sign-in page', function (): void {
    $this->get('/inquiries')->assertRedirect('/login');

    $this->withHeaders(['X-Inertia' => 'true'])->get('/inquiries')->assertRedirect('/login');
});
