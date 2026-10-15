<?php

declare(strict_types=1);

namespace App\Modules\Planning\Jobs;

use App\Modules\Planning\Services\MrpRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * NFR-3 — the material plan runs on the worker. The run row already exists in `running`, so
 * the page can show it straight away and the planner comes back to it; a failure marks the
 * row rather than vanishing into a 500 nobody sees.
 */
class RunMrp implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $runId, public readonly int $horizonDays) {}

    public function handle(MrpRunner $runner): void
    {
        $runner->compute($this->runId, $this->horizonDays);
    }
}
