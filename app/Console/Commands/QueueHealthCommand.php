<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Platform\QueueHealth;
use Illuminate\Console\Command;

/**
 * `php artisan queue:health` — the same report the Settings screen shows, for a deploy check
 * or a monitoring cron. Exits non-zero when nothing is draining the queue, so it can be wired
 * to an alert without parsing output.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'queue:health';

    protected $description = 'Report whether a queue worker is draining the queue (notifications depend on one)';

    public function handle(QueueHealth $health): int
    {
        $report = $health->report();

        $this->components->twoColumnDetail('Connection', $report['driver']);
        $this->components->twoColumnDetail('Worker required', $report['needs_worker'] ? 'yes' : 'no');
        $this->components->twoColumnDetail('Jobs waiting', $report['pending'] === null ? 'unreachable' : (string) $report['pending']);
        $this->components->twoColumnDetail('Worker last seen', $report['last_seen_at'] ?? 'never');
        $this->components->twoColumnDetail('Status', $report['status']);

        $report['healthy']
            ? $this->components->info($report['detail'])
            : $this->components->error($report['detail']);

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
