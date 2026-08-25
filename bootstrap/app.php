<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Support\Http\LandingPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/floor.php'));

            Illuminate\Support\Facades\Route::middleware('web')
                ->prefix('portal')
                ->name('portal.')
                ->group(base_path('routes/portal.php'));

            /*
             * Registered after every route file on purpose — a fallback matches anything, so
             * anything registered later would be unreachable.
             *
             * Why it exists: an unmatched URL 404s during routing, before the web middleware
             * group runs, so the exception renderer below has no session and no user and the
             * error page treated every signed-in person as a guest. The fallback runs the
             * full web stack, so the 404 page knows who you are and where your home is.
             */
            Illuminate\Support\Facades\Route::fallback(
                fn () => Inertia::render('Error', [
                    'status' => 404,
                    'home' => LandingPage::for(auth()->user()),
                ])->toResponse(request())->setStatusCode(404),
            )->middleware('web');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'device' => App\Http\Middleware\EnsureDeviceSession::class,
            'portal' => App\Http\Middleware\EnsurePortalCustomer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * A bare "403 This action is unauthorized" on a blank page is a dead end: no
         * navigation, no sign-out, nothing to click. These render through Inertia with the
         * app chrome and a link to somewhere the user is actually allowed to be.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            if (! in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }

            /*
             * `auth` is passed explicitly: an unmatched route 404s during routing, before
             * the web middleware group runs, so HandleInertiaRequests::share() never fires
             * and the error page would treat every signed-in user as a guest — offering
             * "Sign in" to someone who already is.
             */
            $user = $request->user();

            return Inertia::render('Error', [
                'status' => $response->getStatusCode(),
                'home' => LandingPage::for($user),
                'auth' => [
                    'user' => $user === null ? null : [
                        'id' => $user->id,
                        'name' => $user->name,
                        'roles' => $user->roleNames(),
                    ],
                ],
            ])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
