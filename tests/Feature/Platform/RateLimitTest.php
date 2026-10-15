<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * GAP S-06 / NFR-27 — 60 writes a minute per user on the browser, 120 requests a minute per
 * device token on the device API.
 *
 * The limits are lifted under test by default (the suite runs hundreds of requests a minute as
 * one seeded user through one array cache); these tests switch them on to prove they bite.
 */
beforeEach(function (): void {
    config(['throttle.enforce_in_tests' => true]);
});

it('answers the 61st write in a minute from one user with 429, and never counts reads', function (): void {
    $lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->actingAs($lab);

    foreach (range(1, 60) as $i) {
        $this->put('/profile/locale', ['locale' => 'en'])->assertRedirect();
    }

    $this->put('/profile/locale', ['locale' => 'en'])->assertStatus(429);

    // Reads are not writes.
    $this->get('/profile')->assertOk();
});

it('answers the 121st device request in a minute from one token with 429', function (): void {
    $card = (string) DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $token = $this->postJson('/api/v1/device/session', ['card_no' => $card, 'pin' => substr($card, -4)])
        ->assertCreated()->json('token');

    foreach (range(1, 120) as $i) {
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/session')->assertOk();
    }

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/device/session')->assertStatus(429);
});

it('answers the 11th badge sign-in attempt in a minute from one address with 429', function (): void {
    $card = (string) DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'supervisor@octapussolution.com')
        ->value('card_no');

    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/device/session', ['card_no' => $card, 'pin' => substr($card, -4)])->assertCreated();
    }

    $this->postJson('/api/v1/device/session', ['card_no' => $card, 'pin' => substr($card, -4)])->assertStatus(429);
});
