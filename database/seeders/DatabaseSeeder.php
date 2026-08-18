<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            UserSeeder::class,
        ]);

        // Demo data is opt-in. A production install seeds the lookups and the users and
        // nothing else; local and the test suite get the walkthrough order as well.
        if (app()->environment('local') || app()->runningUnitTests()) {
            $this->call(DemoDataSeeder::class);
        }

        // 100 journeys across the full process — local only. Tests stay on the single
        // walkthrough order so they stay fast and deterministic.
        if (app()->environment('local')) {
            $this->call(LocalProcessSeeder::class);
        }
    }
}
