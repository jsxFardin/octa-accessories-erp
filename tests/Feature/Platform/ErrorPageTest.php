<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * The error page must know who you are.
 *
 * An unmatched URL used to 404 during routing — before the web middleware group ran — so the
 * Inertia error page received no auth and offered "Sign in" to someone already signed in.
 * The fallback route runs the full web stack; these tests pin both 404 paths.
 */
it('knows the signed-in user on an unmatched URL', function (): void {
    $user = User::query()->firstOrFail();

    $this->actingAs($user)
        ->get('/definitely-not-a-route')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->whereNot('auth.user', null));
});

it('knows the signed-in user on a missing record', function (): void {
    $user = User::query()->firstOrFail();

    $this->actingAs($user)
        ->get('/sales-orders/999999')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('status', 404)
            ->whereNot('auth.user', null));
});

it('still treats a guest as a guest', function (): void {
    $this->get('/definitely-not-a-route')
        ->assertStatus(404)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Error')
            ->where('auth.user', null));
});
