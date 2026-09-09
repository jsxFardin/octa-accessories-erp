<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BR-52 — finished goods are not taken into stock at no value without an authorised waiver.
 *
 * BR-48 already refuses a receipt the issued material cannot account for, but it asks about
 * *pieces* and not about *value*, and it returns early for a job whose BOM has nothing
 * mandatory on it. Such a job then values its output at zero and posts it into stock in
 * silence: no shortage, no waiver, no trace. Twenty-five thousand pieces in the live database
 * are carried at 0.00 that way and two thousand of them were dispatched — a delivery with no
 * cost of sale behind it.
 *
 * A job that genuinely consumes nothing from the store is a real thing, so this is the same
 * waiver BR-48 uses — the same permission, the same typed sentence, the same audit row —
 * rather than a second mechanism beside it.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();
    $this->storeKeeper = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();
    $this->service = app(FgReceiptService::class);

    $this->jobCard = JobCard::query()->whereHas('operations')->firstOrFail();

    // A job that has produced, so the only thing standing between it and a receipt is value.
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    foreach ($this->jobCard->operations as $op) {
        $op->forceFill([
            'status' => JobCardOperation::COMPLETED,
            'input_qty' => 5000, 'good_qty' => 5000, 'waste_qty' => 0,
        ])->save();
    }

    $this->jobCard->forceFill([
        'status' => JobCard::IN_PRODUCTION,
        'planned_qty' => 5000,
        'good_qty_running' => (float) $final->good_qty,
        'produced_qty_running' => (float) $final->good_qty,
        // No BOM: the exact case BR-48 waves through and BR-52 catches.
        'bom_id' => null,
    ])->save();

    DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->delete();

    $this->warehouse = DB::table('warehouses')->where('is_active', true)->value('id');

    $this->calls = 0;

    $this->receive = function (?string $waiver, User $as) {
        // A fresh client reference each call: `post()` is idempotent by design and would
        // replay the first receipt rather than exercise the guard again.
        $this->calls++;

        return $this->service->post(
            jobCard: $this->jobCard->refresh(),
            qty: 100.0,
            warehouseId: (int) $this->warehouse,
            clientRef: 'br52-'.$this->calls.'-'.bin2hex(random_bytes(4)),
            grade: 'A',
            qcInspectionId: null,
            userId: $as->id,
            materialWaiverReason: $waiver,
        );
    };
});

it('br52: refuses finished goods that would be valued at nothing', function (): void {
    expect(fn () => ($this->receive)(null, $this->planner))
        ->toThrow(ValidationException::class);

    expect(DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->count())->toBe(0);
});

it('br52: explains that the goods would enter stock at no value', function (): void {
    try {
        ($this->receive)(null, $this->planner);
        $this->fail('The receipt should have been refused.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['qty'] ?? []))
            ->toContain('no value')
            ->toContain('record a waiver');
    }
});

it('br52: accepts the receipt when an authorised planner records a waiver', function (): void {
    expect($this->planner->hasPermission('job_card.waive_material'))->toBeTrue();

    $receipt = ($this->receive)('Rework fed from the previous run; material was issued there.', $this->planner);

    expect($receipt->qty)->toEqual(100.0);

    $lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();
    expect((float) $lot->unit_cost)->toBe(0.0);
});

it('br52: refuses the waiver from a user without the permission', function (): void {
    expect($this->storeKeeper->hasPermission('job_card.waive_material'))->toBeFalse();

    try {
        ($this->receive)('Trying to wave it through.', $this->storeKeeper);
        $this->fail('The waiver should have been refused.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['material_waiver_reason'] ?? []))
            ->toContain('job_card.waive_material');
    }

    expect(DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->count())->toBe(0);
});

it('br52: records the waiver in the audit trail against the job card', function (): void {
    ($this->receive)('Rework fed from the previous run.', $this->planner);

    $entry = DB::table('audit_logs')
        ->where('auditable_type', JobCard::class)
        ->where('auditable_id', $this->jobCard->id)
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values)->toContain('fg_receipt_zero_value')
        ->and($entry->new_values)->toContain('Rework fed from the previous run.');
});

it('br52: leaves a properly valued receipt alone', function (): void {
    // The rule must not start refusing production that has a real cost behind it.
    //
    // The lot is made here rather than found. Looking for one in the seed and skipping when
    // there was none meant the "does not over-refuse" half of BR-52 never ran: the suite
    // proved the rule fires and never proved it stops firing, which is the half that keeps a
    // guard from quietly blocking real production.
    $lotId = DB::table('stock_lots')->insertGetId([
        'lot_no' => 'BR52-'.uniqid('', false),
        'kind' => 'raw_material',
        'item_id' => DB::table('items')->value('id'),
        'warehouse_id' => DB::table('warehouses')->where('is_active', true)->value('id'),
        'uom_id' => DB::table('uoms')->where('code', 'kg')->value('id'),
        'received_qty' => 500,
        'balance_qty' => 500,
        'unit_cost' => 18.75,
        'status' => 'available',
        'received_on' => now()->toDateString(),
    ]);

    $lot = DB::table('stock_lots')->where('id', $lotId)->firstOrFail();

    $issue = DB::table('material_issues')->insertGetId([
        'number' => 'MI-BR52',
        'job_card_id' => $this->jobCard->id,
        'warehouse_id' => $lot->warehouse_id,
        'issued_on' => now()->toDateString(),
        'issue_type' => 'issue',
        'status' => 'posted',
        'issued_by' => $this->storeKeeper->id,
    ]);

    DB::table('material_issue_lines')->insert([
        'material_issue_id' => $issue,
        'line_no' => 1,
        'item_id' => $lot->item_id,
        'lot_id' => $lot->id,
        'uom_id' => $lot->uom_id,
        'qty' => 50,
        'unit_cost' => $lot->unit_cost,
    ]);

    $receipt = ($this->receive)(null, $this->planner);

    $fgLot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    expect((float) $fgLot->unit_cost)->toBeGreaterThan(0.0);
});

it('br52: hands the screen the figures its waiver field is shown from', function (): void {
    // A dead remedy is a dead rule. BR-52 refuses the receipt and tells the supervisor to
    // "record a waiver with a reason", and the form decides whether to show that field from
    // `fgPosition`. It was keyed on `material_required`, which is false for exactly the jobs
    // BR-52 catches — so the rule named a remedy the screen then hid.
    $position = $this->service->positionFor($this->jobCard->refresh());

    expect($position)->toHaveKey('unit_cost')
        // The condition the field is shown from: no value behind the goods, and something
        // still to receive.
        ->and((float) $position['unit_cost'])->toBe(0.0)
        ->and((float) $position['remaining_receivable'])->toBeGreaterThan(0.0)
        // And the case that used to hide it.
        ->and($position['material_required'])->toBeFalse();
});

it('br52: leaves the historical zero-cost lots exactly as they are', function (): void {
    // Append-only stock history. The lots that predate this guard are explained, not rewritten.
    $before = DB::table('stock_lots')->where('kind', 'finished_goods')->where('unit_cost', '<=', 0)->count();

    expect(fn () => ($this->receive)(null, $this->planner))->toThrow(ValidationException::class);

    expect(DB::table('stock_lots')->where('kind', 'finished_goods')->where('unit_cost', '<=', 0)->count())
        ->toBe($before);
});
