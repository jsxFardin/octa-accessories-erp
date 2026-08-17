<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Services\ReservationService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * P1-2 — releasing a job claims its material. Reservations are arithmetic, not stock: the
 * ledger stays silent, balances never move, and two released jobs can no longer count the
 * same physical yarn.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->states = app(JobCardStateMachine::class);
    $this->reservations = app(ReservationService::class);
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
});

it('reserves available material at release without touching the ledger', function (): void {
    $ledgerBefore = DB::table('stock_ledger')->count();
    $balancesBefore = DB::table('stock_lots')->sum('balance_qty');

    // The demo yarn shortage forces a waiver; the release still claims everything that exists.
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);

    $rows = DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->get();

    expect($rows)->not->toBeEmpty()
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and((float) DB::table('stock_lots')->sum('balance_qty'))->toBeQty((float) $balancesBefore);

    // Never more claimed on a lot than the lot physically holds.
    foreach ($rows->groupBy('lot_id') as $lotId => $lotRows) {
        $balance = (float) DB::table('stock_lots')->where('id', $lotId)->value('balance_qty');
        expect((float) $lotRows->sum('qty'))->toBeLessThanOrEqual($balance + 0.000001);
    }
});

it('is idempotent: a retried release does not double the claim', function (): void {
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);
    $claim = (float) DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->sum('qty');

    // Direct service retry — the machine's same-status no-op is one shield; this is the other.
    $this->reservations->reserveForJob($this->jobCard->refresh(), allowShortfall: true);

    expect((float) DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->sum('qty'))
        ->toBeQty($claim);
});

it('makes a competing job\'s release fail on material the first job claimed', function (): void {
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);

    // A sibling job for the same product wants the same lots.
    $other = $this->jobCard->replicate(['number', 'produced_qty', 'good_qty', 'waste_qty', 'actual_start', 'actual_finish', 'material_waiver_reason']);
    $other->status = JobCard::DRAFT;
    $other->save();
    DB::table('job_card_operations')->where('job_card_id', $this->jobCard->id)->get()->each(function ($op) use ($other): void {
        $row = (array) $op;
        unset($row['id']);
        $row['job_card_id'] = $other->id;
        $row['status'] = 'pending';
        DB::table('job_card_operations')->insert($row);
    });

    // Without a waiver, the second job cannot reserve what the first already holds.
    expect(fn () => $this->reservations->reserveForJob($other, allowShortfall: false))
        ->toThrow(TransitionDenied::class, 'other jobs');
});

it('stops an issue from taking another job\'s reserved quantity', function (): void {
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);

    // Find a lot fully claimed by our job.
    $claimed = DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->first();
    $lot = DB::table('stock_lots')->where('id', $claimed->lot_id)->first();

    $other = $this->jobCard->replicate(['number', 'produced_qty', 'good_qty', 'waste_qty', 'actual_start', 'actual_finish']);
    $other->status = JobCard::RELEASED;
    $other->save();

    // The other job tries to issue the claimed quantity through the real endpoint.
    $this->actingAs(User::query()->where('email', 'store@maheenlabel.test')->firstOrFail());
    $free = (float) $lot->balance_qty - (float) $claimed->qty;

    $this->post('/material-issues', [
        'job_card_id' => $other->id,
        'warehouse_id' => $lot->warehouse_id,
        'lines' => [[
            'item_id' => $lot->item_id, 'lot_id' => $lot->id, 'uom_id' => $lot->uom_id,
            'qty' => $free + (float) $claimed->qty, // wants the claim too
        ]],
    ])->assertSessionHas('error');

    // Nothing moved: the refusal happened before any posting.
    expect((float) DB::table('stock_lots')->where('id', $lot->id)->value('balance_qty'))->toBeQty((float) $lot->balance_qty)
        ->and(DB::table('material_issues')->where('job_card_id', $other->id)->count())->toBe(0);
});

it('consumes the claim as the owning job issues, and keeps history rows', function (): void {
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);

    $claimed = DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->first();
    $lot = DB::table('stock_lots')->where('id', $claimed->lot_id)->first();

    $this->actingAs(User::query()->where('email', 'store@maheenlabel.test')->firstOrFail());
    $this->post('/material-issues', [
        'job_card_id' => $this->jobCard->id,
        'warehouse_id' => $lot->warehouse_id,
        'lines' => [[
            'item_id' => $lot->item_id, 'lot_id' => $lot->id, 'uom_id' => $lot->uom_id,
            'qty' => (float) $claimed->qty,
        ]],
    ])->assertSessionHasNoErrors();

    $row = DB::table('stock_reservations')->where('id', $claimed->id)->first();

    // Fully issued → consumed, dated, keeping the claimed quantity on record. Never deleted.
    expect($row->status)->toBe('consumed')
        ->and((float) $row->qty)->toBeQty((float) $claimed->qty)
        ->and($row->released_on)->not->toBeNull();
});

it('releases claims when the job is cancelled', function (): void {
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);

    expect(DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->where('status', 'active')->exists())->toBeTrue();

    $this->states->transition($this->jobCard->refresh(), JobCard::CANCELLED);

    $rows = DB::table('stock_reservations')->where('job_card_id', $this->jobCard->id)->get();

    expect($rows->where('status', 'active'))->toBeEmpty()
        ->and($rows->where('status', 'released'))->not->toBeEmpty();
});

it('feeds the MRP and availability arithmetic', function (): void {
    $itemId = (int) DB::table('bom_lines')->where('bom_id', $this->jobCard->bom_id)->value('item_id');
    $availability = app(App\Modules\Inventory\Services\StockAvailability::class);

    $before = $availability->reserved($itemId);
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'seeded shortage']);
    $after = $availability->reserved($itemId);

    // The previously dead reserved() term now reads real claims — excluding the owner's own.
    expect($after)->toBeGreaterThan($before)
        ->and($availability->reserved($itemId, (int) $this->jobCard->id))->toBeQty($before);
});
