<?php

declare(strict_types=1);

return [
    /*
     * Where `assertInertia(...)->component('Admin/Settings')` looks for the page file.
     *
     * The finder reads `pages.paths` / `pages.extensions` (ServiceProvider), not the
     * `testing.*` keys the published stub suggests — without these, every component assertion
     * fails with "page component file does not exist", which is a configuration failure
     * masquerading as a failure about the code under test.
     */
    'pages' => [
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['vue'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],
];
