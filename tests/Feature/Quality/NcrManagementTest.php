<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P2-2 — NCR management. QC rejection still raises the row (P1-3); this suite is the
 * investigation / CAPA / close path that was missing, and the proof that closing an NCR
 * does not invent a second stock movement.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->states = app(JobCardStateMachine::class);

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $this->states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'ncr walkthrough']);
    $this->states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED, 'input_qty' => 1000, 'good_qty' => 1000]);
    $this->states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);

    $this->qc = User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail();
    $this->quality = User::query()->where('email', 'quality@maheenlabel.test')->firstOrFail();
    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
});

function postRejectedInspection(object $test, int $majors, ?string $disposition = null): void
{
    $test->actingAs($test->qc);

    $test->post('/qc-inspections', array_filter([
        'job_card_id' => $test->jobCard->id, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => $majors, 'disposition' => $disposition,
    ]))->assertSessionHasNoErrors();
}

function latestNcr(): Ncr
{
    return Ncr::query()->orderByDesc('id')->firstOrFail();
}

function walkNcr(object $test, Ncr $ncr, string $until): Ncr
{
    $test->actingAs($test->qc);

    $test->post("/ncrs/{$ncr->id}/assign", ['owner_id' => $test->quality->id])->assertSessionHasNoErrors();

    if ($until === 'assigned') {
        return $ncr->refresh();
    }

    $test->post("/ncrs/{$ncr->id}/investigate", [
        'root_cause' => 'Registration drift on the press.',
        'action' => 'Reset the impression and reprint the lot.',
        'preventive_action' => 'Add a mid-shift registration check.',
    ])->assertSessionHasNoErrors();

    if ($until === 'investigating') {
        return $ncr->refresh();
    }

    $test->post("/ncrs/{$ncr->id}/disposition")->assertSessionHasNoErrors();

    if ($until === 'action_taken') {
        return $ncr->refresh();
    }

    $test->post("/ncrs/{$ncr->id}/verify", ['effectiveness' => 'effective'])->assertSessionHasNoErrors();

    if ($until === 'verified') {
        return $ncr->refresh();
    }

    $test->post("/ncrs/{$ncr->id}/close")->assertSessionHasNoErrors();

    return $ncr->refresh();
}

it('creates an NCR automatically from a QC rejection and lists it', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');

    $ncr = latestNcr();

    expect($ncr->status)->toBe(Ncr::OPEN)
        ->and($ncr->source)->toBe('final')
        ->and((int) $ncr->job_card_id)->toBe((int) $this->jobCard->id)
        ->and($ncr->number)->not->toBeNull();

    $this->actingAs($this->qc)->get('/ncrs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Quality/Ncrs/Index')
            ->has('ncrs.data', 1)
            ->where('ncrs.data.0.number', $ncr->number)
            ->where('counts.open', 1));
});

it('shows the NCR with its QC, job card, operation and product source', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');

    $ncr = latestNcr();
    $inspection = DB::table('qc_inspections')->where('id', $ncr->qc_inspection_id)->first();

    $this->actingAs($this->qc)->get("/ncrs/{$ncr->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Quality/Ncrs/Show')
            ->where('ncr.number', $ncr->number)
            ->where('ncr.status', Ncr::OPEN)
            ->where('inspection.number', $inspection->number)
            ->where('inspection.disposition', 'rework')
            ->where('jobCard.number', $this->jobCard->number)
            ->where('product.id', $this->jobCard->product_id)
            ->where('pendingAction.kind', 'rework')
            ->where('pendingAction.status', 'applied'));
});

it('lets an authorised user assign the NCR and refuses everyone else', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = latestNcr();

    $this->actingAs($this->qc)
        ->post("/ncrs/{$ncr->id}/assign", ['owner_id' => $this->quality->id])
        ->assertSessionHasNoErrors();

    expect($ncr->refresh()->owner_id)->toBe($this->quality->id);

    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail())
        ->post("/ncrs/{$ncr->id}/assign", ['owner_id' => $this->qc->id])
        ->assertForbidden();

    expect($ncr->refresh()->owner_id)->toBe($this->quality->id);
});

it('records investigation, root cause and corrective action as CAPA', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = walkNcr($this, latestNcr(), 'investigating');

    expect($ncr->status)->toBe(Ncr::INVESTIGATING);

    $corrective = Capa::query()->where('ncr_id', $ncr->id)->where('kind', Capa::KIND_CORRECTIVE)->firstOrFail();
    $preventive = Capa::query()->where('ncr_id', $ncr->id)->where('kind', Capa::KIND_PREVENTIVE)->firstOrFail();

    expect($corrective->root_cause)->toBe('Registration drift on the press.')
        ->and($corrective->action)->toBe('Reset the impression and reprint the lot.')
        ->and($preventive->action)->toBe('Add a mid-shift registration check.');
});

it('walks open → investigating → action_taken → verified → closed', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = walkNcr($this, latestNcr(), 'closed');

    expect($ncr->status)->toBe(Ncr::CLOSED)
        ->and($ncr->closed_on?->toDateString())->toBe(now()->toDateString());
});

it('rejects an invalid transition and a close before investigation', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = latestNcr();

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/close")->assertSessionHas('error');
    expect($ncr->refresh()->status)->toBe(Ncr::OPEN);

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/disposition")->assertSessionHas('error');
    expect($ncr->refresh()->status)->toBe(Ncr::OPEN);
});

it('cannot close while the CAPA action is still incomplete', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = walkNcr($this, latestNcr(), 'investigating');

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/close")->assertSessionHas('error');
    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/verify", ['effectiveness' => 'effective'])
        ->assertSessionHas('error');

    expect($ncr->refresh()->status)->toBe(Ncr::INVESTIGATING);
});

it('cannot close a downgrade NCR while the unimplemented lot conversion is pending', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'downgrade');
    $ncr = walkNcr($this, latestNcr(), 'action_taken');

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/verify", ['effectiveness' => 'effective'])
        ->assertSessionHas('error');

    expect($ncr->refresh()->status)->toBe(Ncr::ACTION_TAKEN)
        ->and($ncr->pendingAction()['status'])->toBe('pending');
});

it('keeps the P1-3 rework loop intact when the NCR later closes', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    expect($final->status)->toBe(JobCardOperation::READY)
        ->and($this->jobCard->refresh()->status)->toBe(JobCard::IN_PRODUCTION);

    walkNcr($this, latestNcr(), 'closed');

    expect($this->jobCard->refresh()->status)->toBe(JobCard::IN_PRODUCTION)
        ->and($final->refresh()->status)->toBe(JobCardOperation::READY)
        ->and($this->jobCard->operations()->where('status', JobCardOperation::READY)->count())->toBe(1);
});

it('does not post stock when a scrap NCR is investigated and closed', function (): void {
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 800, $this->fgWarehouseId, (string) Str::uuid());

    postRejectedInspection($this, majors: 50, disposition: 'scrap');

    expect(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('blocked');

    $ledgerBefore = DB::table('stock_ledger')->count();

    walkNcr($this, latestNcr(), 'closed');

    expect(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(DB::table('stock_lots')->where('id', $receipt->lot_id)->value('status'))->toBe('blocked')
        ->and($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING);
});

it('audits creation, assignment, investigation and every transition including close', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = latestNcr();

    expect(DB::table('audit_logs')
        ->where('auditable_type', Ncr::class)
        ->where('auditable_id', $ncr->id)
        ->where('event', 'created')
        ->exists())->toBeTrue();

    walkNcr($this, $ncr, 'closed');

    $events = DB::table('audit_logs')
        ->where('auditable_type', Ncr::class)
        ->where('auditable_id', $ncr->id)
        ->orderBy('id')
        ->get(['event', 'old_values', 'new_values']);

    expect($events->pluck('event'))->toContain('created', 'updated', 'status_changed')
        ->and($events->where('event', 'status_changed')->count())->toBe(4);

    $statuses = $events->where('event', 'status_changed')->map(
        fn ($row): string => json_decode((string) $row->new_values, true)['status'],
    )->values()->all();

    expect($statuses)->toBe([Ncr::INVESTIGATING, Ncr::ACTION_TAKEN, Ncr::VERIFIED, Ncr::CLOSED]);
});

it('rejects cross-role access at the route', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = latestNcr();

    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail())
        ->get('/ncrs')->assertForbidden();
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail())
        ->get("/ncrs/{$ncr->id}")->assertForbidden();
    $this->actingAs(User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail())
        ->get("/ncrs/{$ncr->id}")->assertForbidden();

    $this->actingAs(User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail())
        ->get("/ncrs/{$ncr->id}")->assertOk();
    $this->actingAs(User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail())
        ->post("/ncrs/{$ncr->id}/assign", ['owner_id' => $this->qc->id])
        ->assertForbidden();
});

it('treats a duplicate close as a no-op rather than corrupting the NCR', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = walkNcr($this, latestNcr(), 'closed');
    $closedOn = $ncr->closed_on?->toDateString();

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/close")->assertSessionHasNoErrors();

    expect($ncr->refresh()->status)->toBe(Ncr::CLOSED)
        ->and($ncr->closed_on?->toDateString())->toBe($closedOn)
        ->and(DB::table('audit_logs')
            ->where('auditable_type', Ncr::class)
            ->where('auditable_id', $ncr->id)
            ->where('event', 'status_changed')
            ->count())->toBe(4);
});

it('refuses investigation without an owner', function (): void {
    postRejectedInspection($this, majors: 50, disposition: 'rework');
    $ncr = latestNcr();

    $this->actingAs($this->qc)->post("/ncrs/{$ncr->id}/investigate", [
        'root_cause' => 'Unknown',
        'action' => 'Reprint',
    ])->assertSessionHas('error');

    expect($ncr->refresh()->status)->toBe(Ncr::OPEN)
        ->and(Capa::query()->where('ncr_id', $ncr->id)->count())->toBe(0);
});
