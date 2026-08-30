<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * F-07 / F-08 / BR-48 — finished goods are made out of something, and cost what that something
 * cost.
 *
 * Seven job cards were producing against two material issues, and every finished-goods lot
 * received on 18 August carried a unit cost of 0.00. Those are one fact, not two: FG valuation
 * is the job's issued-material value spread over its good output, so a job with nothing issued
 * values at zero, and nothing stopped such a job from receiving stock in the first place.
 *
 * The gate is the BOM's own mandatory lines, scaled the way every other screen scales them.
 * A job with no BOM, or one whose BOM is entirely optional, still produces freely: some
 * processes genuinely consume nothing from the store.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'BR-48 walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 20000, 'good_qty' => 20000])->save();

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
    $this->receipts = app(FgReceiptService::class);
});

function receiveFg(object $test, float $qty, ?string $waiver = null, ?User $as = null): Illuminate\Testing\TestResponse
{
    return $test->actingAs($as ?? $test->supervisor)
        ->post("/job-cards/{$test->jobCard->id}/fg-receipts", [
            'qty' => $qty,
            'warehouse_id' => $test->fgWarehouseId,
            'grade' => 'A',
            'client_ref' => (string) Str::uuid(),
            'material_waiver_reason' => $waiver,
        ]);
}

it('refuses finished goods from a job that has had no material issued', function (): void {
    expect(DB::table('material_issues')->where('job_card_id', $this->jobCard->id)->count())->toBe(0);

    $lotsBefore = DB::table('stock_lots')->count();
    $ledgerBefore = DB::table('stock_ledger')->count();

    receiveFg($this, 5000)->assertSessionHasErrors('qty');

    // Nothing partial: no receipt, no lot, no ledger row (transactional integrity).
    expect(FgReceipt::query()->where('job_card_id', $this->jobCard->id)->count())->toBe(0)
        ->and(DB::table('stock_lots')->count())->toBe($lotsBefore)
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});

it('names the material the job needs rather than saying validation failed', function (): void {
    receiveFg($this, 5000)->assertSessionHasErrors('qty');

    $message = session('errors')->first('qty');

    expect($message)->toContain('No material has been issued to '.$this->jobCard->number)
        ->toContain('YRN-PLY-150D-WHT')
        ->toContain('issue it from the store');
});

it('permits a receipt the issued material accounts for', function (): void {
    issueMaterialFor($this->jobCard, 5000);

    receiveFg($this, 5000)->assertSessionHasNoErrors();

    expect(FgReceipt::query()->where('job_card_id', $this->jobCard->id)->count())->toBe(1);
});

it('caps a partial issue at the quantity it covers, and lets the rest through once issued', function (): void {
    issueMaterialFor($this->jobCard, 3000);

    $position = $this->receipts->materialPosition($this->jobCard->refresh());

    expect($position['required'])->toBeTrue()
        ->and($position['issued_any'])->toBeTrue()
        ->and($position['supported_qty'])->toBeGreaterThan(2999.0)
        ->and($position['supported_qty'])->toBeLessThan(3001.0);

    // Inside what the issue covers.
    receiveFg($this, 3000)->assertSessionHasNoErrors();

    // Beyond it.
    receiveFg($this, 2000)->assertSessionHasErrors('qty');

    expect(session('errors')->first('qty'))->toContain('Material cannot account for 5,000 pieces')
        ->and((float) FgReceipt::query()->where('job_card_id', $this->jobCard->id)
            ->where('status', 'posted')->sum('qty'))->toBeQty(3000.0);

    // Issue the rest and the same receipt goes through.
    issueMaterialFor($this->jobCard, 3000);
    receiveFg($this, 2000)->assertSessionHasNoErrors();

    expect((float) FgReceipt::query()->where('job_card_id', $this->jobCard->id)
        ->where('status', 'posted')->sum('qty'))->toBeQty(5000.0);
});

it('lets a job with no BOM produce freely', function (): void {
    // Not every process consumes from the store; a rule that assumed otherwise would invent
    // work rather than prevent an error. BR-48 is what is under test here — that a job with no
    // BOM *requires* nothing — and it still holds.
    //
    // The material issued below is not part of that rule: it is what makes this a valid
    // workflow rather than one that quietly takes worthless stock into inventory. BR-52 covers
    // the zero-value case separately, with a waiver.
    DB::table('job_cards')->where('id', $this->jobCard->id)->update(['bom_id' => null]);

    $position = $this->receipts->materialPosition($this->jobCard->refresh());

    expect($position['required'])->toBeFalse();

    issueAnyMaterialFor($this->jobCard->refresh());

    receiveFg($this, 5000)->assertSessionHasNoErrors();

    expect(FgReceipt::query()->where('job_card_id', $this->jobCard->id)->count())->toBe(1);
});

it('ignores optional BOM lines when deciding what is required', function (): void {
    DB::table('bom_lines')->where('bom_id', $this->jobCard->bom_id)->update(['is_optional' => true]);

    expect($this->receipts->materialPosition($this->jobCard->refresh())['required'])->toBeFalse();

    // As above: the optional material was genuinely taken from the store, so the receipt has a
    // cost behind it. What is asserted is that BR-48 did not *require* it.
    issueAnyMaterialFor($this->jobCard->refresh());

    receiveFg($this, 5000)->assertSessionHasNoErrors();
});

it('values the finished-goods lot from what the job actually consumed', function (): void {
    issueMaterialFor($this->jobCard, 5000, unitCost: 250.0);

    receiveFg($this, 5000)->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();
    $lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    $materialValue = (float) DB::table('material_issue_lines as mil')
        ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
        ->where('mi.job_card_id', $this->jobCard->id)
        ->where('mi.status', 'posted')
        ->sum(DB::raw('mil.qty * mil.unit_cost'));

    // Final-operation good output is the denominator (P0-2), not the receipt quantity.
    $expected = round($materialValue / 20000.0, 4);

    expect((float) $lot->unit_cost)->toBeGreaterThan(0.0)
        ->and((float) $lot->unit_cost)->toBeQty($expected)
        // And the ledger movement carries the same valuation, so on-hand value reconciles.
        ->and((float) DB::table('stock_ledger')
            ->where('lot_id', $lot->id)->where('movement_type', 'production_output')->value('unit_cost'))
        ->toBeQty($expected)
        ->and((float) DB::table('stock_ledger')
            ->where('lot_id', $lot->id)->where('movement_type', 'production_output')->value('value'))
        ->toBeQty(round($expected * 5000.0, 4));
});

it('only produces a zero-cost finished-goods lot when someone signed for it', function (): void {
    // The supervisor holds job_card.waive_material; the waiver is the only door to a lot
    // valued at nothing, and it leaves a row in the audit trail.
    receiveFg($this, 5000, 'Rework fed from last month\'s run; material was issued to JC-26-000000.')
        ->assertSessionHasNoErrors();

    $receipt = FgReceipt::query()->where('job_card_id', $this->jobCard->id)->firstOrFail();

    expect((float) DB::table('stock_lots')->where('id', $receipt->lot_id)->value('unit_cost'))->toBeQty(0.0);

    $waiver = DB::table('audit_logs')
        ->where('auditable_type', JobCard::class)
        ->where('auditable_id', $this->jobCard->id)
        ->where('new_values', 'like', '%material_waiver_reason%')
        ->orderByDesc('id')
        ->first();

    expect($waiver)->not->toBeNull()
        ->and($waiver->user_id)->toBe($this->supervisor->id)
        // Either marker is the same waiver: BR-48 records `fg_receipt` when the material falls
        // short, BR-52 records `fg_receipt_zero_value` when there is no value behind the goods
        // at all. A job with nothing issued trips the second first.
        ->and(json_decode((string) $waiver->new_values, true)['waived_for'])
        ->toBeIn(['fg_receipt', 'fg_receipt_zero_value']);
});

it('refuses the waiver to a user without job_card.waive_material', function (): void {
    $storeKeeper = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();

    expect($storeKeeper->hasPermission('job_card.waive_material'))->toBeFalse();

    // The store keeper does not hold fg_receipt.post either, so the route refuses first —
    // which is the point: two independent gates, neither relying on a hidden button.
    receiveFg($this, 5000, 'Trying it on', as: $storeKeeper)->assertForbidden();

    expect(FgReceipt::query()->where('job_card_id', $this->jobCard->id)->count())->toBe(0);
});

it('refuses a waiver from a poster who may receive but may not waive', function (): void {
    $poster = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();

    // Strip only the waiver right; the receipt right stays.
    DB::table('role_permissions')
        ->whereIn('role_id', DB::table('user_roles')->where('user_id', $poster->id)->select('role_id'))
        ->whereIn('permission_id', DB::table('permissions')->where('name', 'job_card.waive_material')->select('id'))
        ->delete();

    $poster->refresh();

    receiveFg($this, 5000, 'No authority for this')->assertSessionHasErrors('material_waiver_reason');

    expect(FgReceipt::query()->where('job_card_id', $this->jobCard->id)->count())->toBe(0);
});

it('states the material position on the job card before anyone tries to receive', function (): void {
    $this->actingAs(User::query()->where('email', 'planner@octapussolution.com')->firstOrFail())
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $fg = $page->toArray()['props']['fgPosition'];

            expect($fg['material_required'])->toBeTrue()
                ->and($fg['material_issued_any'])->toBeFalse()
                ->and((float) $fg['unit_cost'])->toBeQty(0.0);
        });

    issueMaterialFor($this->jobCard, 8000);

    $this->actingAs(User::query()->where('email', 'planner@octapussolution.com')->firstOrFail())
        ->get("/job-cards/{$this->jobCard->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $fg = $page->toArray()['props']['fgPosition'];

            expect($fg['material_issued_any'])->toBeTrue()
                ->and((float) $fg['material_supports'])->toBeGreaterThan(7999.0)
                ->and((float) $fg['unit_cost'])->toBeGreaterThan(0.0);
        });
});

it('nets returned material out of what the issue is deemed to cover', function (): void {
    issueMaterialFor($this->jobCard, 6000);

    $before = $this->receipts->materialPosition($this->jobCard->refresh())['supported_qty'];

    // A return is unused material coming back, not additional consumption (IN-3).
    $issue = DB::table('material_issues')->where('job_card_id', $this->jobCard->id)->firstOrFail();
    $returnId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-RET-BR48',
        'job_card_id' => $this->jobCard->id,
        'warehouse_id' => $issue->warehouse_id,
        'issued_on' => now()->toDateString(),
        'issue_type' => 'return',
        'status' => 'posted',
        'created_at' => now(),
    ]);

    foreach (DB::table('material_issue_lines')->where('material_issue_id', $issue->id)->get() as $i => $line) {
        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $returnId,
            'line_no' => $i + 1,
            'item_id' => $line->item_id,
            'lot_id' => $line->lot_id,
            'uom_id' => $line->uom_id,
            'qty' => (float) $line->qty / 2,
            'unit_cost' => $line->unit_cost,
        ]);
    }

    $after = $this->receipts->materialPosition($this->jobCard->refresh())['supported_qty'];

    expect($after)->toBeLessThan($before)
        ->and($after)->toBeGreaterThan(($before / 2) - 1.0)
        ->and($after)->toBeLessThan(($before / 2) + 1.0);
});
