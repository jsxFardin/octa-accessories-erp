<?php

declare(strict_types=1);

use App\Support\Auth\Totp;

/**
 * GAP S-07 — RFC 6238 test vectors (SHA-1, six digits): the shared secret "12345678901234567890"
 * is "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ" in base32.
 */
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('produces the RFC 6238 reference codes', function (): void {
    expect(Totp::code(RFC_SECRET, 59))->toBe('287082')
        ->and(Totp::code(RFC_SECRET, 1111111109))->toBe('081804')
        ->and(Totp::code(RFC_SECRET, 1234567890))->toBe('005924')
        ->and(Totp::code(RFC_SECRET, 2000000000))->toBe('279037');
});

it('accepts a code from the step either side and nothing further', function (): void {
    $now = 1234567890;

    expect(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $now), $now))->toBeTrue()
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $now - 30), $now))->toBeTrue()
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $now + 30), $now))->toBeTrue()
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $now + 90), $now))->toBeFalse()
        ->and(Totp::verify(RFC_SECRET, '12 34 56', $now))->toBeFalse()
        ->and(Totp::verify(RFC_SECRET, 'abcdef', $now))->toBeFalse();
});

it('makes a secret an authenticator can read and a provisioning URI naming the account', function (): void {
    $secret = Totp::generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and(Totp::code($secret))->toMatch('/^\d{6}$/')
        ->and(Totp::provisioningUri($secret, 'md@octapussolution.com', 'Trimflow'))
        ->toStartWith('otpauth://totp/Trimflow:md%40octapussolution.com?secret='.$secret);
});
