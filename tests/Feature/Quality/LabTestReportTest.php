<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Quality\Models\TestReport;
use Illuminate\Support\Facades\DB;

/**
 * QL-5 / QL-6 — lab test worksheet, auto-verdict, certificate issuance and immutability.
 */
beforeEach(function (): void {
    $this->labTech = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@octapussolution.com')->firstOrFail();
    $this->labTests = DB::table('lab_tests')->where('is_active', true)->get();
});

function ql5CreateReport(object $test, ?array $overrides = null): TestReport
{
    $results = $overrides ?? $test->labTests->map(fn ($t) => [
        'lab_test_id' => $t->id,
        // The names `lab_tests_scale_chk` allows. This carried the same two wrong ones the
        // controller did ('grey', 'percentage'), so every grey and percent test fell to the
        // '5' default — and a 5% shrinkage against a 3% limit only counted as a pass because
        // the verdict comparison was inverted for exactly that scale.
        'result_value' => match ($t->scale) {
            'grey_1_5' => '4.5',
            'percent' => '1.5',
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
    // `grey_1_5` is what `lab_tests_scale_chk` allows and what the seed writes. Looking for
    // 'grey' matched nothing, so this test skipped every run — and the controller was matching
    // on the same non-existent name, which is how an unreachable branch went unnoticed.
    $greyTest = $this->labTests->firstWhere('scale', 'grey_1_5');

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

/**
 * QL-5 AC3 — the verdict rule has to match the scale, and the scale names have to be real.
 *
 * `computeVerdict()` matched on 'grey' and 'percentage'. `lab_tests_scale_chk` allows
 * grey_1_5, percent, delta_e, pass_fail and numeric — so neither arm could ever be reached and
 * both scales fell through to the numeric rule. Grey scale survived that by coincidence (same
 * comparison); percent did not. Shrinkage is a limit, not a target: 5% against a 3% pass value
 * must fail, and the numeric rule passed it. Every dimensional-shrinkage line on every lab
 * certificate was decided the wrong way round.
 */
it('fails a percentage result that is over its limit, not under it', function (): void {
    $shrinkage = $this->labTests->firstWhere('scale', 'percent');

    expect($shrinkage)->not->toBeNull()
        ->and($shrinkage->default_pass_value)->not->toBeNull();

    $limit = (float) $shrinkage->default_pass_value;

    // Over the limit: shrinking more than allowed is a failure.
    $over = ql5CreateReport($this, [[
        'lab_test_id' => $shrinkage->id,
        'result_value' => (string) ($limit + 2),
    ]]);

    expect($over->lines()->where('lab_test_id', $shrinkage->id)->value('result'))->toBe('fail');

    // Under it: shrinking less than allowed is a pass.
    $under = ql5CreateReport($this, [[
        'lab_test_id' => $shrinkage->id,
        'result_value' => (string) max(0, $limit - 1),
    ]]);

    expect($under->lines()->where('lab_test_id', $shrinkage->id)->value('result'))->toBe('pass');
});

it('passes a grey-scale result at or above its grade', function (): void {
    $grey = $this->labTests->firstWhere('scale', 'grey_1_5');

    expect($grey)->not->toBeNull();

    $grade = (float) $grey->default_pass_value;

    // Grey scale runs the other way from percent: a higher grade is a better result.
    $report = ql5CreateReport($this, [[
        'lab_test_id' => $grey->id,
        'result_value' => (string) ($grade + 0.5),
    ]]);

    expect($report->lines()->where('lab_test_id', $grey->id)->value('result'))->toBe('pass');
});

/*
 * UX audit H-38. Every catalogue test was required, so a technician who ran three of nine was
 * refused for the other six under keys no row displayed; and the form showed the house pass
 * value whoever the customer was.
 */
it('saves a report holding only the tests that were run', function (): void {
    $run = $this->labTests->take(2);

    $results = $this->labTests->values()->map(fn ($test, int $index): array => [
        'lab_test_id' => $test->id,
        'result_value' => $index < 2 ? ($test->scale === 'pass_fail' ? 'pass' : '4') : '',
    ])->all();

    $this->actingAs($this->labTech)->post('/lab/reports', ['tested_on' => now()->toDateString(), 'results' => $results])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $report = TestReport::query()->latest('id')->firstOrFail();

    expect(DB::table('test_report_lines')->where('test_report_id', $report->id)->pluck('lab_test_id')->sort()->values()->all())
        ->toBe($run->pluck('id')->sort()->values()->all());
});

it('refuses a report with no results, and a result that does not fit its scale, on its own row', function (): void {
    $blank = $this->labTests->map(fn ($test): array => ['lab_test_id' => $test->id, 'result_value' => ''])->all();

    $this->actingAs($this->labTech)->post('/lab/reports', ['tested_on' => now()->toDateString(), 'results' => $blank])
        ->assertSessionHasErrors(['results' => 'Enter the result of at least one test.']);

    $grey = $this->labTests->values()->search(fn ($test): bool => $test->scale === 'grey_1_5');
    $rows = $blank;
    $rows[$grey]['result_value'] = 'four';

    // The error carries the row's own position in the list, so the form can show it there.
    $this->post('/lab/reports', ['tested_on' => now()->toDateString(), 'results' => $rows])
        ->assertSessionHasErrors(["results.{$grey}.result_value" => 'Enter a number.']);

    $rows[$grey]['result_value'] = '7';
    $this->post('/lab/reports', ['tested_on' => now()->toDateString(), 'results' => $rows])
        ->assertSessionHasErrors(["results.{$grey}.result_value" => 'A grey-scale grade is between 1 and 5.']);
});

it('shows the form the pass value the chosen customer asks for', function (): void {
    $test = $this->labTests->firstWhere('scale', 'grey_1_5');
    $customerId = (int) DB::table('customers')->value('id');

    DB::table('customer_test_requirements')->where('customer_id', $customerId)->where('lab_test_id', $test->id)->delete();
    DB::table('customer_test_requirements')->insert([
        'customer_id' => $customerId, 'lab_test_id' => $test->id, 'pass_value' => '4.5', 'is_mandatory' => true,
    ]);

    $this->actingAs($this->labTech)->get('/lab/reports/create')
        ->assertInertia(fn ($page) => $page
            ->where("thresholds.{$test->id}.pass_value", $test->default_pass_value)
            ->where("thresholds.{$test->id}.from_customer", false)
            ->where("thresholds.{$test->id}.is_mandatory", false));

    $this->get("/lab/reports/create?customer_id={$customerId}")
        ->assertInertia(fn ($page) => $page
            ->where("thresholds.{$test->id}.pass_value", '4.5')
            ->where("thresholds.{$test->id}.from_customer", true)
            ->where("thresholds.{$test->id}.is_mandatory", true));
});
