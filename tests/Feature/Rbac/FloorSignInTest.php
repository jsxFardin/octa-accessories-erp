<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signing in at the terminal is one act, not two.
 *
 * The badge screen used to trade a card and a PIN for an API token in localStorage and nothing
 * else, then send the operator to `/floor/queue` — a `web` route behind `auth`. On a kiosk
 * browser with no prior session that redirected straight to the password login, so the badge
 * screen only ever worked for someone who had *already* signed in with an email and a password
 * and then scanned a badge on top of it. Every test covering the terminal used `actingAs()`,
 * which is exactly the session the real flow never created.
 */
beforeEach(function (): void {
    $this->operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();
    $this->employee = DB::table('employees')->where('user_id', $this->operator->id)->firstOrFail();
    $this->pin = substr((string) $this->employee->card_no, -4);
});

it('floor: badge and PIN sign the operator into the session, not only into localStorage', function (): void {
    $this->post('/floor/session', [
        'card_no' => $this->employee->card_no,
        'pin' => $this->pin,
    ])->assertRedirect('/floor/queue');

    $this->assertAuthenticatedAs($this->operator);

    // The proof the old flow failed: the queue answers a guest browser that only scanned in.
    $this->get('/floor/queue')->assertOk();
});

it('floor: hands the queue a device token so the offline replay can post with it', function (): void {
    $this->post('/floor/session', [
        'card_no' => $this->employee->card_no,
        'pin' => $this->pin,
        'machine_code' => null,
    ]);

    $token = session('floor.device_token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->get('/floor/queue')
        ->assertInertia(fn ($page) => $page->component('Floor/Queue')->where('deviceToken', $token));

    // And it is a real device session: the API the terminal writes through accepts it.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/floor/queue')
        ->assertOk();
});

it('floor: carries the chosen machine through to the queue', function (): void {
    // The seed carries no machines, so the row this asserts on is made here rather than found.
    $code = 'TEST-M1';
    DB::table('machines')->insert([
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'machine_group_id' => DB::table('machine_groups')->value('id'),
        'code' => $code,
        'name' => 'Test loom',
    ]);

    $this->post('/floor/session', [
        'card_no' => $this->employee->card_no,
        'pin' => $this->pin,
        'machine_code' => $code,
    ])->assertRedirect('/floor/queue?machine='.$code);
});

it('floor: refuses a wrong PIN and leaves the browser a guest', function (): void {
    $this->post('/floor/session', [
        'card_no' => $this->employee->card_no,
        'pin' => '9999',
    ])->assertRedirect();

    $this->assertGuest();
    $this->get('/floor/queue')->assertRedirect('/login');
});

it('floor: signs a badge out and revokes its device token at end of shift', function (): void {
    $this->post('/floor/session', [
        'card_no' => $this->employee->card_no,
        'pin' => $this->pin,
    ]);

    $token = session('floor.device_token');

    $this->post('/floor/session/end')->assertRedirect('/floor');

    $this->assertGuest();

    // The next operator at this kiosk must scan their own badge; the old token is dead.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/floor/queue')
        ->assertUnauthorized();
});

it('floor: lets a supervisor already signed in at a desk continue without a badge', function (): void {
    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->actingAs($supervisor)
        ->get('/floor')
        ->assertInertia(fn ($page) => $page->component('Floor/Login')->where('signedInAs', $supervisor->name));

    $this->actingAs($supervisor)
        ->post('/floor/session/continue')
        ->assertRedirect('/floor/queue');

    expect(session('floor.device_token'))->toBeString();
});

it('floor: does not offer the no-badge door to someone who may not run the terminal', function (): void {
    $driver = User::query()->where('email', 'driver@octapussolution.com')->firstOrFail();

    $this->actingAs($driver)
        ->get('/floor')
        ->assertInertia(fn ($page) => $page->where('signedInAs', null));

    $this->actingAs($driver)->post('/floor/session/continue')->assertForbidden();
});
