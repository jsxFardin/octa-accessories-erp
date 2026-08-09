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
}
