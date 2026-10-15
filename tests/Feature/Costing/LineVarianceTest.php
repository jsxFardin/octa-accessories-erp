<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostStages;
use App\Modules\Costing\Services\PostProductionCosting;
use App\Modules\Costing\Services\PreProductionCosting;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Reporting\Queries\CostVarianceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 — pre against post, line by line: a material that cost more (price) is told apart
 * from a material of which more was used (usage), and the cost variance report foots.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $this->job = JobCard::query()->whereNotNull('sales_order_line_id')->with('operations')->firstOrFail();
    app(PreProductionCosting::class)->snapshot($this->job->salesOrderLine->salesOrder);

    $machine = DB::table('machines')->first();
    DB::table('machines')->where('id', $machine->id)->update(['hourly_rate' => 600, 'kw_rating' => 10]);

    foreach ($this->job->operations as $operation) {
        DB::table('job_card_operations')->where('id', $operation->id)->update([
            'machine_id' => $machine->id, 'actual_minutes' => 120, 'input_qty' => 50000, 'good_qty' => 50000, 'status' => 'completed',
        ]);
    }

    $this->post = app(PostProductionCosting::class)->snapshot($this->job->fresh());
    $this->pre = CostSheet::query()->where('sales_order_line_id', $this->job->sales_order_line_id)->where('stage', CostSheet::PRE_PRODUCTION)->firstOrFail();
    $this->scale = (float) $this->post->basis_qty / (float) $this->pre->basis_qty;
});

it('splits each material into usage and price, scaled to what the job made, and each operation into hours', function (): void {
    $lines = app(CostStages::class)->forJobCard($this->job->fresh())['line_variance'];

    expect($lines['scale'])->toBe(round($this->scale, 6))
        ->and($lines['materials'])->not->toBeEmpty()
        ->and($lines['conversion'])->not->toBeEmpty();

    foreach ($lines['materials'] as $row) {
        // Usage and price are the whole story: together they are the amount variance.
        expect(round($row['usage_variance'] + $row['price_variance'], 2))->toBe(round($row['total_variance'], 2));

        if ($row['planned'] !== null && $row['actual'] !== null) {
            expect($row['usage_variance'])->toEqualWithDelta(($row['actual']['qty'] - $row['planned']['qty']) * $row['planned']['rate'], 0.01)
                ->and($row['price_variance'])->toEqualWithDelta(($row['actual']['rate'] - $row['planned']['rate']) * $row['actual']['qty'], 0.01);
        }
    }

    // The lines foot to the elements: material and conversion variance over the job.
    $materialVariance = (float) $this->post->material_cost - (float) $this->pre->material_cost * $this->scale;
    $conversionVariance = ((float) $this->post->machine_cost + (float) $this->post->labour_cost + (float) $this->post->energy_cost)
        - ((float) $this->pre->machine_cost + (float) $this->pre->labour_cost + (float) $this->pre->energy_cost) * $this->scale;

    expect(array_sum(array_column($lines['materials'], 'total_variance')))->toEqualWithDelta($materialVariance, 0.05)
        ->and(array_sum(array_column($lines['conversion'], 'total_variance')))->toEqualWithDelta($conversionVariance, 0.05);

    // Every operation ran two hours. A product costed from its routing compares step by step; a
    // label sheet benchmarks one blended line, so the run is compared as a whole.
    $actualHours = array_sum(array_map(fn (array $row): float => $row['actual']['hours'] ?? 0.0, $lines['conversion']));
    expect($actualHours)->toBe(2.0 * $this->job->operations->count());

    // The actuals that were issued to the job are the actual lines, item by item.
    $issued = DB::table('material_issue_lines as l')->join('material_issues as mi', 'mi.id', '=', 'l.material_issue_id')
        ->where('mi.job_card_id', $this->job->id)->where('mi.status', 'posted')->distinct()->count('l.item_id');

    expect(collect($lines['materials'])->filter(fn (array $row): bool => $row['actual'] !== null)->count())->toBe($issued);
});

it('reports the variance of every closed job, footing usage, price, conversion and other to the total', function (): void {
    $report = app(CostVarianceReport::class);
    $rows = fn (array $query = []) => collect($report->paginate(Request::create('/reports/cost-variance', 'GET', $query))->items())->map(fn ($row): array => (array) $row);

    $row = $rows()->firstWhere('id', $this->post->id);

    expect($row)->not->toBeNull()
        ->and($row['number'])->toBe($this->job->number)
        ->and((float) $row['total_variance'])->toEqualWithDelta((float) $this->post->total_cost - (float) $this->pre->total_cost * $this->scale, 0.01)
        ->and((float) $row['usage_variance'] + (float) $row['price_variance'] + (float) $row['conversion_variance'] + (float) $row['other_variance'])
        ->toEqualWithDelta((float) $row['total_variance'], 0.02);

    // The report's price variance is the same figure the sheet page shows, line by line.
    $lines = app(CostStages::class)->forJobCard($this->job->fresh())['line_variance'];
    expect((float) $row['price_variance'])->toEqualWithDelta(array_sum(array_column($lines['materials'], 'price_variance')), 0.01);

    // Unexplained until the clerk writes the reason; then explained.
    expect($rows(['explained' => 'no'])->firstWhere('id', $this->post->id))->not->toBeNull()
        ->and($rows(['explained' => 'yes'])->firstWhere('id', $this->post->id))->toBeNull();

    $this->post("/cost-sheets/{$this->post->id}/reason", ['variance_reason' => 'Yarn price up 3%.'])->assertSessionHasNoErrors();

    expect($rows(['explained' => 'no'])->firstWhere('id', $this->post->id))->toBeNull()
        ->and($rows(['explained' => 'yes'])->firstWhere('id', $this->post->id)['variance_reason'])->toBe('Yarn price up 3%.');

    // Totals add across the set, and the screen opens.
    $totals = $report->totals(Request::create('/reports/cost-variance'));
    expect($totals['total_variance'])->toEqualWithDelta($rows()->sum(fn (array $r): float => (float) $r['total_variance']), 0.01);

    $this->get('/reports/cost-variance')->assertOk()->assertInertia(fn ($page) => $page->where('report.key', 'cost-variance'));
});

// GAP M-05 — MF-4 AC4 / BR-23: downtime is not machine time.
it('takes downtime out of the hours an operation is charged for', function (): void {
    $operation = $this->job->operations->first();
    $machineBefore = collect($this->post->lines)->first(fn ($line): bool => $line->cost_type === 'machine' && str_starts_with((string) $line->description, $operation->name));

    DB::table('downtime_logs')->insert([
        'machine_id' => $operation->fresh()->machine_id,
        'job_card_operation_id' => $operation->id,
        'downtime_reason_id' => DB::table('downtime_reasons')->value('id'),
        'started_at' => now()->subMinutes(30),
        'ended_at' => now(),
        'minutes' => 30,
        'reported_by' => DB::table('employees')->value('id'),
    ]);

    $post = app(PostProductionCosting::class)->snapshot($this->job->fresh());
    $machineAfter = collect($post->lines)->first(fn ($line): bool => $line->cost_type === 'machine' && str_starts_with((string) $line->description, $operation->name));

    // 120 minutes on the clock, 30 of them standing: 1.5 hours charged, not 2.
    expect((float) $machineBefore->qty)->toBe(2.0)
        ->and((float) $machineAfter->qty)->toBe(1.5)
        ->and((float) $machineAfter->amount)->toBeMoney(round((float) $machineBefore->amount * 0.75, 4))
        ->and($machineAfter->description)->toContain('less 30 min downtime');
});
