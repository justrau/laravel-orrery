<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Registration\Modules\MonitoringModule;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use Justrau\Orrery\Workers\LiveWorkers;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Wait time and long-wait alerts. The
 * rewrite of the probe WaitTimeProbeTest.
 */
final class WaitTimeTest extends TestCase
{
    use SeedsSamples;
    use WorkerFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.waits', ['*' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: counting delayed jobs as backlog.
     */
    public function test_delayed_jobs_are_not_backlog(): void
    {
        $this->seedCompletion('queue', 'database:default', 1000.0);

        for ($i = 0; $i < 20; $i++) {
            Queue::later(86400, new BasicJob);
        }

        $row = $this->row('database', 'default');
        self::assertSame(0, $row['backlog'], 'a delayed job is not executable');
        self::assertNull($row['oldest_age'], 'no executable job, so no oldest age');
        self::assertSame(0, $row['wait']);

        Event::fake([LongWaitDetected::class]);
        $this->artisan('orrery:tick')->assertExitCode(0);
        Event::assertNotDispatched(LongWaitDetected::class);
    }

    /**
     * Rejects: a runtime estimate from the latest minute only, one that counts a sample older than
     * the hour, or one that averages the minutes instead of the completions.
     */
    public function test_the_runtime_estimate_is_the_average_of_the_samples_of_the_last_hour(): void
    {
        $minute = intdiv((int) Carbon::now()->getTimestamp(), 60) * 60;
        $this->seedCompletion('queue', 'database:default', 4000.0, $minute - 600);
        $this->seedCompletion('queue', 'database:default', 4000.0, $minute - 600);
        $this->seedCompletion('queue', 'database:default', 7000.0, $minute - 1200);
        // Older than the hour: not part of the estimate.
        $this->seedCompletion('queue', 'database:default', 900000.0, $minute - 3660);

        for ($i = 0; $i < 20; $i++) {
            Queue::push(new BasicJob);
        }

        $row = $this->row('database', 'default');
        self::assertSame(20, $row['backlog']);
        self::assertEqualsWithDelta(5000.0, (float) $row['runtime_ms'], 0.001, '(4000 + 4000 + 7000) / 3 completions');
        self::assertSame(100, $row['wait'], '20 x 5000 ms / 1000 / 1 worker');

        Event::fake([LongWaitDetected::class]);
        $this->artisan('orrery:tick')->assertExitCode(0);
        Event::assertDispatched(LongWaitDetected::class, static fn (LongWaitDetected $event): bool => $event->connection === 'database' && $event->queue === 'default' && $event->seconds >= 100);
    }

    /**
     * Rejects: a runtime of 0 for a series whose samples know no runtime (unknown is never zero).
     */
    public function test_a_series_without_a_known_runtime_has_no_estimate(): void
    {
        $minute = intdiv((int) Carbon::now()->getTimestamp(), 60) * 60;
        $this->mirror()->table('orrery_samples')->insert([
            'scope' => 'queue', 'series' => \Justrau\Orrery\Monitoring\SampleCounts::series('queue', 'database', 'default'), 'connection' => 'database', 'queue' => 'default',
            'minute' => $minute - 600, 'completed' => 3, 'runtime_ms_sum' => 0, 'runtime_count' => 0,
        ]);

        for ($i = 0; $i < 5; $i++) {
            Queue::push(new BasicJob);
        }

        $row = $this->row('database', 'default');
        self::assertSame(5, $row['backlog']);
        self::assertNull($row['runtime_ms']);
        self::assertNull($row['wait'], 'a backlog with an unknown runtime has an unknown wait');
    }

    /**
     * Rejects: checking only queues with metrics.
     */
    public function test_queue_without_completions_is_checked_by_its_oldest_age(): void
    {
        $start = Carbon::now()->startOfSecond();
        Carbon::setTestNow($start);

        for ($i = 0; $i < 3; $i++) {
            Queue::pushOn('reports', new BasicJob);
        }

        Carbon::setTestNow($start->copy()->addSeconds(120));

        $row = $this->row('database', 'reports');
        self::assertSame(3, $row['backlog']);
        self::assertEqualsWithDelta(120.0, (float) $row['oldest_age'], 0.001);
        self::assertNull($row['runtime_ms']);
        self::assertNull($row['wait']);
        self::assertSame(60, $row['threshold'], 'the * key is the default threshold');

        Event::fake([LongWaitDetected::class]);
        $this->artisan('orrery:tick')->assertExitCode(0);
        Event::assertDispatched(LongWaitDetected::class, static fn (LongWaitDetected $event): bool => $event->queue === 'reports' && $event->seconds === 120);
    }

    /**
     * Rejects: a backlog of unreserved rows only.
     */
    public function test_expired_reservation_only_queue_is_backlog(): void
    {
        $now = Carbon::now()->startOfSecond();
        Carbon::setTestNow($now);
        $t = $now->getTimestamp();

        // retry_after is 90: the first reservation expired 110 s ago, the second is still valid.
        $this->insertJobsRow('stuck', $t - 300, $t - 200);
        $this->insertJobsRow('stuck', $t - 300, $t - 10);

        $row = $this->row('database', 'stuck');
        self::assertSame(1, $row['backlog'], 'only the expired reservation is executable');
        self::assertEqualsWithDelta(110.0, (float) $row['oldest_age'], 0.001, 'now - (reserved_at + retry_after)');
    }

    /**
     * Rejects: division by all workers.
     */
    public function test_estimate_divides_by_live_subscribed_workers(): void
    {
        $this->seedCompletion('queue', 'database:default', 1000.0);

        for ($i = 0; $i < 12; $i++) {
            Queue::push(new BasicJob);
        }

        $now = Carbon::now()->getTimestamp();
        $this->insertHeartbeats([
            $this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'default']),
            $this->heartbeatRow('host-a', 102, ['subscribed_queues' => 'high,default']),
            $this->heartbeatRow('host-b', 201, ['subscribed_queues' => 'default']),
            $this->heartbeatRow('host-b', 202, ['subscribed_queues' => 'emails']),
            $this->heartbeatRow('host-c', 301, ['subscribed_queues' => 'default', 'last_seen_at' => $now - 3600]),
            $this->heartbeatRow('host-c', 302, ['subscribed_queues' => 'default', 'connection' => 'other']),
        ]);

        $row = $this->row('database', 'default');
        self::assertSame(12, $row['backlog']);
        self::assertSame(4, $row['wait'], '12 x 1000 ms / 1000 / 3 live subscribed workers');
    }

    /**
     * Rejects: one read of the live workers for each queue.
     */
    public function test_measure_and_workload_read_the_live_workers_once(): void
    {
        foreach (['default', 'high', 'emails'] as $queue) {
            $this->seedCompletion('queue', 'database:'.$queue, 1000.0);
            Queue::push(new BasicJob, '', $queue);
            Queue::push(new BasicJob, '', $queue);
        }

        $this->insertHeartbeats([
            $this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'default,high']),
            $this->heartbeatRow('host-a', 102, ['subscribed_queues' => 'emails']),
        ]);

        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'orrery_worker_heartbeats')) {
                $reads++;
            }
        });

        $rows = $this->app->make(WaitTime::class)->measure();
        $waits = array_column(array_map(static fn (array $row): array => [$row['queue'], $row['wait']], $rows), 1, 0);

        self::assertSame(['default' => 2, 'emails' => 2, 'high' => 2], $waits, '2 x 1000 ms / 1000 / 1 subscribed worker');
        self::assertSame(1, $reads, 'measure() reads the heartbeats once for all queues');

        $reads = 0;
        $workload = $this->app->make(\Justrau\Orrery\Contracts\WorkloadRepository::class)->get();

        self::assertSame(['default' => 1, 'emails' => 1, 'high' => 1], array_column(array_map(static fn (array $row): array => [$row['name'], $row['processes']], $workload), 1, 0));
        self::assertSame(1, $reads, 'the workload reads the heartbeats once, for its waits and its processes (UI v1 B1)');
    }

    /**
     * A failed watched read of the live workers leaves them unknown: the
     * queue's wait is null, and its other fields still come back.
     *
     * Rejects: a wait() that reads the live workers again outside the
     * watcher after the watched read failed (one more heartbeat read, whose
     * error would fail the whole measurement), and a wait computed with 0
     * or 1 worker for workers that are unknown.
     */
    public function test_a_failed_worker_read_leaves_the_wait_unknown(): void
    {
        $this->seedCompletion('queue', 'database:default', 1000.0);
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'default'])]);

        $reads = 0;
        DB::listen(static function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'orrery_worker_heartbeats')) {
                $reads++;
            }
        });
        Watcher::failOperationsForTesting(['wait_time:workers']);

        try {
            $row = $this->row('database', 'default');
        } finally {
            Watcher::failOperationsForTesting([]);
        }

        self::assertSame([2, 1000.0, null, false], [$row['backlog'], $row['runtime_ms'], $row['wait'], $row['backlog_unknown']]);
        self::assertSame(0, $reads, 'no read of the live workers outside the watcher');
    }

    /**
     * Rejects: a wait of 0 for an unknown runtime.
     */
    public function test_unknown_runtime_gives_a_null_wait(): void
    {
        Queue::push(new BasicJob);
        Queue::push(new BasicJob);

        $row = $this->row('database', 'default');
        self::assertSame(2, $row['backlog']);
        self::assertNull($row['runtime_ms'], 'no completion and no last_runtime');
        self::assertNull($row['wait']);
    }

    /**
     * Rejects: a lease default of 90 s while Laravel's connector expires a reservation after 60 s.
     *
     * @param  'missing'|'null'  $setting
     */
    #[DataProvider('unsetRetryAfter')]
    public function test_default_retry_after_is_the_connectors_60_seconds(string $setting): void
    {
        $config = $this->app['config'];
        $queue = $config->get('queue.connections.database');
        unset($queue['retry_after']);

        if ($setting === 'null') {
            $queue['retry_after'] = null;
        }

        $config->set('queue.connections.database', $queue);
        $config->set('orrery.waits', ['database:default' => 180]);
        $this->seedCompletion('queue', 'database:default', 120000.0);

        $now = Carbon::now()->startOfSecond();
        Carbon::setTestNow($now);
        $t = $now->getTimestamp();
        $stale = $t - 600;

        // Two workers with stale heartbeats hold reservations of 75 s: expired after 60 s.
        // A third one holds a reservation of 30 s: its lease is still active.
        foreach ([[101, 75], [102, 75], [103, 30]] as [$pid, $age]) {
            $row = $this->heartbeatRow('host-a', $pid, ['last_seen_at' => $stale]);
            $this->insertHeartbeats([$row]);
            $this->reservedJob($row['worker_id'], $t - $age);
            $this->insertJobsRow('default', $t - 300, $t - $age);
        }

        $workers = $this->app->make(LiveWorkers::class);
        self::assertCount(1, $workers->list(), 'only the worker with the active lease is live');
        self::assertSame(1, $workers->capacity('database', 'default'));

        $row = $this->row('database', 'default');
        self::assertSame(2, $row['backlog'], 'the two reservations of 75 s are executable again');
        self::assertEqualsWithDelta(15.0, (float) $row['oldest_age'], 0.001);
        self::assertSame(240, $row['wait'], '2 x 120000 ms / 1000 / 1 live worker');
        self::assertCount(1, $this->app->make(WaitTime::class)->alerts(), 'the wait reaches the threshold of 180 s');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsetRetryAfter(): array
    {
        return ['missing retry_after' => ['missing'], 'null retry_after' => ['null']];
    }

    /**
     * @return array{connection: string, queue: string, backlog: int, oldest_age: ?float, runtime_ms: ?float, wait: ?int, threshold: int}
     */
    /**
     * Rejects: a backlog grouped or keyed by a collated queue name:
     * on MySQL with a case-insensitive collation `HIGH` merged into a requested `high`; and a
     * byte-exact read that cannot use the (queue, reserved_at, available_at) index (the one grouped
     * read scans that index alone). Postgres and SQLite compare bytes, so there the test checks the
     * result.
     */
    #[FreshSchema]
    public function test_backlog_reads_are_byte_exact_and_keep_the_jobs_index(): void
    {
        $this->app['config']->set('orrery.waits', ['database:high' => 60, '*' => 60]);
        $now = (int) Carbon::now()->getTimestamp();
        $this->insertJobsRow('high', $now - 30, null);
        $this->insertJobsRow('high', $now - 20, null);
        $this->insertJobsRow('HIGH', $now - 300, null);
        $this->insertJobsRow('HIGH', $now - 10, null);
        $this->insertJobsRow('HIGH', $now - 5, null);
        $this->insertJobsRow('high ', $now - 7, null);

        /** @var Migration $index */
        $index = require MonitoringModule::source();
        $index->up();

        $reads = [];
        PackageStatements::beforeEach($this->app, TestEnvironment::PRIMARY, static function (string $sql, array $bindings) use (&$reads): void {
            if (str_contains($sql, 'reserved_at') && str_contains(strtolower($sql), 'is null')) {
                $reads[] = [$sql, $bindings];
            }
        });

        try {
            self::assertSame(2, $this->row('database', 'high')['backlog']);
            self::assertSame(3, $this->row('database', 'HIGH')['backlog'], 'HIGH beside high is a row of its own');
            self::assertSame(1, $this->row('database', 'high ')['backlog'], 'a trailing space is a queue of its own');
            self::assertEqualsWithDelta(300.0, (float) $this->row('database', 'HIGH')['oldest_age'], 2.0);
            self::assertEqualsWithDelta(30.0, (float) $this->row('database', 'high')['oldest_age'], 2.0);

            $workload = (array) $this->withoutMiddleware()->getJson('/orrery/api/workload')->assertOk()->json();
            $lengths = [];

            foreach ($workload as $row) {
                $lengths[$row['name']] = $row['length'];
            }

            self::assertSame([2, 3, 1], [$lengths['high'] ?? null, $lengths['HIGH'] ?? null, $lengths['high '] ?? null], '/api/workload has the exact backlog of each queue');
        } finally {
            $index->down();
        }

        self::assertNotSame([], $reads, 'the ready backlog read was seen');

        if (DB::connection(TestEnvironment::PRIMARY)->getDriverName() === 'mysql') {
            [$sql, $bindings] = $reads[0];
            $index->up();

            try {
                $plan = DB::connection(TestEnvironment::PRIMARY)->select('EXPLAIN '.$sql, $bindings);
            } finally {
                $index->down();
            }

            $keys = strtolower((string) ($plan[0]->key ?? '').' '.(string) ($plan[0]->possible_keys ?? ''));
            self::assertStringContainsString('orrery_backlog', $keys, 'the backlog read can use the optional jobs index: '.json_encode($plan));
        }
    }

    /**
     * Rejects: a measurement that reads the jobs table of a target three
     * times (the distinct queues, the ready jobs, the expired reservations),
     * each a scan of the whole table without the optional index (review
     * follow-up). One grouped read gives every queue of the table, its
     * backlog and its oldest executable time. It would still pass a single
     * read that a later statement repeats for another target; the harness
     * has one target.
     */
    public function test_a_target_is_measured_with_one_read_of_its_jobs_table(): void
    {
        $now = (int) Carbon::now()->getTimestamp();
        $this->insertJobsRow('alpha', $now - 30, null);
        $this->insertJobsRow('alpha', $now + 600, null);
        $this->insertJobsRow('beta', $now - 900, $now - 500);
        $this->insertJobsRow('gamma', $now - 900, $now - 10);

        $reads = 0;
        PackageStatements::beforeEach($this->app, TestEnvironment::PRIMARY, static function (string $sql) use (&$reads): void {
            if (str_contains($sql, 'jobs') && ! str_contains($sql, 'orrery_')) {
                $reads++;
            }
        });
        $rows = [];

        foreach ($this->app->make(WaitTime::class)->measure() as $row) {
            if ($row['connection'] === 'database') {
                $rows[$row['queue']] = [$row['backlog'], $row['oldest_age'] === null ? null : (float) $row['oldest_age']];
            }
        }

        self::assertSame(1, $reads, 'the target read its jobs table more than once');
        // alpha: one ready job, one delayed; beta: an expired reservation (retry_after 90: since -410);
        // gamma: a live reservation, no backlog, still a queue of the table.
        self::assertSame(1, $rows['alpha'][0] ?? null);
        self::assertEqualsWithDelta(30.0, $rows['alpha'][1] ?? null, 2.0);
        self::assertSame(1, $rows['beta'][0] ?? null);
        self::assertEqualsWithDelta(410.0, $rows['beta'][1] ?? null, 2.0);
        self::assertSame([0, null], $rows['gamma'] ?? null);
    }

    /**
     * Rejects: queue names from the samples that keep themselves alive (a queue that the samples
     * only carried forward stays measured for ever), and names from samples older than the hour
     * (the metric counters retired a name after 7 days).
     */
    public function test_sampled_queue_names_need_activity_in_the_last_hour(): void
    {
        $minute = intdiv((int) Carbon::now()->getTimestamp(), 60) * 60;
        $state = static fn (string $queue, int $at, int $ready, int $completed = 0): array => [
            'scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', $queue), 'connection' => 'database', 'queue' => $queue,
            'minute' => $at, 'ready' => $ready, 'workers' => 0, 'oldest_age' => null, 'over_limit' => false, 'completed' => $completed,
        ];
        $this->mirror()->table('orrery_samples')->insert([
            // Only carried forward: an empty backlog, no worker, no outcome.
            $state('idle', $minute - 120, 0),
            $state('idle', $minute - 60, 0),
            // A backlog, and an outcome, in the last hour.
            $state('busy', $minute - 600, 3),
            $state('counted', $minute - 1200, 0, 2),
            // Active, but more than an hour ago.
            $state('old', $minute - 3900, 5, 4),
        ]);

        $names = array_column(array_filter($this->app->make(WaitTime::class)->measure(), static fn (array $row): bool => $row['connection'] === 'database'), 'queue');

        self::assertContains('busy', $names);
        self::assertContains('counted', $names);
        self::assertNotContains('idle', $names, 'a name that only its own samples carry drops out');
        self::assertNotContains('old', $names, 'activity older than the hour does not count');
    }

    /**
     * Rejects: a queue that only a live worker names is not measured: the metric
     * counters named it before, and the samples need a measurement to name it.
     */
    public function test_worker_subscribed_queue_is_measured(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports,mail'])]);

        $row = $this->row('database', 'reports');

        self::assertSame([0, null, 0, false], [$row['backlog'], $row['oldest_age'], $row['wait'], $row['backlog_unknown']], 'measured, with an empty backlog');
        self::assertSame(0, $this->row('database', 'mail')['backlog']);
    }

    private function row(string $connection, string $queue): array
    {
        $rows = $this->app->make(WaitTime::class)->measure();

        foreach ($rows as $row) {
            if ($row['connection'] === $connection && $row['queue'] === $queue) {
                return $row;
            }
        }

        self::fail("WaitTime::measure() has no row for {$connection}:{$queue}: ".json_encode($rows));
    }

    private function insertJobsRow(string $queue, int $availableAt, ?int $reservedAt): void
    {
        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => $reservedAt === null ? 0 : 1,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }
}
