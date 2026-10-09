<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;

/**
 * M-49 — a filter change asks the server for the list alone.
 *
 * The filter bar names the props it alters (`only`), and Inertia sends that as a partial
 * request. The option lists and counts the page already holds are neither rebuilt nor sent
 * again on every keystroke. The unused Ziggy route table no longer rides on every response.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    // An Inertia request without the current asset version is answered 409 "reload".
    $this->inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
});

it('answers a partial reload with the named props only', function (): void {
    $full = $this->actingAs($this->admin)
        ->withHeaders($this->inertia)
        ->get('/quotations');

    $full->assertOk();
    $fullProps = $full->json('props');

    expect($fullProps)->toHaveKeys(['quotations', 'filters', 'customers']);

    $partial = $this->actingAs($this->admin)
        ->withHeaders([
            ...$this->inertia,
            'X-Inertia-Partial-Component' => 'Sales/Quotations/Index',
            'X-Inertia-Partial-Data' => 'quotations,filters',
        ])
        ->get('/quotations?q=zz');

    $partial->assertOk();
    $props = $partial->json('props');

    expect($props)->toHaveKeys(['quotations', 'filters'])
        ->and($props)->not->toHaveKey('customers')
        ->and($props)->not->toHaveKey('merchandisers');
});

it('no longer shares the Ziggy route table', function (): void {
    $this->actingAs($this->admin)
        ->withHeaders($this->inertia)
        ->get('/dashboard')
        ->assertOk()
        ->assertJsonMissingPath('props.ziggy');
});
