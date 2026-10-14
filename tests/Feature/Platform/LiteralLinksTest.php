<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Links in the pages are literal paths (no Ziggy), so a renamed route leaves a dead link
 * nothing compiles against. Every literal path a page links to has to start the way some
 * registered route does. Found by hand once: three pages linked `/sales-invoices`, a route
 * that never existed; the invoice list is `/invoices`.
 */
it('links only to paths some route answers', function (): void {
    $prefixes = collect(Route::getRoutes())
        ->map(fn ($route): string => '/'.explode('/', $route->uri())[0])
        ->unique()
        ->filter(fn (string $p): bool => $p !== '/' && $p !== '/{fallbackPlaceholder}')
        ->values();

    $dead = [];

    foreach (File::allFiles(resource_path('js/Pages')) as $file) {
        $source = $file->getContents();

        // href="/x/…", `/x/…` in template literals, and router.get('/x', …) / form.post('/x', …).
        preg_match_all('#(?:href=["\']|href="`|router\.\w+\(["\'`]|form\.\w+\(["\'`]|\.visit\(["\'`])(/[a-z][a-z0-9-]*)#', $source, $matches);

        foreach (array_unique($matches[1]) as $path) {
            if (! $prefixes->contains($path)) {
                $dead[] = str_replace(resource_path('js/Pages').'/', '', $file->getPathname()).': '.$path;
            }
        }
    }

    expect($dead)->toBe([]);
});
