<?php

declare(strict_types=1);

namespace App\Support\Platform;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Is anything actually draining the queue?
 *
 * `DocumentNotification` implements `ShouldQueue`, so every notification this application
 * raises — a credit hold, an NCR, an overdue invoice — is written by a worker, not by the
 * request that triggered it. With no worker running, nothing fails and nothing is logged: the
 * inbox is simply always empty, which reads as "notifications are broken" rather than "nobody
 * started the worker". That is a deployment mistake this class exists to name.
 *
 * The signal is two facts, neither of which is sufficient alone:
 *
 *   - a heartbeat, written by {@see \App\Providers\AppServiceProvider} on every job a worker
 *     finishes, so "a worker was alive N seconds ago" is knowable without shelling out;
 *   - the queue's own backlog, so a worker that died while jobs were waiting is distinguished
 *     from an idle system that has simply had nothing to do.
 *
 * A quiet factory legitimately produces no heartbeat for hours, so a stale heartbeat alone is
 * not a fault. Work waiting with no recent heartbeat is.
 */
class QueueHealth
{
    /** How long a heartbeat stays meaningful. Longer than any realistic job. */
    public const HEARTBEAT_TTL_MINUTES = 60;

    /** Backlog older than this with no heartbeat means nothing is consuming the queue. */
    public const STALE_AFTER_SECONDS = 300;

    public const CACHE_KEY = 'queue:worker:last-seen';

    public function __construct(
        private readonly Cache $cache,
        private readonly QueueFactory $queue,
    ) {}

    /** Called when a worker finishes a job. Cheap: one cache write per job. */
    public function recordHeartbeat(): void
    {
        $this->cache->put(self::CACHE_KEY, now()->toIso8601String(), now()->addMinutes(self::HEARTBEAT_TTL_MINUTES));
    }

    /**
     * @return array{
     *     driver: string,
     *     needs_worker: bool,
     *     pending: int|null,
     *     last_seen_at: string|null,
     *     seconds_since_seen: int|null,
     *     healthy: bool,
     *     status: string,
     *     detail: string
     * }
     */
    public function report(): array
    {
        $driver = (string) config('queue.default');

        // `sync` runs the job inside the request that dispatched it, so there is nothing to
        // run and nothing to check. Tests use it; so may a single-box install.
        if ($driver === 'sync') {
            return $this->result($driver, false, null, null, true, 'not_required',
                'Jobs run inline on this connection, so no worker is needed.');
        }

        $lastSeen = $this->lastSeen();
        $since = $lastSeen?->diffInSeconds(now());
        $pending = $this->pending();

        // Nothing waiting: a worker may be idle or absent, and from here the two are
        // indistinguishable. Saying "unavailable" on a quiet Sunday would train people to
        // ignore the warning, so an empty queue is reported as unknown, not as a fault.
        if ($pending !== null && $pending === 0 && $lastSeen === null) {
            return $this->result($driver, true, $pending, null, true, 'idle',
                'Nothing is queued and no worker has reported in. Start one before relying on notifications.');
        }

        if ($pending !== null && $pending > 0 && ($lastSeen === null || $since > self::STALE_AFTER_SECONDS)) {
            return $this->result($driver, true, $pending, $lastSeen, false, 'unavailable', sprintf(
                '%d job%s waiting and no worker has finished one %s. Notifications will not appear until '
                .'`php artisan queue:work` is running.',
                $pending,
                $pending === 1 ? ' is' : 's are',
                $lastSeen === null ? 'at all' : "in {$since} seconds",
            ));
        }

        if ($lastSeen === null) {
            return $this->result($driver, true, $pending, null, true, 'unknown',
                'No worker has reported in yet. It will show here once one finishes a job.');
        }

        return $this->result($driver, true, $pending, $lastSeen, true, 'running',
            'A worker finished a job '.$lastSeen->diffForHumans().'.');
    }

    private function lastSeen(): ?Carbon
    {
        $raw = $this->cache->get(self::CACHE_KEY);

        return is_string($raw) ? Carbon::parse($raw) : null;
    }

    /**
     * The backlog on the default connection, or null when the driver cannot be reached —
     * a queue that cannot be counted is a separate problem from one nobody is draining, and
     * this screen must not blow up because Redis is down.
     */
    private function pending(): ?int
    {
        try {
            return (int) $this->queue->connection()->size();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function result(
        string $driver,
        bool $needsWorker,
        ?int $pending,
        ?Carbon $lastSeen,
        bool $healthy,
        string $status,
        string $detail,
    ): array {
        return [
            'driver' => $driver,
            'needs_worker' => $needsWorker,
            'pending' => $pending,
            'last_seen_at' => $lastSeen?->toIso8601String(),
            'seconds_since_seen' => $lastSeen === null ? null : (int) $lastSeen->diffInSeconds(now()),
            'healthy' => $healthy,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
