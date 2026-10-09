<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use Illuminate\Support\Facades\DB;

/**
 * Release gate (spec §1, step 9): material, artwork, mould, machine and QC plan all in place,
 * or the job is not released. Machine and QC plan were not checked before.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->gate = app(JobCardReleaseGate::class);
});

it('passes the seeded job: every operation has a machine and the routing ends in an inspection', function (): void {
    $report = $this->gate->evaluate($this->jobCard);

    expect($report['checks']['machines']['ok'])->toBeTrue()
        ->and($report['checks']['qc_plan']['ok'])->toBeTrue()
        ->and($report['checks'])->toHaveKeys(['artwork', 'bom', 'tools', 'machines', 'qc_plan', 'material']);
});

it('refuses release while an operation has no machine, and names the operation', function (): void {
    $operation = $this->jobCard->operations()->orderBy('sequence_no')->firstOrFail();
    DB::table('job_card_operations')->where('id', $operation->id)->update(['machine_id' => null]);

    $report = $this->gate->evaluate($this->jobCard->fresh());

    expect($report['ready'])->toBeFalse()
        ->and($report['checks']['machines']['ok'])->toBeFalse()
        ->and($report['checks']['machines']['detail'])->toContain($operation->name);

    $this->actingAs($this->admin)
        ->post("/job-cards/{$this->jobCard->id}/transition", [
            'to' => 'released',
            'material_waiver_reason' => 'Yarn arriving tomorrow.',
        ]);

    expect(session('error'))->toContain('Machine on every operation')
        ->and($this->jobCard->fresh()->status)->not->toBe(JobCard::RELEASED);
});

it('refuses release with no QC step and no QC plan, and accepts once the item names a plan', function (): void {
    DB::table('job_card_operations')->where('job_card_id', $this->jobCard->id)->update(['requires_qc' => false]);
    DB::table('items')->where('id', $this->jobCard->product->item_id)->update(['qc_plan_ref' => null]);

    expect($this->gate->evaluate($this->jobCard->fresh())['checks']['qc_plan']['ok'])->toBeFalse();

    DB::table('items')->where('id', $this->jobCard->product->item_id)->update(['qc_plan_ref' => 'AQL 2.5 / II']);

    $report = $this->gate->evaluate($this->jobCard->fresh());

    expect($report['checks']['qc_plan']['ok'])->toBeTrue()
        ->and($report['checks']['qc_plan']['detail'])->toContain('AQL 2.5 / II');
});
