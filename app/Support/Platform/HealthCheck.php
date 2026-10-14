<?php

declare(strict_types=1);

namespace App\Support\Platform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * NFR-62 — what `/up` actually proves.
 *
 * The framework's health route answered 200 as long as PHP booted, which is true of a server
 * whose database is down. A load balancer or a watchdog reading it needs the dependencies
 * the application cannot work without: the database, the cache the device sessions live in,
 * and a writable disk. The queue worker is reported, not failed on — its absence degrades
 * notifications, it does not take the application down, and `queue:health` already alerts.
 *
 * @return array<string, string>
 */
class HealthCheck
{
    public function __construct(private readonly QueueHealth $queue) {}

    /** @return array<string, string> status per dependency; throws on the first hard failure */
    public function run(): array
    {
        $status = [];

        DB::select('select 1');
        $status['database'] = 'ok';

        $probe = 'health:'.Str::random(8);
        Cache::put($probe, 1, 10);

        if (Cache::get($probe) !== 1) {
            throw new RuntimeException('Cache store does not read back what it was given.');
        }

        Cache::forget($probe);
        $status['cache'] = 'ok';

        $file = 'health/'.Str::random(8).'.txt';
        Storage::disk('local')->put($file, 'ok');

        if (Storage::disk('local')->get($file) !== 'ok') {
            throw new RuntimeException('Local storage is not writable.');
        }

        Storage::disk('local')->delete($file);
        $status['storage'] = 'ok';

        $status['queue'] = $this->queue->report()['healthy'] ? 'ok' : 'degraded';

        return $status;
    }
}
