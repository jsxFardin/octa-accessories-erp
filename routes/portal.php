<?php

use Illuminate\Support\Facades\Route;

/*
 * Customer portal (Module 15). A route group with its own guard and a customer global
 * scope — not a separate application (AD-1). Built out in Phase 4; the group exists now
 * so the isolation middleware has a home and cannot be forgotten later.
 *
 * The customer global scope (`App\Support\Scoping\BelongsToCustomer`) exists and confines
 * every order, quotation, inquiry, invoice, credit note, receipt, return, packing list,
 * delivery note and test report to the customer `EnsurePortalCustomer` binds — but only on
 * requests that pass through this group. The `portal_customer` role is still withheld by the
 * user form: it carries `*.view_any` permissions that the desk routes honour without the
 * scope, so a portal contact must never be able to reach the desk routes at all. That needs
 * portal data routes of their own with cross-customer tests behind them (NFR-31) before the
 * role is handed out.
 */

Route::middleware(['auth', 'portal'])->group(function (): void {
    Route::get('/', fn () => inertia('Portal/Dashboard'))->name('dashboard');
});
