<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The shop-floor operation screen is not a public reading room.
 *
 * `floor/operations/{operation}` carried `auth` and nothing else, so any authenticated employee
 * could read any operation by id: its quantities and status, and the job card behind it —
 * number, product code, colourway, approved artwork version. A driver is refused
 * `/job-cards/1` outright and could read the same card through this route, with ids that are
 * sequential and trivially enumerable.
 *
 * A plain `can:job_card.view` gate is the wrong fix and would break the terminal: an operator
 * holds four permissions and that is not one of them. The screen belongs to the people who
 * *work* operations as well as those who may *view* them, which is the same shape as the
 * existing `trip.access` ability — a driver may open the trip list and sees only their own.
 */
beforeEach(function (): void {
    $this->operation = DB::table('job_card_operations')->orderBy('id')->firstOrFail();
});

it('floor: refuses the operation screen to an employee with no operation rights', function (): void {
    foreach (['driver', 'accounts', 'store'] as $mailbox) {
        $user = User::query()->where('email', "{$mailbox}@octapussolution.com")->first();

        if ($user === null) {
            continue;
        }

        $this->actingAs($user)
            ->get("/floor/operations/{$this->operation->id}")
            ->assertForbidden();
    }
});

it('floor: still lets an operator open the operation they are there to run', function (): void {
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    // The premise: the operator cannot read the job card through the commercial route…
    expect($operator->hasPermission('job_card.view'))->toBeFalse()
        ->and($operator->hasPermission('operation.view_any'))->toBeFalse();

    // …but the terminal is their screen and must keep working.
    $this->actingAs($operator)
        ->get("/floor/operations/{$this->operation->id}")
        ->assertOk();
});

it('floor: lets a supervisor and the read-only auditor view an operation', function (): void {
    foreach (['supervisor', 'auditor'] as $mailbox) {
        $user = User::query()->where('email', "{$mailbox}@octapussolution.com")->firstOrFail();

        $this->actingAs($user)
            ->get("/floor/operations/{$this->operation->id}")
            ->assertOk();
    }
});

it('floor: does not leak a job card through the terminal that the same user is refused directly', function (): void {
    $driver = User::query()->where('email', 'driver@octapussolution.com')->firstOrFail();
    $jobCardId = DB::table('job_card_operations')->where('id', $this->operation->id)->value('job_card_id');

    $this->actingAs($driver)->get("/job-cards/{$jobCardId}")->assertForbidden();
    $this->actingAs($driver)->get("/floor/operations/{$this->operation->id}")->assertForbidden();
});

it('floor: refuses the queue screen to an employee with no operation rights', function (): void {
    $driver = User::query()->where('email', 'driver@octapussolution.com')->firstOrFail();

    $this->actingAs($driver)->get('/floor/queue')->assertForbidden();
});

it('floor: answers an unknown operation id with a 404, never a 500', function (): void {
    $operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();

    $this->actingAs($operator)->get('/floor/operations/99999999')->assertNotFound();
});
