<?php

declare(strict_types=1);

/**
 * The terminal installs to a tablet's home screen and survives a reload with no link.
 *
 * The offline queue already kept four hours of writes across an outage; the application that
 * produces them did not survive a refresh. These are the pieces a browser fetches for itself,
 * before and outside any session — so the thing most worth asserting is that none of them sit
 * behind `auth`, and that the worker names files that actually exist.
 */
it('serves the web app manifest to a browser with no session', function (): void {
    $response = $this->get('/floor/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    $manifest = $response->json();

    // Scope is what makes it an application rather than a bookmark: opened from the home
    // screen, `/floor` and everything under it stays inside it.
    expect($manifest['scope'])->toBe('/floor')
        ->and($manifest['start_url'])->toBe('/floor')
        // A kiosk beside a loom has no use for a URL bar, and an operator wearing gloves
        // should not be able to reach one.
        ->and($manifest['display'])->toBe('fullscreen');

    // Chrome installs nothing without both sizes, and drops the mark into a circle on Android
    // unless something declares itself maskable.
    $icons = collect($manifest['icons']);

    expect($icons->pluck('sizes'))->toContain('192x192', '512x512')
        ->and($icons->pluck('purpose'))->toContain('maskable');

    foreach ($icons->pluck('src') as $src) {
        expect(public_path(ltrim((string) $src, '/')))->toBeFile();
    }
});

it('serves the service worker unauthenticated, from a path that can claim /floor', function (): void {
    $response = $this->get('/floor/sw.js')->assertOk();

    // A worker may only control paths below the directory it is served from — the whole
    // reason this is a route and not something Vite emitted under /build.
    expect($response->headers->get('Content-Type'))->toStartWith('application/javascript')
        // Cached for even an hour, this is a deploy that does not reach the floor for an hour.
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        /*
         * Without this the browser caps the worker at `/floor/` — with the trailing slash —
         * and refuses to register it for `/floor`, which is the badge screen and the
         * manifest's own start_url. Registration then fails with a SecurityError that the
         * terminal has no way to show, so it works perfectly until the wifi drops. It did
         * exactly that on the first build of this feature.
         */
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/floor');
});

it('precaches the built assets the floor screens actually need', function (): void {
    $body = $this->get('/floor/sw.js')->assertOk()->getContent();

    expect($body)->toMatch('/const PRECACHE = \[/');

    preg_match('/const PRECACHE = (\[.*?\]);/s', (string) $body, $matches);

    /** @var list<string> $precache */
    $precache = json_decode($matches[1], true);

    // Every entry is a file on disk. A worker that installs a list with one bad URL fails the
    // whole install and silently leaves the terminal exactly as broken as it was before.
    foreach ($precache as $file) {
        expect(public_path(ltrim($file, '/')))->toBeFile();
    }

    // All three floor screens, including the ones the operator has not opened: the first tap
    // after an outage begins is precisely when the operation screen is lazily loaded.
    $names = implode(' ', $precache);

    expect($names)->toContain('Queue-')
        ->and($names)->toContain('Operation-')
        ->and($names)->toContain('Login-')
        // The stylesheet and the Bengali face — the terminal is bilingual and a fallback font
        // renders the Bangla half as empty boxes.
        ->and($names)->toContain('.css')
        ->and($names)->toContain('noto-sans-bengali');
});

it('offers the manifest on the floor and nowhere else', function (): void {
    $this->get('/floor')->assertOk()->assertSee('/floor/manifest.webmanifest', escape: false);

    // An accountant's browser must not offer to install a machine terminal.
    $this->actingAs(App\Models\User::where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('manifest.webmanifest', escape: false);
});
