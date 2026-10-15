<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * GAP S-03 — no badge ships with a PIN the badge reveals.
 *
 * The demo PIN (the badge's last four digits) is for developer machines and this suite. On
 * UAT and production the seeder leaves `pin_hash` alone, and the Users list marks the badges
 * that still need one so an administrator can see who cannot sign in at the terminal.
 */
it('seeds demo PINs only on local and testing environments', function (): void {
    expect(UserSeeder::seedsDemoPins())->toBeTrue();

    $original = app()->environment();
    app()->detectEnvironment(fn (): string => 'staging');

    try {
        expect(UserSeeder::seedsDemoPins())->toBeFalse();

        // Clear one PIN, re-run the seeder as staging: it must not put a derived PIN back,
        // and it must not blank the PINs that are set.
        $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();
        DB::table('employees')->where('user_id', $operator->id)->update(['pin_hash' => null]);

        $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();
        $kept = DB::table('employees')->where('user_id', $supervisor->id)->value('pin_hash');

        $this->artisan('db:seed', ['--class' => UserSeeder::class])->assertSuccessful();

        expect(DB::table('employees')->where('user_id', $operator->id)->value('pin_hash'))->toBeNull()
            ->and(DB::table('employees')->where('user_id', $supervisor->id)->value('pin_hash'))->toBe($kept);
    } finally {
        app()->detectEnvironment(fn (): string => $original);
    }
});

it('marks badges without a PIN on the Users list', function (): void {
    $admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();
    DB::table('employees')->where('user_id', $operator->id)->update(['pin_hash' => null]);

    $this->actingAs($admin)
        ->get('/admin/users?q=Jahangir')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data.0.email', 'operator@octapussolution.com')
            ->where('users.data.0.has_floor_pin', false));
});
