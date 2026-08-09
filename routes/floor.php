<?php

use App\Modules\Manufacturing\Http\Controllers\FloorTerminalController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-floor terminal. FloorLayout — four buttons, gloves, glare (08-architecture §4).
 */

Route::prefix('floor')->name('floor.')->group(function (): void {
    Route::get('/', [FloorTerminalController::class, 'index'])->name('index');

    Route::middleware(['auth'])->group(function (): void {
        Route::get('queue', [FloorTerminalController::class, 'queue'])->name('queue');
        Route::get('operations/{operation}', [FloorTerminalController::class, 'operation'])->name('operation');
    });
});
