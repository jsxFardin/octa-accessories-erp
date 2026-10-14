<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * `migrate:fresh` drops tables but leaves views behind, and the schema migration then
     * fails on `CREATE VIEW v_stock_balances`. The four views are part of the schema
     * (02-database-schema §4), so they have to go with it.
     */
    protected bool $dropViews = true;

    /**
     * Seed once per run, not once per test.
     *
     * RefreshDatabase migrates and seeds a single time, then wraps each test in a transaction
     * that rolls back. Loading 129 tables and the full reference data per test turns a
     * three-second suite into a four-minute one.
     */
    protected bool $seed = true;

    /**
     * Switching persona mid-test is a new sign-in, not a hijack.
     *
     * `AuthenticateSession` (NFR-26) signs a session out when the account's password hash no
     * longer matches the one the session was opened with. `actingAs()` swaps the user but keeps
     * the test's session, so the second persona would be compared against the first one's hash
     * and bounced to the login page. A real sign-in starts from a session without that key, and
     * so does this.
     */
    public function be(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->app['session.store']->forget('password_hash_'.($guard ?? config('auth.defaults.guard')));

        return parent::be($user, $guard);
    }
}
