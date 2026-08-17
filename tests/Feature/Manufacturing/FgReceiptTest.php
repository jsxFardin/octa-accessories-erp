<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0-3 — production output becomes finished-goods stock through one door: FgReceiptService.
 *
 * One `fg_receipts` row, one new finished-goods lot, exactly one `production_output` ledger
 * movement. Quarantine until an accepted final inspection for the same job; the release is a
 * status flip and never a second movement. The ceiling — final-operation good output minus
 * what is already received — holds under the job card's row lock.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    // The demo card is left `planned` on purpose; walk it to in_production through the state
    // machine (waiving the seeded yarn shortage), never by writing the status column.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'Test walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    // Shop-floor session for output logging, exactly as the terminal does it.
    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@maheenlabel.test')
        ->value('card_no');
    $this->deviceToken = $this->postJson('/api/v1/device/session', [
        'card_no' => $card, 'pin' => substr((string) $card, -4),
    ])->json('token');

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
});

/** Report good output on the job's final operation, through the device API. */
function produceOutput(object $test, float $good): void
{
    $final = $test->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    $test->postJson("/api/v1/operations/{$final->id}/log", [
        'good_qty' => $good, 'waste_qty' => 0, 'input_qty' => $good,
    ], [
        'Authorization' => "Bearer {$test->deviceToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertOk();
}

/** Post an FG receipt as the production supervisor, through the route. */
function postReceipt(object $test, float $qty, string $grade = 'A', ?string $clientRef = null): Illuminate\Testing\TestResponse
{
    $test->actingAs(User::query()->where('email', 'supervisor@maheenlabel.test')->firstOrFail());

    return $test->post("/job-cards/{$test->jobCard->id}/fg-receipts", [
        'qty' => $qty,
        'warehouse_id' => $test->fgWarehouseId,
        'grade' => $grade,
        'client_ref' => $clientRef ?? (string) Str::uuid(),
    ]);
}

/** Record a final inspection for a job through the QC route; verdict computes to accepted at 0 majors. */
function acceptFinalQc(object $test, int $jobCardId): void
{
    $test->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());

    $test->post('/qc-inspections', [
        'job_card_id' => $jobCardId, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => 0, 'minor_found' => 0, 'critical_found' => 0,
    ])->assertSessionHasNoErrors();
}

it('posts production output into finished-goods stock with exactly one production_output movement', function (): void {
    produceOutput($this, 1000);
    postReceipt($this, 600)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();
    $lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    expect($receipt->status)->toBe('posted')
        ->and($receipt->number)->not->toBeNull()
        ->and((float) $receipt->qty)->toBeQty(600.0)
        // The lot: finished goods, owned by the product, welded to the job, in the FG store.
        ->and($lot->kind)->toBe('finished_goods')
        ->and((int) $lot->product_id)->toBe((int) $this->jobCard->product_id)
        ->and((int) $lot->job_card_id)->toBe((int) $this->jobCard->id)
        ->and((int) $lot->warehouse_id)->toBe($this->fgWarehouseId)
        ->and((float) $lot->balance_qty)->toBeQty(600.0);

    // Exactly one ledger movement, and it is production_output — never fg_receipt, never two.
    $movements = DB::table('stock_ledger')->where('lot_id', $lot->id)->get();

    expect($movements)->toHaveCount(1)
        ->and($movements[0]->movement_type)->toBe('production_output')
        ->and((float) $movements[0]->qty)->toBeQty(600.0)
        ->and($movements[0]->source_type)->toBe(FgReceipt::class);

    // Balance caches agree with the ledger-derived view (I3).
    $view = (float) DB::table('v_stock_balances')->where('lot_id', $lot->id)->value('balance_qty');
    expect((float) DB::table('stock_balances')->where('lot_id', $lot->id)->value('balance_qty'))->toBeQty($view);

    // This workflow writes no fg_receipt-typed ledger rows at all.
    expect(DB::table('stock_ledger')->where('movement_type', 'fg_receipt')->count())->toBe(0);
});

it('holds FG in quarantine until final QC accepts, then releases by status flip only', function (): void {
    produceOutput($this, 1000);
    postReceipt($this, 1000)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();

    // No accepted final inspection yet → quarantine, and not available anywhere.
    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');

    $position = app(App\Modules\Manufacturing\Services\FgReceiptService::class)->positionFor($this->jobCard);
    expect($position['available'])->toBeQty(0.0)->and($position['quarantined'])->toBeQty(1000.0);

    $ledgerBefore = DB::table('stock_ledger')->count();

    acceptFinalQc($this, (int) $this->jobCard->id);

    // Released — a status change with a silent ledger: the pieces never moved.
    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('available')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('marks the lot available immediately when an accepted final inspection already exists', function (): void {
    produceOutput($this, 1000);
    acceptFinalQc($this, (int) $this->jobCard->id);
    postReceipt($this, 400)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();

    expect($receipt->qc_inspection_id)->not->toBeNull()
        ->and(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('available');
});

it('keeps reject-grade output in quarantine even when the paperwork is accepted', function (): void {
    produceOutput($this, 1000);
    acceptFinalQc($this, (int) $this->jobCard->id);

    postReceipt($this, 300, grade: 'reject')->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->where('grade', 'reject')->firstOrFail();

    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');

    // A repeated accepted verdict releases nothing it should not, and duplicates nothing.
    acceptFinalQc($this, (int) $this->jobCard->id);
    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');
});

it('does not let another job\'s inspection release this job\'s quarantined lot', function (): void {
    produceOutput($this, 1000);
    postReceipt($this, 500)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();
    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');

    // A sibling job for the same product, planned through the state machine.
    $other = $this->jobCard->replicate(['number', 'produced_qty', 'good_qty', 'waste_qty', 'actual_start', 'actual_finish']);
    $other->status = JobCard::DRAFT;
    $other->save();

    acceptFinalQc($this, (int) $other->id);

    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');
});

it('enforces the production ceiling across partial receipts and rolls back cleanly', function (): void {
    produceOutput($this, 10000);

    postReceipt($this, 6000)->assertSessionHasNoErrors();
    postReceipt($this, 4000)->assertSessionHasNoErrors();

    $receipts = DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->count();
    $lots = DB::table('stock_lots')->where('job_card_id', $this->jobCard->id)->where('kind', 'finished_goods')->count();
    $ledger = DB::table('stock_ledger')->where('movement_type', 'production_output')->count();

    // Each partial receipt made its own lot and its own single movement.
    expect($receipts)->toBe(2)->and($lots)->toBe(2)->and($ledger)->toBe(2);

    // One piece over the 10,000 the final operation reported → refused, nothing written.
    postReceipt($this, 1)->assertSessionHasErrors('qty');

    expect(DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->count())->toBe($receipts)
        ->and(DB::table('stock_lots')->where('job_card_id', $this->jobCard->id)->where('kind', 'finished_goods')->count())->toBe($lots)
        ->and(DB::table('stock_ledger')->where('movement_type', 'production_output')->count())->toBe($ledger);

    // Σ receipts == Σ production_output ledger quantity — reconcilable to the piece.
    $receiptSum = (float) DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->where('status', 'posted')->sum('qty');
    $ledgerSum = (float) DB::table('stock_ledger')->where('movement_type', 'production_output')->sum('qty');
    expect($receiptSum)->toBeQty($ledgerSum)->toBeQty(10000.0);
});

it('replays a duplicate client_ref instead of double-posting', function (): void {
    produceOutput($this, 1000);
    $ref = (string) Str::uuid();

    postReceipt($this, 700, clientRef: $ref)->assertSessionHasNoErrors();
    postReceipt($this, 700, clientRef: $ref)->assertSessionHasNoErrors();

    // One receipt, one lot, one movement — the second submit was a replay, not a repeat.
    expect(DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->count())->toBe(1)
        ->and(DB::table('stock_lots')->where('job_card_id', $this->jobCard->id)->where('kind', 'finished_goods')->count())->toBe(1)
        ->and(DB::table('stock_ledger')->where('movement_type', 'production_output')->count())->toBe(1);
});

it('leaves the order line produced quantity to production, not to receipts', function (): void {
    produceOutput($this, 1000);

    $produced = (float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty');

    postReceipt($this, 900)->assertSessionHasNoErrors();

    // P0-2's definition is untouched: receiving to FG moves stock, not the produced figure.
    expect((float) DB::table('sales_order_lines')->where('id', $this->jobCard->sales_order_line_id)->value('produced_qty'))
        ->toBeQty($produced);

    $position = app(App\Modules\Manufacturing\Services\FgReceiptService::class)->positionFor($this->jobCard->refresh());
    expect($position['received'])->toBeQty(900.0)->and($position['remaining_receivable'])->toBeQty(100.0);
});

it('stamps the FG lot with the diluted certification claim of what the job consumed', function (): void {
    produceOutput($this, 1000);

    // Consume two real seeded lots — one GRS-certified, one not — through posted issue lines,
    // which is exactly what the dilution reads (BR-40, rounded down, never up).
    $certified = DB::table('stock_lots')->where('cert_scheme', 'GRS')->whereNotNull('item_id')->first();
    $plain = DB::table('stock_lots')->whereNull('cert_scheme')->whereNotNull('item_id')->first();

    $issueId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-TEST-1', 'job_card_id' => $this->jobCard->id, 'warehouse_id' => $certified->warehouse_id,
        'issued_on' => now()->toDateString(), 'issue_type' => 'issue', 'status' => 'posted', 'created_at' => now(),
    ]);

    foreach ([[$certified, 49.0], [$plain, 51.0]] as $i => [$lot, $qtyConsumed]) {
        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $issueId, 'line_no' => $i + 1, 'item_id' => $lot->item_id,
            'lot_id' => $lot->id, 'uom_id' => $lot->uom_id, 'qty' => $qtyConsumed, 'unit_cost' => 100.0,
        ]);
    }

    postReceipt($this, 1000)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();
    $lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    $expectedClaim = floor(49.0 * (float) $certified->cert_claim_pct / 100.0);

    expect($lot->cert_scheme)->toBe('GRS')
        ->and((float) $lot->cert_claim_pct)->toBeQty($expectedClaim)
        // Unit cost: issued-material value over final-operation good output — actual, traceable.
        ->and((float) $lot->unit_cost)->toBeQty(round(100.0 * (49.0 + 51.0) / 1000.0, 4));

    // No CoC output row at FG receipt: output is claimed at shipment, not at the store door.
    expect(DB::table('coc_transactions')->where('direction', 'output')->count())->toBe(0);
});

it('blocks users without fg_receipt.post', function (): void {
    produceOutput($this, 1000);

    $this->actingAs(User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail());

    $this->post("/job-cards/{$this->jobCard->id}/fg-receipts", [
        'qty' => 100, 'warehouse_id' => $this->fgWarehouseId, 'grade' => 'A', 'client_ref' => (string) Str::uuid(),
    ])->assertForbidden();

    expect(DB::table('fg_receipts')->count())->toBe(0);
});

it('keeps the GRN receive path byte-for-byte on grn_receipt', function (): void {
    // The movement-type parameter defaults to the old behavior; every existing GRN lot's
    // ledger stays grn_receipt, and nothing this feature does writes to that path.
    $grnMovements = DB::table('stock_ledger')
        ->join('stock_lots', 'stock_lots.id', '=', 'stock_ledger.lot_id')
        ->whereNotNull('stock_lots.grn_line_id')
        ->where('stock_ledger.qty', '>', 0)
        ->pluck('stock_ledger.movement_type')
        ->unique();

    expect($grnMovements->all())->toBe(['grn_receipt']);
});
