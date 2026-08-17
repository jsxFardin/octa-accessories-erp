<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P1-3 — a rejection with disposition `rework` is a controlled loop, not a note: an NCR is
 * raised, the rejected batch's FG freezes, the flagged operation reopens and the job goes
 * back to the floor through its state machine. History is append-only throughout.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->states = app(JobCardStateMachine::class);

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'rework walkthrough']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED, 'input_qty' => 1000, 'good_qty' => 1000]);
    $this->states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
});

/** Post a final inspection as the QC inspector. */
function postFinal(object $test, int $majors, ?string $disposition = null): void
{
    $test->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());

    $test->post('/qc-inspections', array_filter([
        'job_card_id' => $test->jobCard->id, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => $majors, 'disposition' => $disposition,
    ]))->assertSessionHasNoErrors();
}

it('raises an NCR and reopens the flagged operation on a rework rejection', function (): void {
    $logCountBefore = DB::table('operation_logs')->count();

    postFinal($this, majors: 50, disposition: 'rework');

    // The NCR exists on the real schema, tied to the inspection and the job.
    $ncr = DB::table('ncrs')->latest('id')->first();

    expect($ncr)->not->toBeNull()
        ->and($ncr->number)->not->toBeNull()
        ->and($ncr->source)->toBe('final')
        ->and((int) $ncr->job_card_id)->toBe((int) $this->jobCard->id)
        ->and($ncr->status)->toBe('open')
        ->and($ncr->description)->toContain('rework');

    // The final operation reopened; the job is back on the floor through its machine.
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();

    expect($final->status)->toBe(JobCardOperation::READY)
        ->and($this->jobCard->refresh()->status)->toBe(JobCard::IN_PRODUCTION)
        // History untouched: no operation log was deleted or rewritten.
        ->and(DB::table('operation_logs')->count())->toBe($logCountBefore)
        ->and((float) $final->good_qty)->toBeQty(1000.0);
});

it('freezes the rejected batch\'s quarantined FG so later acceptance cannot release it', function (): void {
    // FG received before QC — quarantined, as P0-3 demands.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 800, $this->fgWarehouseId, (string) Str::uuid());

    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('quarantine');

    postFinal($this, majors: 50, disposition: 'rework');

    // Rejected batch → blocked, not quarantine: outside every release and packing path.
    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('blocked');

    // Rework produces new output; its acceptance releases only the NEW batch.
    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['status' => JobCardOperation::COMPLETED, 'input_qty' => 1200, 'good_qty' => 1200])->save();
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);

    $second = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 400, $this->fgWarehouseId, (string) Str::uuid());

    postFinal($this, majors: 0);

    expect(DB::table('stock_lots')->where('id', $second->lot_id)->value('status'))->toBe('available')
        // The old rejected batch stays frozen — history does not get laundered.
        ->and(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('blocked');
});

it('accepted rework flows through the normal completion pipeline', function (): void {
    postFinal($this, majors: 50, disposition: 'rework');

    expect($this->jobCard->refresh()->status)->toBe(JobCard::IN_PRODUCTION);

    // The floor finishes the rework and QC accepts.
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED]);
    $this->states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);
    postFinal($this, majors: 0);

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED);

    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});

it('scrap disposition raises the NCR without reopening production', function (): void {
    postFinal($this, majors: 50, disposition: 'scrap');

    expect(DB::table('ncrs')->count())->toBe(1)
        // No rework: the job stays where QC left it.
        ->and($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING)
        ->and($this->jobCard->operations()->where('status', JobCardOperation::READY)->count())->toBe(0);
});

it('keeps unauthorized users out of inspections entirely', function (): void {
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail());

    $this->post('/qc-inspections', [
        'job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => 50, 'disposition' => 'rework',
    ])->assertForbidden();

    expect(DB::table('ncrs')->count())->toBe(0);
});
