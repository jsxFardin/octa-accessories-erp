<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * GAP S-05 — the device API checks the four operator permissions (06-rbac §6).
 *
 * `device` proves who holds the badge. It said nothing about what that person may do, so any
 * active employee with a user account and a PIN — a driver, an accountant — could start,
 * log, finish and stop operations by id.
 */
function deviceTokenFor(string $email): string
{
    $card = (string) DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', $email)
        ->value('card_no');

    return test()->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr($card, -4),
    ])->assertCreated()->json('token');
}

beforeEach(function (): void {
    $this->operation = DB::table('job_card_operations')->orderBy('id')->firstOrFail();
});

it('refuses every write to a badge whose role has no operation permissions', function (): void {
    $token = deviceTokenFor('driver@octapussolution.com');
    $headers = ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'perm-check'];

    foreach (['start', 'log', 'finish', 'downtime'] as $action) {
        $this->withHeaders($headers)
            ->postJson("/api/v1/operations/{$this->operation->id}/{$action}", ['good_qty' => 1])
            ->assertForbidden();
    }

    // Reading the queue is still allowed: the gate is on the writes.
    $this->withHeaders($headers)->getJson('/api/v1/floor/queue')->assertOk();
});

it('lets an operator through the permission gate', function (): void {
    $token = deviceTokenFor('operator@octapussolution.com');

    // Whatever the floor rules say about this particular step, the answer is not 403.
    $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'perm-ok'])
        ->postJson("/api/v1/operations/{$this->operation->id}/downtime", [
            'downtime_reason_id' => DB::table('downtime_reasons')->value('id'),
            'minutes' => 5,
        ]);

    expect($response->status())->not->toBe(403);
});
