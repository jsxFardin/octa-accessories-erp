<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Quality\Models\QcInspection;
use Illuminate\Support\Facades\DB;

/**
 * P0-1 regression — the stage vocabulary the application accepts must be exactly the
 * vocabulary `qc_inspections_stage_chk` admits. The bug this pins: the controller accepted
 * `pre_dispatch`, the CHECK constraint only knew `pre_shipment`, and the inspector got a 500
 * with the inspection lost after validation had already said yes.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
});

it('creates a pre-shipment inspection successfully', function (): void {
    $response = $this->post('/qc-inspections', [
        'stage' => 'pre_shipment',
        'lot_size' => 500,
        'major_found' => 0,
        'minor_found' => 0,
        'critical_found' => 0,
    ]);

    $inspection = QcInspection::query()->where('stage', 'pre_shipment')->latest('id')->first();

    expect($inspection)->not->toBeNull()
        ->and($inspection->result)->toBe('accepted')
        // BR-30 — the verdict fields are computed from the AQL plan, not typed.
        ->and($inspection->sample_size)->toBeGreaterThan(0);

    $response->assertRedirect(route('qc-inspections.show', $inspection));
});

it('accepts every stage the form offers without tripping the CHECK constraint', function (string $stage): void {
    // The list here mirrors the form and the controller; the insert below proves each value
    // also satisfies qc_inspections_stage_chk. Vocabulary drift fails this test, not production.
    $this->post('/qc-inspections', [
        'stage' => $stage,
        'lot_size' => 200,
        'major_found' => 0,
    ])->assertSessionHasNoErrors();

    expect(QcInspection::query()->where('stage', $stage)->exists())->toBeTrue();
})->with(['incoming', 'in_process', 'final', 'pre_shipment']);

it('rejects the retired pre_dispatch spelling and other unknown stages', function (string $stage): void {
    $before = QcInspection::query()->count();

    $this->post('/qc-inspections', [
        'stage' => $stage,
        'lot_size' => 200,
        'major_found' => 0,
    ])->assertSessionHasErrors('stage');

    expect(QcInspection::query()->count())->toBe($before);
})->with(['pre_dispatch', 'outgoing', '']);

it('keeps the application vocabulary inside the database constraint vocabulary', function (): void {
    // Belt and braces: read the CHECK back from the schema and assert the application's list
    // is a subset. If someone widens the form again without widening the constraint, this
    // names the drift directly.
    $check = (string) DB::table('information_schema.CHECK_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('CONSTRAINT_NAME', 'qc_inspections_stage_chk')
        ->value('CHECK_CLAUSE');

    foreach (['incoming', 'in_process', 'final', 'pre_shipment'] as $stage) {
        expect($check)->toContain($stage);
    }

    expect($check)->not->toContain('pre_dispatch');
});
