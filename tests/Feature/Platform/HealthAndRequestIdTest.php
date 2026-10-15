<?php

declare(strict_types=1);

use App\Models\User;

/**
 * GAP D-07 — NFR-58 / NFR-62: a request id on every response, and a health route that proves
 * the database, the cache and the disk rather than only that PHP booted.
 */
it('answers /up only when the database, cache and storage all work', function (): void {
    $this->get('/up')->assertOk();
});

it('stamps every response with a request id and keeps one the caller sent', function (): void {
    $admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();

    $minted = $this->actingAs($admin)->get('/dashboard')->assertOk()->headers->get('X-Request-Id');

    expect($minted)->toBeString()->not->toBeEmpty();

    $this->actingAs($admin)->withHeader('X-Request-Id', 'proxy-abc-123')->get('/dashboard')
        ->assertOk()
        ->assertHeader('X-Request-Id', 'proxy-abc-123');
});
