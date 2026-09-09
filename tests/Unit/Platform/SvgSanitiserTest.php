<?php

declare(strict_types=1);

use App\Support\Platform\SvgSanitiser;

/**
 * The branding slot accepts SVG and stores it on the `public` disk, where the web server hands
 * it back verbatim. Through `<img src>` — how the print layout uses it — script inside cannot
 * run; navigated to directly, `/storage/branding/x.svg` is a document on the application's own
 * origin and it can.
 */
beforeEach(function (): void {
    $this->svg = new SvgSanitiser;
});

it('svg: removes a script element', function (): void {
    $cleaned = $this->svg->clean(
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script><rect width="10" height="10"/></svg>',
    );

    expect($cleaned)->not->toContain('script')
        ->and($cleaned)->not->toContain('alert')
        // The drawing survives: a sanitiser that eats the logo is one nobody may use.
        ->and($cleaned)->toContain('rect');
});

it('svg: removes event handler attributes', function (): void {
    $cleaned = $this->svg->clean(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)" onclick="alert(2)" width="10" height="10" fill="red"/></svg>',
    );

    expect($cleaned)->not->toContain('onload')
        ->and($cleaned)->not->toContain('onclick')
        ->and($cleaned)->toContain('fill="red"');
});

it('svg: removes a foreignObject, which is how HTML gets smuggled in', function (): void {
    $cleaned = $this->svg->clean(
        '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>',
    );

    expect($cleaned)->not->toContain('foreignObject')
        ->and($cleaned)->not->toContain('alert');
});

it('svg: drops a javascript: href but keeps a fragment reference', function (): void {
    $cleaned = $this->svg->clean(
        '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
        .'<a xlink:href="javascript:alert(1)"><rect width="10" height="10"/></a>'
        .'<use xlink:href="#shape"/>'
        .'</svg>',
    );

    expect($cleaned)->not->toContain('javascript:')
        // Internal references are the whole point of `use` and gradients.
        ->and($cleaned)->toContain('#shape');
});

it('svg: strips javascript inside a style attribute', function (): void {
    $cleaned = $this->svg->clean(
        '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(javascript:alert(1))" width="10" height="10"/></svg>',
    );

    expect($cleaned)->not->toContain('javascript:');
});

it('svg: leaves an ordinary logo alone', function (): void {
    // The rule must not start mangling the vector logos it exists to keep.
    $original = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
        .'<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient></defs>'
        .'<path d="M10 10 H 90 V 90 H 10 Z" fill="url(#g)"/>'
        .'</svg>';

    $cleaned = $this->svg->clean($original);

    expect($cleaned)->toContain('linearGradient')
        ->and($cleaned)->toContain('viewBox="0 0 100 100"')
        ->and($cleaned)->toContain('M10 10 H 90 V 90 H 10 Z')
        ->and($cleaned)->toContain('url(#g)');
});

it('svg: refuses something it could not parse rather than storing it unread', function (): void {
    expect($this->svg->clean('<svg><unclosed></svg>'))->toBe('')
        ->and($this->svg->clean('this is not markup at all'))->toBe('');
});

it('svg: does not fetch a URL or unfold an entity while parsing', function (): void {
    // XXE and billion-laughs: an uploaded file must not be able to make the server read a
    // local path or expand its way through memory during the parse.
    $cleaned = $this->svg->clean(
        '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg"><text>&xxe;</text></svg>',
    );

    expect($cleaned)->not->toContain('root:');
});
