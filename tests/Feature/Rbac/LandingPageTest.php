<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Http\LandingPage;

/**
 * Signing in must not end on a 403.
 *
 * Every role authenticates, then goes somewhere it is allowed to be. A hardcoded redirect to
 * the dashboard locks out four roles that do not hold `report.dashboard` — they log in
 * successfully and land on a page with no navigation and no way back.
 */
it('lands every seeded role on a page they can actually open', function (): void {
    $failures = [];

    foreach (User::query()->with('roles')->get() as $user) {
        $landing = LandingPage::for($user);

        $response = $this->actingAs($user)->get($landing);

        if ($response->getStatusCode() >= 400) {
            $failures[] = "{$user->email} → {$landing} = {$response->getStatusCode()}";
        }

        auth()->logout();
    }

    expect($failures)->toBe([]);
});

it('sends an operator to the floor terminal, not the dashboard', function (): void {
    $operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();

    expect(LandingPage::for($operator))->toBe('/floor');
});

it('sends a driver to their trips', function (): void {
    $driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();

    expect(LandingPage::for($driver))->toBe('/trips');
});

it('lets the auditor see the read-only dashboard', function (): void {
    $auditor = User::query()->where('email', 'auditor@maheenlabel.test')->firstOrFail();

    // An auditor is precisely who a read-only overview is for.
    expect($auditor->hasPermission('report.dashboard'))->toBeTrue()
        ->and(LandingPage::for($auditor))->toBe('/dashboard');

    $this->actingAs($auditor)->get('/dashboard')->assertOk();
});

it('follows the post-login redirect through to a usable page', function (): void {
    $this->post('/login', [
        'email' => 'operator@maheenlabel.test',
        'password' => 'password',
    ])->assertRedirect('/floor');
});

it('renders a way out instead of a bare 403', function (): void {
    $operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();

    $response = $this->actingAs($operator)->get('/sales-orders');

    $response->assertForbidden();

    // The page carries the app's error component with a home link, not a blank string.
    expect($response->getContent())->toContain('Error')->toContain('/floor');
});
