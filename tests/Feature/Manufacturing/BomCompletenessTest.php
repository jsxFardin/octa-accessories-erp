<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * I7 — a job cannot complete with BOM material it never drew.
 *
 * Cartons and polybags sit on the BOM and in the store. A job that finished without issuing
 * them left the store's records holding packaging that had physically gone, and nothing said
 * so until the next physical count found the gap.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    $this->jobCard = JobCard::query()->whereNotNull('bom_id')->firstOrFail();
    $this->jobCard->forceFill(['status' => JobCard::QC_PENDING])->save();
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED]);

    // An accepted final inspection, so P1-1 is not what refuses the transition.
    $this->post('/qc-inspections', [
        'job_card_id' => $this->jobCard->id,
        'stage' => 'final',
        'lot_size' => 500,
        'major_found' => 0,
    ])->assertSessionHasNoErrors();

    $this->states = app(JobCardStateMachine::class);
});

it('refuses completion while a BOM item has nothing issued against it', function (): void {
    DB::table('material_issues')->where('job_card_id', $this->jobCard->id)->update(['status' => 'cancelled']);

    expect(fn () => $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED))
        ->toThrow(TransitionDenied::class);

    expect($this->jobCard->refresh()->status)->toBe(JobCard::QC_PENDING);
});

it('completes when the shortfall is waived by someone who may waive it', function (): void {
    DB::table('material_issues')->where('job_card_id', $this->jobCard->id)->update(['status' => 'cancelled']);

    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED, [
        'material_waiver_reason' => 'Ran on packaging left over from the previous programme.',
    ]);

    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});

it('completes without a waiver once every BOM item has been issued', function (): void {
    $bom = $this->jobCard->bom;

    foreach ($bom->lines as $index => $line) {
        $issued = DB::table('material_issue_lines as mil')
            ->join('material_issues as mi', 'mi.id', '=', 'mil.material_issue_id')
            ->where('mi.job_card_id', $this->jobCard->id)
            ->where('mi.status', 'posted')
            ->where('mil.item_id', $line->item_id)
            ->exists();

        if ($issued) {
            continue;
        }

        // Recorded directly: the point under test is the guard, not the issue screen.
        $lot = DB::table('stock_lots')->where('item_id', $line->item_id)->first();
        $issueId = DB::table('material_issues')->insertGetId([
            'number' => "MI-BOM-{$this->jobCard->id}-{$index}",
            'job_card_id' => $this->jobCard->id,
            'warehouse_id' => $lot?->warehouse_id ?? DB::table('warehouses')->value('id'),
            'issued_on' => now()->toDateString(),
            'issue_type' => 'issue',
            'status' => 'posted',
            'created_at' => now(),
        ]);

        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $issueId,
            'line_no' => 1,
            'item_id' => $line->item_id,
            'lot_id' => $lot?->id,
            'uom_id' => $line->uom_id,
            'qty' => 1,
            'unit_cost' => 1,
        ]);
    }

    $this->states->transition($this->jobCard->refresh(), JobCard::COMPLETED);

    expect($this->jobCard->refresh()->status)->toBe(JobCard::COMPLETED);
});
