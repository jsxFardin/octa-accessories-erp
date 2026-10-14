<?php

use Illuminate\Support\Facades\Route;

/*
 * Device API — shop-floor scanners and the driver screen (07-api-contracts §2).
 * Offline-tolerant: every write carries an Idempotency-Key and an occurred_at stamp.
 */

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // Same throttle as the browser badge screen: a four-digit PIN against a badge number worn
    // on a lanyard is guessable at machine speed if nothing limits the attempts.
    Route::post('device/session', [App\Modules\Manufacturing\Http\Controllers\Api\DeviceSessionController::class, 'store'])
        ->middleware('throttle:badge')
        ->name('device.session.store');

    Route::middleware('device')->group(function (): void {
        Route::get('device/session', [App\Modules\Manufacturing\Http\Controllers\Api\DeviceSessionController::class, 'show'])
            ->name('device.session.show');

        Route::get('floor/queue', [App\Modules\Manufacturing\Http\Controllers\Api\FloorQueueController::class, 'index'])
            ->name('floor.queue');

        // The four operator permissions (06-rbac §6). `device` proves who holds the badge;
        // these say what that person may do with it. Without them any active employee with a
        // user account and a PIN — a driver, an accountant — could book output.
        Route::post('operations/{operation}/start', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'start'])
            ->middleware('can:operation.start')
            ->name('operations.start');
        Route::post('operations/{operation}/log', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'log'])
            ->middleware('can:operation.log')
            ->name('operations.log');
        Route::post('operations/{operation}/finish', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'finish'])
            ->middleware('can:operation.finish')
            ->name('operations.finish');
        Route::post('operations/{operation}/downtime', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'downtime'])
            ->middleware('can:downtime.create')
            ->name('operations.downtime');
        // MF-4 AC2/AC3 — a stop that is still going: the step is paused with its reason and
        // the downtime stays open until START resumes it. The live view reads the open row.
        Route::post('operations/{operation}/pause', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'pause'])
            ->middleware('can:downtime.create')
            ->name('operations.pause');
    });
});
