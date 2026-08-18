<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * IN-3 / P2-7 — return unused material to the same lot it was issued from.
 *
 * returnable_qty(job, lot) = posted issue qty − posted return qty. Returns post
 * `return_from_job` with a positive quantity and never call consumeForIssue().
 */
beforeEach(function (): void {
    $this->keeper = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $this->manager = User::query()->where('email', 'storemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
});

function p27Job(object $test): JobCard
{
    $job = JobCard::query()->whereNotNull('sales_order_line_id')->orderBy('id')->firstOrFail();

    if (! in_array($job->status, [
        JobCard::RELEASED,
        JobCard::IN_PRODUCTION,
        JobCard::QC_PENDING,
        JobCard::COMPLETED,
        JobCard::CLOSED,
        JobCard::CANCELLED,
    ], true)) {
        $test->actingAs($test->admin);
        app(JobCardStateMachine::class)->transition($job, JobCard::RELEASED, [
            'material_waiver_reason' => 'IN-3 seeded shortage',
        ]);
    }

    return $job->refresh();
}

function p27IssuableLot(JobCard $job, float $need, ?int $exceptLotId = null, ?int $warehouseId = null): StockLot
{
    $reservations = app(ReservationService::class);

    $lots = StockLot::query()
        ->where('status', 'available')
        ->whereNotNull('item_id')
        ->where('balance_qty', '>=', $need)
        ->when($exceptLotId, fn ($query) => $query->where('id', '!=', $exceptLotId))
        ->when($warehouseId, fn ($query) => $query->where('warehouse_id', $warehouseId))
        ->orderBy('id')
        ->get();

    foreach ($lots as $lot) {
        $free = (float) $lot->balance_qty - $reservations->claimedByOthers((int) $lot->id, (int) $job->id);

        if ($free + 0.000001 >= $need) {
            return $lot;
        }
    }

    throw new RuntimeException("No lot with {$need} free for job {$job->id}.");
}

/** @return object{job: JobCard, lot: StockLot, qty: float, unitCost: float, balanceAfter: float} */
function p27Issued(object $test, float $qty = 10, ?JobCard $job = null, ?StockLot $lot = null): object
{
    $job ??= p27Job($test);
    $lot ??= p27IssuableLot($job, $qty);

    $test->actingAs($test->keeper);
    $test->post('/material-issues', [
        'job_card_id' => $job->id,
        'warehouse_id' => $lot->warehouse_id,
        'issue_type' => 'issue',
        'lines' => [[
            'item_id' => $lot->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $lot->uom_id,
            'qty' => $qty,
        ]],
    ])->assertSessionHasNoErrors();

    $lot->refresh();

    return (object) [
        'job' => $job->refresh(),
        'lot' => $lot,
        'qty' => $qty,
        'unitCost' => (float) $lot->unit_cost,
        'balanceAfter' => (float) $lot->balance_qty,
    ];
}

function p27ReturnPayload(object $ctx, float $qty, array $overrides = []): array
{
    return [
        'job_card_id' => $overrides['job_card_id'] ?? $ctx->job->id,
        'warehouse_id' => $overrides['warehouse_id'] ?? $ctx->lot->warehouse_id,
        'issue_type' => $overrides['issue_type'] ?? 'return',
        'remarks' => $overrides['remarks'] ?? 'Unused on the floor.',
        'lines' => $overrides['lines'] ?? [[
            'item_id' => $overrides['item_id'] ?? $ctx->lot->item_id,
            'lot_id' => $overrides['lot_id'] ?? $ctx->lot->id,
            'uom_id' => $overrides['uom_id'] ?? $ctx->lot->uom_id,
            'qty' => $qty,
        ]],
    ];
}

function p27Return(object $test, object $ctx, float $qty, array $overrides = []): MaterialIssue
{
    $test->actingAs($overrides['user'] ?? $test->keeper);
    $test->post('/material-issues', p27ReturnPayload($ctx, $qty, $overrides))
        ->assertSessionHasNoErrors();

    return MaterialIssue::query()->where('issue_type', MaterialIssue::TYPE_RETURN)->orderByDesc('id')->firstOrFail();
}

function p27Ledger(MaterialIssue $document)
{
    return DB::table('stock_ledger')
        ->where('source_type', MaterialIssue::class)
        ->where('source_id', $document->id);
}

function p27SiblingJob(JobCard $job): JobCard
{
    $other = $job->replicate([
        'number', 'produced_qty', 'good_qty', 'waste_qty', 'actual_start', 'actual_finish', 'material_waiver_reason',
    ]);
    $other->status = JobCard::RELEASED;
    $other->save();

    return $other;
}

function p27SetStatus(JobCard $job, string $status): JobCard
{
    $job->forceFill(['status' => $status])->save();

    return $job->refresh();
}

function p27StartProduction(object $test, JobCard $job): JobCard
{
    $test->actingAs($test->admin);

    if ($job->status === JobCard::RELEASED) {
        app(JobCardStateMachine::class)->transition($job, JobCard::IN_PRODUCTION);
    }

    return $job->refresh();
}

function p27Produce(object $test, JobCard $job, float $good): void
{
    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@maheenlabel.test')
        ->value('card_no');

    $token = $test->postJson('/api/v1/device/session', [
        'card_no' => $card, 'pin' => substr((string) $card, -4),
    ])->json('token');

    $final = $job->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    $test->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => $good, 'waste_qty' => 0, 'input_qty' => $good,
    ], [
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertOk();
}

function p27ReceiveFg(object $test, JobCard $job, float $qty): FgReceipt
{
    $fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $test->actingAs(User::query()->where('email', 'supervisor@maheenlabel.test')->firstOrFail());
    $test->post("/job-cards/{$job->id}/fg-receipts", [
        'qty' => $qty,
        'warehouse_id' => $fgWarehouseId,
        'grade' => 'A',
        'client_ref' => (string) Str::uuid(),
    ])->assertSessionHasNoErrors();

    return FgReceipt::query()->where('job_card_id', $job->id)->orderByDesc('id')->firstOrFail();
}

it('posts a return onto the same lot with return_from_job and a positive quantity', function (): void {
    $ctx = p27Issued($this, 10);
    $avgBefore = (float) DB::table('items')->where('id', $ctx->lot->item_id)->value('avg_rate');
    $lotsBefore = DB::table('stock_lots')->count();
    $unitCost = $ctx->unitCost;

    $document = p27Return($this, $ctx, 4);
    $entry = p27Ledger($document)->first();

    expect($document->issue_type)->toBe(MaterialIssue::TYPE_RETURN)
        ->and($document->status)->toBe(MaterialIssue::POSTED)
        ->and($entry)->not->toBeNull()
        ->and($entry->movement_type)->toBe('return_from_job')
        ->and((float) $entry->qty)->toBeQty(4.0)
        ->and((int) $entry->lot_id)->toBe((int) $ctx->lot->id)
        ->and((float) $entry->unit_cost)->toBeQty($unitCost)
        ->and((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter + 4)
        ->and((float) $ctx->lot->unit_cost)->toBeQty($unitCost)
        ->and((float) DB::table('items')->where('id', $ctx->lot->item_id)->value('avg_rate'))->toBe($avgBefore)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore)
        ->and($ctx->lot->status)->toBe('available');
});

it('does not call consumeForIssue or reduce warehouse stock on a return', function (): void {
    $ctx = p27Issued($this, 8);
    $reservationsBefore = DB::table('stock_reservations')->get()->map(fn ($row) => (array) $row)->all();

    $spy = $this->spy(ReservationService::class);
    $this->app->instance(ReservationService::class, $spy);

    p27Return($this, $ctx, 3);

    $spy->shouldNotHaveReceived('consumeForIssue');

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter + 3)
        ->and(DB::table('stock_reservations')->get()->map(fn ($row) => (array) $row)->all())
        ->toEqual($reservationsBefore);
});

it('restores a fully issued consumed lot to available on the same lot', function (): void {
    $job = p27Job($this);
    $lot = p27IssuableLot($job, 1);
    $full = (float) $lot->balance_qty - app(ReservationService::class)->claimedByOthers((int) $lot->id, (int) $job->id);
    $ctx = p27Issued($this, $full, $job, $lot);

    expect($ctx->lot->status)->toBe('consumed')
        ->and((float) $ctx->lot->balance_qty)->toBeQty(0.0);

    $returnQty = min(5.0, $full);
    p27Return($this, $ctx, $returnQty);

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($returnQty)
        ->and($ctx->lot->status)->toBe('available')
        ->and(DB::table('stock_lots')->where('id', $ctx->lot->id)->count())->toBe(1);
});

it('leaves an already-available lot available after a partial return', function (): void {
    $ctx = p27Issued($this, 6);

    expect($ctx->lot->status)->toBe('available');

    p27Return($this, $ctx, 2);

    expect($ctx->lot->refresh()->status)->toBe('available');
});

it('refuses a return that exceeds the originally issued quantity', function (): void {
    $ctx = p27Issued($this, 10);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 10.0001))
        ->assertSessionHasErrors('lines.0.qty');

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter);
});

it('caps a later return at issued minus previously returned quantity', function (): void {
    $ctx = p27Issued($this, 10);
    p27Return($this, $ctx, 4);

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 7))
        ->assertSessionHasErrors('lines.0.qty');

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter + 4);
});

it('allows multiple partial returns up to the issued quantity', function (): void {
    $ctx = p27Issued($this, 10);

    p27Return($this, $ctx, 3);
    p27Return($this, $ctx, 4);
    p27Return($this, $ctx, 3);

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 0.0001))
        ->assertSessionHasErrors('lines.0.lot_id');

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter + 10)
        ->and((float) DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $ctx->job->id)
            ->where('mi.issue_type', 'return')
            ->where('mi.status', 'posted')
            ->where('mil.lot_id', $ctx->lot->id)
            ->sum('mil.qty'))->toBeQty(10.0);
});

it('rejects returning another job\'s issued lot', function (): void {
    $ctx = p27Issued($this, 8);
    $other = p27SiblingJob($ctx->job);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 2, ['job_card_id' => $other->id]))
        ->assertSessionHasErrors('lines.0.lot_id');

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter);
});

it('does not count draft or cancelled issues toward returnable quantity', function (): void {
    $ctx = p27Issued($this, 5);

    foreach (['draft', 'cancelled'] as $status) {
        $issueId = DB::table('material_issues')->insertGetId([
            'number' => 'MI-IN3-'.$status,
            'job_card_id' => $ctx->job->id,
            'warehouse_id' => $ctx->lot->warehouse_id,
            'issued_on' => now()->toDateString(),
            'issue_type' => 'issue',
            'status' => $status,
            'created_at' => now(),
        ]);
        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $issueId,
            'line_no' => 1,
            'item_id' => $ctx->lot->item_id,
            'lot_id' => $ctx->lot->id,
            'uom_id' => $ctx->lot->uom_id,
            'qty' => 20,
            'unit_cost' => $ctx->unitCost,
        ]);
    }

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 5.0001))
        ->assertSessionHasErrors('lines.0.qty');

    p27Return($this, $ctx, 5);
});

it('rejects a return against a closed or cancelled job', function (string $status): void {
    $ctx = p27Issued($this, 4);
    p27SetStatus($ctx->job, $status);

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 1))
        ->assertSessionHasErrors('job_card_id');

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter);
})->with([
    JobCard::CLOSED,
    JobCard::CANCELLED,
]);

it('allows a return against a job in an allowed status', function (string $status): void {
    $ctx = p27Issued($this, 3);
    p27SetStatus($ctx->job, $status);

    p27Return($this, $ctx, 1);

    expect((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter + 1);
})->with([
    JobCard::RELEASED,
    JobCard::IN_PRODUCTION,
    JobCard::QC_PENDING,
    JobCard::COMPLETED,
]);

it('rejects replacement and any other unsupported issue type', function (): void {
    $ctx = p27Issued($this, 4);

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 1, ['issue_type' => 'replacement']))
        ->assertSessionHasErrors('issue_type');

    $this->actingAs($this->keeper)
        ->post('/material-issues', p27ReturnPayload($ctx, 1, ['issue_type' => 'adjustment']))
        ->assertSessionHasErrors('issue_type');
});

it('lets a store manager post a return', function (): void {
    $ctx = p27Issued($this, 5);
    p27Return($this, $ctx, 2, ['user' => $this->manager]);

    expect(p27Ledger(MaterialIssue::query()->where('issue_type', 'return')->orderByDesc('id')->firstOrFail())
        ->value('movement_type'))->toBe('return_from_job');
});

it('refuses MD, operator and driver from posting a return', function (): void {
    $ctx = p27Issued($this, 4);
    $ledgerBefore = DB::table('stock_ledger')->count();

    foreach ([$this->md, $this->operator, $this->driver] as $user) {
        $this->actingAs($user)
            ->post('/material-issues', p27ReturnPayload($ctx, 1))
            ->assertForbidden();
    }

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) $ctx->lot->refresh()->balance_qty)->toBeQty($ctx->balanceAfter);
});

it('ignores client-supplied returnable quantity and movement type', function (): void {
    $ctx = p27Issued($this, 5);
    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs($this->keeper)
        ->post('/material-issues', array_merge(p27ReturnPayload($ctx, 9), [
            'returnable_qty' => 99,
            'movement_type' => 'issue_to_job',
            'lines' => [[
                'item_id' => $ctx->lot->item_id,
                'lot_id' => $ctx->lot->id,
                'uom_id' => $ctx->lot->uom_id,
                'qty' => 9,
                'returnable_qty' => 99,
                'movement_type' => 'issue_to_job',
                'status' => 'available',
                'balance_qty' => 999,
            ]],
        ]))
        ->assertSessionHasErrors('lines.0.qty');

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore);

    $document = p27Return($this, $ctx, 2, [
        'lines' => [[
            'item_id' => $ctx->lot->item_id,
            'lot_id' => $ctx->lot->id,
            'uom_id' => $ctx->lot->uom_id,
            'qty' => 2,
            'movement_type' => 'issue_to_job',
            'returnable_qty' => 99,
        ]],
    ]);

    expect(p27Ledger($document)->value('movement_type'))->toBe('return_from_job');
});

it('rolls back every line when a later return line is impossible', function (): void {
    $first = p27Issued($this, 5);
    $secondLot = p27IssuableLot($first->job, 4, (int) $first->lot->id, (int) $first->lot->warehouse_id);
    $second = p27Issued($this, 4, $first->job, $secondLot);

    $ledgerBefore = DB::table('stock_ledger')->count();
    $issuesBefore = DB::table('material_issues')->count();
    $balanceA = (float) $first->lot->refresh()->balance_qty;
    $balanceB = (float) $second->lot->refresh()->balance_qty;

    $this->actingAs($this->keeper)
        ->post('/material-issues', [
            'job_card_id' => $first->job->id,
            'warehouse_id' => $first->lot->warehouse_id,
            'issue_type' => 'return',
            'lines' => [
                [
                    'item_id' => $first->lot->item_id,
                    'lot_id' => $first->lot->id,
                    'uom_id' => $first->lot->uom_id,
                    'qty' => 2,
                ],
                [
                    'item_id' => $second->lot->item_id,
                    'lot_id' => $second->lot->id,
                    'uom_id' => $second->lot->uom_id,
                    'qty' => 4.5,
                ],
            ],
        ])
        ->assertSessionHasErrors('lines.1.qty');

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(DB::table('material_issues')->count())->toBe($issuesBefore)
        ->and((float) $first->lot->refresh()->balance_qty)->toBeQty($balanceA)
        ->and((float) $second->lot->refresh()->balance_qty)->toBeQty($balanceB);
});

it('writes an audit entry when a return is posted', function (): void {
    $ctx = p27Issued($this, 4);
    $document = p27Return($this, $ctx, 1);

    expect(AuditLog::query()
        ->where('auditable_type', MaterialIssue::class)
        ->where('auditable_id', $document->id)
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

it('nets a partial return out of FG material cost', function (): void {
    $ctx = p27Issued($this, 10);
    p27Return($this, $ctx, 3);

    $job = p27StartProduction($this, $ctx->job);
    p27Produce($this, $job, 1000);
    $receipt = p27ReceiveFg($this, $job, 1000);
    $fgLot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    expect((float) $fgLot->unit_cost)->toBeQty(round(7.0 * $ctx->unitCost / 1000.0, 4));
});

it('values FG material at zero when the issue is fully returned', function (): void {
    $ctx = p27Issued($this, 10);
    p27Return($this, $ctx, 10);

    $job = p27StartProduction($this, $ctx->job);
    p27Produce($this, $job, 1000);
    $receipt = p27ReceiveFg($this, $job, 1000);
    $fgLot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    expect((float) $fgLot->unit_cost)->toBeQty(0.0);
});

it('exposes remaining returnable lots for the selected job', function (): void {
    $ctx = p27Issued($this, 10);
    p27Return($this, $ctx, 3);

    $response = $this->actingAs($this->keeper)
        ->getJson('/material-issues/returnable?job_card_id='.$ctx->job->id.'&warehouse_id='.$ctx->lot->warehouse_id)
        ->assertOk();

    $row = collect($response->json('lots'))->firstWhere('lot_id', $ctx->lot->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['issued_qty'])->toBeQty(10.0)
        ->and((float) $row['returned_qty'])->toBeQty(3.0)
        ->and((float) $row['returnable_qty'])->toBeQty(7.0);
});

it('still posts a normal issue with issue_to_job', function (): void {
    $ctx = p27Issued($this, 2);
    $issue = MaterialIssue::query()->where('issue_type', MaterialIssue::TYPE_ISSUE)->orderByDesc('id')->firstOrFail();

    expect(p27Ledger($issue)->value('movement_type'))->toBe('issue_to_job')
        ->and((float) p27Ledger($issue)->value('qty'))->toBeQty(-2.0)
        ->and($ctx->job->status)->toBe(JobCard::RELEASED);
});
