<?php

use Illuminate\Support\Facades\Route;

/*
 * Device API — shop-floor scanners and the driver screen (07-api-contracts §2).
 * Offline-tolerant: every write carries an Idempotency-Key and an occurred_at stamp.
 */

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('device/session', [App\Modules\Manufacturing\Http\Controllers\Api\DeviceSessionController::class, 'store'])
        ->name('device.session.store');

    Route::middleware('device')->group(function (): void {
        Route::get('device/session', [App\Modules\Manufacturing\Http\Controllers\Api\DeviceSessionController::class, 'show'])
            ->name('device.session.show');

        Route::get('floor/queue', [App\Modules\Manufacturing\Http\Controllers\Api\FloorQueueController::class, 'index'])
            ->name('floor.queue');

        Route::post('operations/{operation}/start', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'start'])
            ->name('operations.start');
        Route::post('operations/{operation}/log', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'log'])
            ->name('operations.log');
        Route::post('operations/{operation}/finish', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'finish'])
            ->name('operations.finish');
        Route::post('operations/{operation}/downtime', [App\Modules\Manufacturing\Http\Controllers\Api\OperationEventController::class, 'downtime'])
            ->name('operations.downtime');
    });
});
