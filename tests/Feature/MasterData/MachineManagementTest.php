<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** GAP E-04 — the machine register's writes had no behavioural test. */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $this->payload = fn (array $overrides = []): array => [
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => DB::table('machine_groups')->value('id'),
        'code' => 'LOOM-T-01',
        'name' => 'Test needle loom',
        'web_width_mm' => 260,
        'max_colours' => 6,
        'hourly_rate' => 450,
        'kw_rating' => 7.5,
        'efficiency_pct' => 85,
        'status' => 'available',
        ...$overrides,
    ];
});

it('registers a machine, edits it, and refuses a duplicate code', function (): void {
    $this->post('/machines', ($this->payload)())->assertSessionHasNoErrors();

    $machine = DB::table('machines')->where('code', 'LOOM-T-01')->first();

    expect($machine)->not->toBeNull()
        ->and((float) $machine->hourly_rate)->toBe(450.0)
        ->and((int) $machine->max_colours)->toBe(6);

    $this->put("/machines/{$machine->id}", ($this->payload)(['name' => 'Renamed loom', 'hourly_rate' => 500]))->assertSessionHasNoErrors();

    expect(DB::table('machines')->where('id', $machine->id)->value('name'))->toBe('Renamed loom')
        ->and((float) DB::table('machines')->where('id', $machine->id)->value('hourly_rate'))->toBe(500.0);

    $this->post('/machines', ($this->payload)(['name' => 'Second with same code']))->assertSessionHasErrors('code');
});

it('keeps the register to people who may edit master data', function (): void {
    $this->actingAs(User::query()->where('email', 'operator@octapussolution.com')->firstOrFail())
        ->post('/machines', ($this->payload)())
        ->assertForbidden();
});
