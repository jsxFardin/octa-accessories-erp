<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Services\DeviceSessionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * GAP S-04 — a badge locks after five wrong PINs.
 *
 * The route throttle counts per IP and a factory floor shares one NAT address, so it bounds
 * the whole shed rather than one badge. Five wrong PINs lock the badge for fifteen minutes,
 * on both doors: the device API and the browser badge screen.
 */
beforeEach(function (): void {
    $this->card = (string) DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');
    $this->pin = substr($this->card, -4);

    // The badge counter lives in the cache, which outlives a test's transaction.
    RateLimiter::clear('floor-pin:'.strtolower($this->card));
});

it('locks the badge on the device API after five wrong PINs, even for the right one', function (): void {
    foreach (range(1, DeviceSessionRegistry::PIN_ATTEMPTS - 1) as $attempt) {
        $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => '0000'])
            ->assertUnauthorized();
    }

    // The fifth wrong PIN is the one that locks, and says so at once.
    $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => '0000'])
        ->assertStatus(429);

    $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => $this->pin])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('retry_after', fn (int $wait) => $wait > 0 && $wait <= DeviceSessionRegistry::PIN_LOCKOUT_SECONDS);

    // The lockout is per badge: another badge is untouched.
    $other = (string) DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'supervisor@octapussolution.com')
        ->value('card_no');

    $this->postJson('/api/v1/device/session', ['card_no' => $other, 'pin' => substr($other, -4)])
        ->assertCreated();
});

it('locks the badge on the browser screen too and says how long', function (): void {
    foreach (range(1, DeviceSessionRegistry::PIN_ATTEMPTS) as $attempt) {
        $this->post('/floor/session', ['card_no' => $this->card, 'pin' => '0000'])->assertRedirect();
    }

    $this->post('/floor/session', ['card_no' => $this->card, 'pin' => $this->pin])
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Too many wrong PINs'));

    $this->assertGuest();
});

it('clears the count on a correct PIN and lets the badge in once the lockout has passed', function (): void {
    foreach (range(1, DeviceSessionRegistry::PIN_ATTEMPTS - 1) as $attempt) {
        $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => '0000'])
            ->assertUnauthorized();
    }

    // Four wrong, one right: in, and the slate is clean.
    $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => $this->pin])
        ->assertCreated();

    expect(app(DeviceSessionRegistry::class)->isLockedOut($this->card))->toBeFalse();

    // Locked, then the lockout window elapses.
    foreach (range(1, DeviceSessionRegistry::PIN_ATTEMPTS) as $attempt) {
        $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => '0000']);
    }

    expect(app(DeviceSessionRegistry::class)->isLockedOut($this->card))->toBeTrue();

    RateLimiter::clear('floor-pin:'.strtolower($this->card));

    $this->postJson('/api/v1/device/session', ['card_no' => $this->card, 'pin' => $this->pin])
        ->assertCreated();
});
