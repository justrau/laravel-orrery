<?php

namespace Justrau\Orrery\Tests\Feature\Console;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Database\Models\FailedTag;
use Justrau\Orrery\Database\Models\Job;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class TickCommandTest extends TestCase
{
    use SeedsSamples;

    /**
     * Rejects: a trim that deletes pending or reserved rows, or that ignores finished_at or the retention of the row's bucket.
     */
    public function test_tick_trims_expired_rows_for_each_bucket(): void
    {
        config()->set('orrery.trim', ['completed' => 60, 'failed' => 600, 'monitored' => 900] + (array) config('orrery.trim'));

        $minutesAgo = static fn (int $minutes): float => (float) Carbon::now()->subMinutes($minutes)->format('U.u');

        $kept = [
            $this->makeJobRow('completed', $minutesAgo(59)),
            $this->makeJobRow('failed', $minutesAgo(599)),
            // A monitored row keeps the larger of its state and monitored retention.
            $this->makeJobRow('failed', $minutesAgo(899), monitored: true),
            $this->makeJobRow('completed', $minutesAgo(899), monitored: true),
            // Unfinished rows have no finished_at, however old they are.
            $this->makeJobRow('pending', null, pushedAt: $minutesAgo(100_000)),
            $this->makeJobRow('reserved', null, pushedAt: $minutesAgo(100_000)),
        ];

        $expiredCompleted = $this->makeJobRow('completed', $minutesAgo(61));
        $expiredFailed = $this->makeJobRow('failed', $minutesAgo(601));
        $expiredMonitored = $this->makeJobRow('failed', $minutesAgo(901), monitored: true);

        $this->artisan('orrery:tick')->assertExitCode(0);

        $remaining = Job::query()->pluck('uuid')->all();

        $this->assertEqualsCanonicalizing($kept, $remaining);
        $this->assertNotContains($expiredCompleted, $remaining);
        $this->assertNotContains($expiredFailed, $remaining);
        $this->assertNotContains($expiredMonitored, $remaining);
    }

    /**
     * Rejects: failed-tag counts and page ids that retain a trimmed job.
     */
    public function test_tick_deletes_the_failed_tags_of_trimmed_failures(): void
    {
        config()->set('orrery.trim', ['failed' => 600] + (array) config('orrery.trim'));
        Orrery::auth(static fn (): bool => true);

        $minutesAgo = static fn (int $minutes): float => (float) Carbon::now()->subMinutes($minutes)->format('U.u');

        $expired = $this->makeJobRow('failed', $minutesAgo(601));
        $retained = $this->makeJobRow('failed', $minutesAgo(599));

        $failedTags = static fn (string $tag): int => FailedTag::query()->where('tag', $tag)->count();
        FailedTags::write($this->mirror(), $expired, ['orders', 'expired-only'], Clock::toDecimal($minutesAgo(601)));
        FailedTags::write($this->mirror(), $retained, ['orders'], Clock::toDecimal($minutesAgo(599)));

        $this->assertSame(2, $failedTags('orders'));

        $this->artisan('orrery:tick')->assertExitCode(0);

        $this->assertSame([$retained], Job::query()->pluck('uuid')->all());
        $this->assertSame(
            [['job_uuid' => $retained, 'tag' => 'orders']],
            FailedTag::query()->orderBy('job_uuid')->orderBy('tag')->get(['job_uuid', 'tag'])->toArray(),
            'the failed tags of the trimmed job survive',
        );
        $this->assertSame(1, $failedTags('orders'));
        $this->assertSame(0, $failedTags('expired-only'));

        // The list engine's failed tab of the tag, and the tag's failed count (4.11).
        $this->getJson('/orrery/api/jobs?status=failed&tag=orders')
            ->assertOk()
            ->assertJsonPath('total', ['count' => 1, 'at_least' => false])
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.id', $retained);

        $this->app->make(MonitoringVersion::class)->monitor('orders');
        $this->getJson('/orrery/api/tags')
            ->assertOk()
            ->assertJsonPath('tags.0.tag', 'orders')
            ->assertJsonPath('tags.0.failed', ['count' => 1, 'at_least' => false]);
    }

    public function test_tick_dispatches_long_wait_detected_when_threshold_exceeded(): void
    {
        config()->set('orrery.waits', ['database:default' => 1]);

        $this->seedCompletion('queue', 'database:default', 5000.0);

        Queue::push(new BasicJob);

        Event::fake([LongWaitDetected::class]);

        $this->artisan('orrery:tick')->assertExitCode(0);

        Event::assertDispatched(
            LongWaitDetected::class,
            fn (LongWaitDetected $e): bool => $e->connection === 'database' && $e->queue === 'default',
        );
    }

    /**
     * Rejects: a long-wait dispatch outside the watcher, so an application
     * listener that throws ends the alerts of every later queue.
     */
    public function test_a_throwing_long_wait_listener_stops_no_later_alert(): void
    {
        config()->set('orrery.waits', ['database:default' => 1, 'database:emails' => 1]);

        $this->seedCompletion('queue', 'database:default', 5000.0);
        $this->seedCompletion('queue', 'database:emails', 5000.0);
        Queue::push(new BasicJob);
        Queue::push(new BasicJob, '', 'emails');

        $queues = [];
        $this->app['events']->listen(LongWaitDetected::class, static function (LongWaitDetected $event) use (&$queues): never {
            $queues[] = $event->queue;

            throw new \RuntimeException('The application listener failed.');
        });

        $this->artisan('orrery:tick')->assertExitCode(0);

        sort($queues);
        $this->assertSame(['default', 'emails'], $queues);
    }

    public function test_tick_does_not_dispatch_long_wait_when_below_threshold(): void
    {
        config()->set('orrery.waits', ['database:default' => 1_000_000]);

        $this->seedCompletion('queue', 'database:default', 1.0);
        Queue::push(new BasicJob);

        Event::fake([LongWaitDetected::class]);

        $this->artisan('orrery:tick')->assertExitCode(0);

        Event::assertNotDispatched(LongWaitDetected::class);
    }

    protected function makeJobRow(string $status, ?float $finishedAt, bool $monitored = false, ?float $pushedAt = null): string
    {
        $uuid = (string) Uuid::uuid4();

        Job::query()->create([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'name' => 'BasicJob',
            'type' => 'job',
            'status' => $status,
            'silenced' => false,
            'monitored' => $monitored,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $pushedAt ?? $finishedAt ?? microtime(true),
            'finished_at' => $finishedAt,
            'failed_at' => $status === 'failed' ? $finishedAt : null,
            'updated_at' => microtime(true),
        ]);

        return $uuid;
    }
}
