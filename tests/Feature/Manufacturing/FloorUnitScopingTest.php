<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Models\JobCard;
use Illuminate\Support\Facades\DB;

/**
 * A floor terminal may only touch work on its own floor.
 *
 * `FloorQueueController` already scopes what a terminal *reads* to
 * `$session->factoryUnitId`. The four write endpoints took the operation id straight off the
 * URL and never asked where that operation was, so a device badged into one unit could start,
 * log, finish or stop an operation belonging to another by id alone. Every business guard
 * (J2, J3, J5, QC1) still applied, so the numbers stayed self-consistent while being booked
 * against the wrong factory — read scoped, write unscoped, which is BR-54's read-around on the
 * other side of the request.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::IN_PRODUCTION])->save();

    $this->operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $this->token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card,
        'pin' => substr((string) $card, -4),
    ])->assertCreated()->json('token');

    $this->headers = fn (string $key): array => [
        'Authorization' => "Bearer {$this->token}",
        'Idempotency-Key' => $key,
    ];

    $this->sessionUnitId = (int) DB::table('job_cards')->where('id', $this->jobCard->id)->value('factory_unit_id');

    // A second unit to move the card to, so the terminal's own unit no longer matches.
    $this->otherUnitId = DB::table('factory_units')
        ->where('id', '!=', $this->sessionUnitId)
        ->value('id')
        ?? DB::table('factory_units')->insertGetId([
            'code' => 'QA-U2',
            'name' => 'QA second unit',
            'is_active' => true,
        ]);

    $this->moveCardToOtherUnit = function (): void {
        DB::table('job_cards')->where('id', $this->jobCard->id)
            ->update(['factory_unit_id' => $this->otherUnitId]);
    };
});

it('floor: refuses to start an operation belonging to another unit', function (): void {
    ($this->moveCardToOtherUnit)();

    $before = DB::table('job_card_operations')->where('id', $this->operation->id)->first();

    $this->withHeaders(($this->headers)('qa-scope-start'))
        ->postJson("/api/v1/operations/{$this->operation->id}/start")
        ->assertForbidden();

    $after = DB::table('job_card_operations')->where('id', $this->operation->id)->first();

    // No mutation of any kind.
    expect($after->status)->toBe($before->status)
        ->and($after->started_at)->toBe($before->started_at);
});

it('floor: refuses to log production against another unit', function (): void {
    ($this->moveCardToOtherUnit)();

    $logsBefore = DB::table('operation_logs')->count();
    $before = (float) DB::table('job_card_operations')->where('id', $this->operation->id)->value('good_qty');

    $this->withHeaders(($this->headers)('qa-scope-log'))
        ->postJson("/api/v1/operations/{$this->operation->id}/log", [
            'good_qty' => 100,
            'waste_qty' => 0,
        ])
        ->assertForbidden();

    expect(DB::table('operation_logs')->count())->toBe($logsBefore)
        ->and((float) DB::table('job_card_operations')->where('id', $this->operation->id)->value('good_qty'))
        ->toBe($before);
});

it('floor: refuses to finish an operation belonging to another unit', function (): void {
    ($this->moveCardToOtherUnit)();

    $before = DB::table('job_card_operations')->where('id', $this->operation->id)->first();

    $this->withHeaders(($this->headers)('qa-scope-finish'))
        ->postJson("/api/v1/operations/{$this->operation->id}/finish")
        ->assertForbidden();

    expect(DB::table('job_card_operations')->where('id', $this->operation->id)->value('status'))
        ->toBe($before->status);
});

it('floor: refuses to book downtime against another unit', function (): void {
    ($this->moveCardToOtherUnit)();

    $before = DB::table('downtime_logs')->count();

    $this->withHeaders(($this->headers)('qa-scope-downtime'))
        ->postJson("/api/v1/operations/{$this->operation->id}/downtime", [
            'reason' => 'breakdown',
            'minutes' => 30,
        ])
        ->assertForbidden();

    expect(DB::table('downtime_logs')->count())->toBe($before);
});

it('floor: still allows an operation on the terminal own unit', function (): void {
    // The card stays where the terminal is badged: the scope must not break the normal path.
    $this->withHeaders(($this->headers)('qa-scope-same-unit'))
        ->postJson("/api/v1/operations/{$this->operation->id}/start")
        ->assertOk();

    expect(DB::table('job_card_operations')->where('id', $this->operation->id)->value('status'))
        ->toBe('in_progress');
});

it('floor: refuses an operation id that does not exist', function (): void {
    $this->withHeaders(($this->headers)('qa-scope-missing'))
        ->postJson('/api/v1/operations/99999999/start')
        ->assertNotFound();
});

it('floor: refuses every write endpoint without a device session at all', function (): void {
    foreach (['start', 'log', 'finish', 'downtime'] as $action) {
        $this->postJson("/api/v1/operations/{$this->operation->id}/{$action}")
            ->assertUnauthorized();
    }
});
