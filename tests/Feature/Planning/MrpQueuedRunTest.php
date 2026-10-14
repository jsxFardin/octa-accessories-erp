<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Planning\Jobs\RunMrp;
use App\Modules\Planning\Services\MrpRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * GAP D-06 — NFR-3: the material plan is computed on the queue; the run row exists at once,
 * in `running`, so the page can show it and the planner can come back to it.
 */
it('opens the run at once and hands the computation to the queue', function (): void {
    Queue::fake();
    $this->actingAs(User::query()->where('email', 'planner@octapussolution.com')->firstOrFail());

    $this->post('/mrp/run', ['horizon_days' => 30])->assertRedirect();

    $run = DB::table('mrp_runs')->orderByDesc('id')->first();

    expect($run->status)->toBe('running');
    Queue::assertPushed(RunMrp::class, fn (RunMrp $job): bool => $job->runId === (int) $run->id && $job->horizonDays === 30);

    // The worker's work, done here: the row completes with its requirements.
    app(MrpRunner::class)->compute((int) $run->id, 30);

    expect(DB::table('mrp_runs')->where('id', $run->id)->value('status'))->toBe('completed');
});

it('finishes inline on a synchronous queue, as the test suite and composer dev run it', function (): void {
    $this->actingAs(User::query()->where('email', 'planner@octapussolution.com')->firstOrFail());

    $this->post('/mrp/run', ['horizon_days' => 30])->assertRedirect()->assertSessionHas('success', 'MRP run complete.');

    expect(DB::table('mrp_runs')->orderByDesc('id')->value('status'))->toBe('completed');
});
