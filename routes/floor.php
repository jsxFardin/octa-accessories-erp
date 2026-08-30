<?php

use App\Modules\Manufacturing\Http\Controllers\FloorTerminalController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-floor terminal. FloorLayout — four buttons, gloves, glare (08-architecture §4).
 */

Route::prefix('floor')->name('floor.')->group(function (): void {
    Route::get('/', [FloorTerminalController::class, 'index'])->name('index');

    // `operation.terminal` (AppServiceProvider) — whoever may run or inspect an operation.
    // These were `auth` only, which made the operation screen a way for any authenticated
    // employee to read a job card they are refused on `/job-cards/{id}`.
    Route::middleware(['auth', 'can:operation.terminal'])->group(function (): void {
        Route::get('queue', [FloorTerminalController::class, 'queue'])->name('queue');
        Route::get('operations/{operation}', [FloorTerminalController::class, 'operation'])->name('operation');
    });
});
