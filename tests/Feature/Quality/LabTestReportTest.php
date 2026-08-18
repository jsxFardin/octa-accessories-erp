<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Quality\Models\TestReport;
use Illuminate\Support\Facades\DB;

/**
 * QL-5 / QL-6 — lab test worksheet, auto-verdict, certificate issuance and immutability.
 */
beforeEach(function (): void {
    $this->labTech = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->labTests = DB::table('lab_tests')->where('is_active', true)->get();
});

function ql5CreateReport(object $test, ?array $overrides = null): TestReport
{
    $results = $overrides ?? $test->labTests->map(fn ($t) => [
        'lab_test_id' => $t->id,
        'result_value' => match ($t->scale) {
            'grey' => '4.5',
            'percentage' => '1.5',
            'delta_e' => '0.5',
            'pass_fail' => 'pass',
            default => '5',
        },
    ])->all();

    $test->actingAs($test->labTech)->post('/lab/reports', [
        'tested_on' => now()->toDateString(),
        'results' => $results,
    ])->assertRedirect();

    return TestReport::query()->latest('id')->firstOrFail();
}

// ── Creation ────────────────────────────────────────────────

it('creates a test report with auto-computed verdicts', function (): void {
    $report = ql5CreateReport($this);

    expect($report->status)->toBe('draft');
    expect($report->overall_result)->toBe('pass');
    expect($report->lines()->count())->toBe($this->labTests->count());
    expect($report->lines()->where('result', 'pass')->count())->toBe($this->labTests->count());
});

it('auto-fails when a test fails', function (): void {
    $greyTest = $this->labTests->firstWhere('scale', 'grey');

    if ($greyTest === null) {
        $this->markTestSkipped('No grey-scale test seeded.');
    }

    $report = ql5CreateReport($this, [[
        'lab_test_id' => $greyTest->id,
        'result_value' => '1',
    ]]);

    expect($report->overall_result)->toBe('fail');
    expect($report->lines()->where('result', 'fail')->exists())->toBeTrue();
});

it('shows a test report with lines', function (): void {
    $report = ql5CreateReport($this);

    $this->actingAs($this->labTech)
        ->get("/lab/reports/{$report->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quality/Lab/Show')
            ->has('lines'));
});

// ── Issuance ────────────────────────────────────────────────

it('issues a test certificate (QL-6)', function (): void {
    $report = ql5CreateReport($this);

    $this->actingAs($this->labTech)
        ->post("/lab/reports/{$report->id}/transition", ['to' => 'issued'])
        ->assertRedirect();

    $report->refresh();
    expect($report->status)->toBe('issued');
    expect($report->number)->not->toBeNull();
    expect($report->issued_at)->not->toBeNull();
});

it('blocks issuing a report with no results', function (): void {
    $report = ql5CreateReport($this);
    $report->lines()->delete();

    $this->actingAs($this->labTech)
        ->post("/lab/reports/{$report->id}/transition", ['to' => 'issued'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($report->refresh()->status)->toBe('draft');
});

it('cancels a draft report', function (): void {
    $report = ql5CreateReport($this);

    $this->actingAs($this->labTech)
        ->post("/lab/reports/{$report->id}/transition", ['to' => 'cancelled'])
        ->assertRedirect();

    expect($report->refresh()->status)->toBe('cancelled');
});

it('rejects lab access from unauthorized users', function (): void {
    $this->actingAs($this->operator)
        ->get('/lab')
        ->assertForbidden();
});

it('lists test reports on the lab index', function (): void {
    ql5CreateReport($this);

    $this->actingAs($this->labTech)
        ->get('/lab')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Quality/Lab/Index')
            ->has('reports.data'));
});
