<?php

use Illuminate\Support\Facades\Route;

/*
 * Customer portal (Module 15). A route group with its own guard and a customer global
 * scope — not a separate application (AD-1). Built out in Phase 4; the group exists now
 * so the isolation middleware has a home and cannot be forgotten later.
 */

Route::middleware(['auth', 'portal'])->group(function (): void {
    Route::get('/', fn () => inertia('Portal/Dashboard'))->name('dashboard');
});
