<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Routes exposed to the frontend
|--------------------------------------------------------------------------
|
| Route names are not credentials — every route is authorised server-side no
| matter what the client knows about it. But Ziggy embeds its list in the HTML
| of every page, and there is no reason to hand a machine operator the complete
| admin and delete surface with each page load.
|
| Nothing in `resources/js` calls Ziggy's `route()` helper (links are literal
| paths), so this list is purely what the page advertises about itself.
|
*/

return [
    'except' => [
        'admin.*',
        '*.destroy',
        'api.*',
        'floor.*',
        'portal.*',
    ],
];
