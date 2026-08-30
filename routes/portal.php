<?php

use Illuminate\Support\Facades\Route;

/*
 * Customer portal (Module 15). A route group with its own guard and a customer global
 * scope — not a separate application (AD-1). Built out in Phase 4; the group exists now
 * so the isolation middleware has a home and cannot be forgotten later.
 *
 * PREREQUISITE BEFORE ANY DATA IS EXPOSED HERE, and before a `portal_customer` user is
 * created on any environment: the customer global scope this group is named for **does not
 * exist yet**. `EnsurePortalCustomer` binds the customer id into `PortalContext`, and nothing
 * reads it — there is no `BelongsToCustomer` scope in the codebase. Meanwhile the
 * `portal_customer` role already carries `sales_order.view_any`, `sales_invoice.view_any` and
 * `delivery_challan.view_any`, and authorisation in this application is permission-only with
 * no row-level filtering, so such a user would read *every* customer's orders and invoices
 * through the main routes. The stub is safe precisely because it is a stub: one static page,
 * no data, and no portal users seeded.
 */

Route::middleware(['auth', 'portal'])->group(function (): void {
    Route::get('/', fn () => inertia('Portal/Dashboard'))->name('dashboard');
});
