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
         * A booking the floor rules refuse keeps its English sentence and gains a `code` and
         * the figures behind it, so the terminal can say it in Bangla (`OperationRefused`).
         * The response is otherwise what it always was: 422, with `message`.
         */
        $exceptions->render(function (App\Modules\Manufacturing\Exceptions\OperationRefused $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
                'code' => $exception->refusalCode,
                'params' => $exception->refusalParams,
            ], 422);
        });

        /*
         * A save from someone whose session has run out is answered 419, not with a redirect.
         *
         * An expired session usually fails authentication before it ever reaches the CSRF
         * check, and the stock answer to that is a redirect to the sign-in page — which
         * Inertia follows, replacing the form and everything typed into it. Answering 419
         * instead lets the shell keep the page and ask the user to sign in again in another
         * tab (`SessionExpired.vue`). A page *load* by a signed-out user still redirects.
         */
        $exceptions->render(function (Illuminate\Auth\AuthenticationException $exception, Request $request) {
            if ($request->hasHeader('X-Inertia') && ! $request->isMethod('GET') && ! $request->is('api/*')) {
                return response('Your session has expired.', 419);
            }

            return null;
        });

        /*
         * A bare "403 This action is unauthorized" on a blank page is a dead end: no
         * navigation, no sign-out, nothing to click. These render through Inertia with the
         * app chrome and a link to somewhere the user is actually allowed to be.
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            // 413: a file past the upload limit used to come back as a bare server page.
            if (! in_array($response->getStatusCode(), [403, 404, 413, 419, 429, 500, 503], true)) {
                return $response;
            }

            /*
             * A save that arrives after the session has expired is left as a bare 419.
             *
             * Rendering the error page for it replaced the form the user was filling in, and
             * everything typed into it. Left alone, the response reaches the client as an HTTP
             * exception, which the shell answers with a dialog over the untouched page: sign
             * in again in another tab, then press Save. A page *load* that hits 419 still gets
             * the error page — there is nothing on screen to protect.
             */
            if ($response->getStatusCode() === 419 && $request->hasHeader('X-Inertia') && ! $request->isMethod('GET')) {
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
                /*
                 * A reference for the support ticket. It is derived from where the fault is,
                 * not from the request, so the same bug reported twice carries the same code
                 * and two different bugs never share one.
                 */
                'reference' => $response->getStatusCode() === 500
                    ? strtoupper(substr(hash('crc32b', get_class($exception).'|'.$exception->getFile().'|'.$exception->getLine()), 0, 8))
                    : null,
                // Enough of the shared props for the app shell to draw its sidebar around the page.
                'auth' => [
                    'user' => $user === null ? null : [
                        'id' => $user->id,
                        'name' => $user->name,
                        'roles' => $user->roleNames(),
                        'permissions' => $user->permissionNames(),
                    ],
                ],
                'app' => $user === null ? [] : app(\App\Support\Settings\Organisation::class)->forFrontend(),
            ])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
