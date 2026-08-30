<?php

declare(strict_types=1);

use App\Support\Platform\QueueHealth;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Notifications are queued, so "no worker" and "notifications are broken" look identical from
 * every screen in the application. These assert that the difference is reported.
 */
function queueHealthWith(?int $pending, string $driver = 'redis'): QueueHealth
{
    config()->set('queue.default', $driver);

    $connection = Mockery::mock();
    $connection->shouldReceive('size')->andReturnUsing(
        fn () => $pending ?? throw new RuntimeException('unreachable'),
    );

    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->andReturn($connection);

    return new QueueHealth(Cache::store(), $factory);
}

beforeEach(function (): void {
    Cache::forget(QueueHealth::CACHE_KEY);
});

it('needs no worker when jobs run inline', function (): void {
    $report = queueHealthWith(0, 'sync')->report();

    expect($report['needs_worker'])->toBeFalse()
        ->and($report['healthy'])->toBeTrue()
        ->and($report['status'])->toBe('not_required');
});

it('reports the queue as unavailable when work is waiting and no worker has run', function (): void {
    $report = queueHealthWith(7)->report();

    expect($report['status'])->toBe('unavailable')
        ->and($report['healthy'])->toBeFalse()
        ->and($report['pending'])->toBe(7)
        // The message has to name the fix, not just the symptom.
        ->and($report['detail'])->toContain('queue:work');
});

it('does not cry wolf when the queue is simply empty', function (): void {
    // A quiet Sunday produces no heartbeat either. Reporting that as a fault trains people to
    // ignore the warning that matters.
    $report = queueHealthWith(0)->report();

    expect($report['status'])->toBe('idle')
        ->and($report['healthy'])->toBeTrue();
});

it('reports a healthy queue once a worker has finished a job', function (): void {
    $health = queueHealthWith(3);
    $health->recordHeartbeat();

    $report = $health->report();

    expect($report['status'])->toBe('running')
        ->and($report['healthy'])->toBeTrue()
        ->and($report['last_seen_at'])->not->toBeNull();
});

it('treats a backlog behind a long-dead worker as unavailable', function (): void {
    Cache::put(
        QueueHealth::CACHE_KEY,
        now()->subSeconds(QueueHealth::STALE_AFTER_SECONDS + 60)->toIso8601String(),
        now()->addHour(),
    );

    $report = queueHealthWith(2)->report();

    expect($report['status'])->toBe('unavailable')
        ->and($report['healthy'])->toBeFalse();
});

it('survives a queue backend it cannot reach', function (): void {
    // Redis being down is a different fault from nobody draining the queue; the settings
    // screen must not white-screen because of it.
    $report = queueHealthWith(null)->report();

    expect($report['pending'])->toBeNull()
        ->and($report)->toHaveKey('status');
});

it('exposes the report to an administrator on the settings screen', function (): void {
    $this->actingAs(App\Models\User::query()->where('email', 'admin@octapussolution.com')->firstOrFail())
        ->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Settings')->has('queue.status'));
});

it('exits non-zero from the console when nothing is draining the queue', function (): void {
    app()->instance(QueueHealth::class, queueHealthWith(5));

    $this->artisan('queue:health')->assertExitCode(1);
});
