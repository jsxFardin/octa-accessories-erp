<?php

use App\Modules\Manufacturing\Http\Controllers\FloorTerminalController;
use App\Modules\Manufacturing\Http\Controllers\ServiceWorkerController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-floor terminal. FloorLayout — four buttons, gloves, glare (08-architecture §4).
 */

Route::prefix('floor')->name('floor.')->group(function (): void {
    Route::get('/', [FloorTerminalController::class, 'index'])->name('index');

    /*
     * Installability. Both are fetched by the browser itself, before and outside any session:
     * the manifest whenever the badge screen is opened, the service worker on registration and
     * again every time the browser checks it for an update. Neither may sit behind `auth`.
     *
     * The worker is a route rather than a file in `public/` because it needs the hashed asset
     * names Vite writes at build time, and because a service worker may only control the paths
     * below the directory it is served from — a bundled `/build/floor-sw.js` could never claim
     * `/floor`.
     */
    Route::get('manifest.webmanifest', [FloorTerminalController::class, 'manifest'])->name('manifest');
    Route::get('sw.js', ServiceWorkerController::class)->name('service-worker');

    /*
     * Badge and PIN. Throttled because a PIN is four digits and a badge number is worn on a
     * lanyard in plain sight: without a limit the pair is guessable at machine speed.
     */
    Route::post('session', [FloorTerminalController::class, 'signIn'])
        ->middleware('throttle:10,1')
        ->name('session.store');

    // `operation.terminal` (AppServiceProvider) — whoever may run or inspect an operation.
    // These were `auth` only, which made the operation screen a way for any authenticated
    // employee to read a job card they are refused on `/job-cards/{id}`.
    Route::middleware(['auth', 'can:operation.terminal'])->group(function (): void {
        Route::post('session/continue', [FloorTerminalController::class, 'continueAsUser'])
            ->name('session.continue');
        Route::get('queue', [FloorTerminalController::class, 'queue'])->name('queue');
        Route::get('operations/{operation}', [FloorTerminalController::class, 'operation'])->name('operation');
    });

    // End of shift is available to whoever is signed in, gate or no gate: someone who landed
    // here without terminal rights still has to be able to get back off the kiosk.
    Route::post('session/end', [FloorTerminalController::class, 'signOut'])
        ->middleware('auth')
        ->name('session.destroy');
});
