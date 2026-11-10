<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Contracts\WorkloadRepository;
use Justrau\Orrery\Database\Repositories\DatabaseWorkloadRepository;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Monitoring\MeasurementCache;
use Justrau\Orrery\Monitoring\NativePause;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\FailingCacheStore;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use RuntimeException;

/**
 * The measurement that the /api/workload requests share
 * through the default cache store for `orrery.dashboard.measure_cache_seconds`.
 */
final class MeasurementCacheTest extends ControllerTest
{
    use SeedsSamples;
    use WorkerFixtures;

    /** A minute boundary, plus 12.5 s. */
    private const NOW = 1_790_000_040 + 12.5;

    private FailingCacheStore $store;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.waits', ['*' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));

        // One store object outside the package services: the services can be rebuilt around it.
        $store = $this->store = new FailingCacheStore;
        // The cache manager binds the creator to itself (Laravel 12 rebinds it with bindTo(),
        // which a static closure refuses), so it is not static and must not use $this.
        $this->app['cache']->extend('orrery-measure-test', fn ($app) => $app['cache']->repository($store));
        $this->app['config']->set('cache.stores.orrery-measure-test', ['driver' => 'orrery-measure-test']);
        $this->app['config']->set('cache.default', 'orrery-measure-test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // The rollback of the fence migration reads the queue connections: the broken one goes first.
        $this->app?->make('config')->set('queue.connections.broken', null);

        parent::tearDown();
    }

    /**
     * Rejects: no cache, a cache that never expires, and an instance-only cache
     * (the package services are rebuilt between the requests).
     */
    public function test_two_requests_within_the_ttl_measure_once(): void
    {
        $this->readyJob('reports', (int) self::NOW - 30);
        $reads = $this->countJobsReads();

        $this->workload();
        self::assertGreaterThan(0, $reads(), 'the first request measures');

        $this->freshServices();
        $before = $reads();
        $rows = $this->workload();
        self::assertSame($before, $reads(), 'the second request within 5 s runs no jobs-table statement');
        self::assertSame(1, $rows['reports']['length']);

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 5, 'UTC'));
        $before = $reads();
        $this->workload();
        self::assertGreaterThan($before, $reads(), 'after 5 s the entry has expired: it measures again');

        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        $before = $reads();
        $this->workload();
        $middle = $reads();
        $this->workload();
        self::assertGreaterThan($before, $middle);
        self::assertGreaterThan($middle, $reads(), 'with 0 seconds every request measures');
    }

    /**
     * Rejects: a constant key (another `waits` or another target reads the old entry).
     */
    public function test_another_configuration_gets_its_own_entry(): void
    {
        $this->readyJob('reports', (int) self::NOW - 30);
        $cache = $this->app->make(MeasurementCache::class);
        $reads = $this->countJobsReads();
        $first = $cache->key();
        $this->workload();

        $this->app['config']->set('orrery.waits', ['*' => 10]);
        $second = $cache->key();
        $before = $reads();
        $rows = $this->workload();
        self::assertNotSame($first, $second);
        self::assertGreaterThan($before, $reads(), 'another `waits` measures again');
        self::assertSame(10, $rows['reports']['threshold']);

        $this->app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $third = $cache->key();
        $before = $reads();
        $rows = $this->workload();
        self::assertNotSame($second, $third);
        self::assertGreaterThan($before, $reads(), 'another target measures again');
        self::assertSame(['database', 'database-twin'], $rows['reports']['connections']);
        self::assertStringStartsWith(MeasurementCache::KEY_PREFIX, $third);
    }

    /**
     * Rejects: a cached age and a cached wait (the capacity of the first request).
     */
    public function test_oldest_age_and_wait_are_derived_at_read_time(): void
    {
        $this->seedCompletion('queue', 'database:reports', 1000.0);

        foreach (range(1, 4) as $ignored) {
            $this->readyJob('reports', (int) self::NOW - 30);
        }

        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports'])]);
        $reads = $this->countJobsReads();

        $first = $this->workload()['reports'];
        self::assertEqualsWithDelta(30.5, $first['oldest_age'], 0.001);
        self::assertSame(4, $first['wait'], '4 jobs x 1000 ms / 1 worker');

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW + 4, 'UTC'));
        $this->insertHeartbeats([$this->heartbeatRow('host-b', 201, ['subscribed_queues' => 'reports'])]);
        $before = $reads();
        $second = $this->workload()['reports'];

        self::assertSame($before, $reads(), 'a cache hit');
        self::assertEqualsWithDelta(34.5, $second['oldest_age'], 0.001, 'the age is 4 s older');
        self::assertSame(2, $second['wait'], 'the wait uses the request\'s 2 workers');
        self::assertSame(2, $second['processes']);
    }

    /**
     * Rejects: an error response when the cache store fails.
     */
    public function test_cache_errors_fall_back_to_a_measurement(): void
    {
        Exceptions::fake();
        $this->readyJob('reports', (int) self::NOW - 30);
        $this->store->failGet = true;

        self::assertSame(1, $this->workload()['reports']['length']);
        Exceptions::assertReported(static fn (\Justrau\Orrery\Exceptions\WatchedOperationFailed $reported): bool => $reported->operation === 'measure_cache' && $reported->causeClass === RuntimeException::class && str_contains($reported->getMessage(), 'The cache store cannot be read.'));

        $this->store->failGet = false;
        $this->store->failPut = true;
        // The watcher reports an operation at most once per 60 s.
        Watcher::advanceMonotonicForTesting(61);
        $reads = $this->countJobsReads();
        self::assertSame(1, $this->workload()['reports']['length']);
        self::assertGreaterThan(0, $reads(), 'a failed get measures');
        Exceptions::assertReported(static fn (\Justrau\Orrery\Exceptions\WatchedOperationFailed $reported): bool => $reported->operation === 'measure_cache' && $reported->causeClass === RuntimeException::class && str_contains($reported->getMessage(), 'The cache store cannot be written.'));

        $this->store->failPut = false;
        $before = $reads();
        $this->workload();
        self::assertGreaterThan($before, $reads(), 'nothing was stored by the failed put: it measures again');
    }

    /**
     * Rejects: one native-pause round trip per row (or per connection) after the store failed.
     */
    public function test_a_failing_store_gets_at_most_one_native_pause_call_per_measurement(): void
    {
        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        // A second primary connection (unresolvable, so its rows are unknown), and three queues on the first.
        $this->app['config']->set('queue.connections.broken', ['driver' => 'database', 'connection' => 'orrery_no_such_connection', 'table' => 'jobs', 'queue' => 'brokenq']);

        foreach (['a', 'b', 'c'] as $queue) {
            $this->readyJob($queue, (int) self::NOW - 30);
        }

        $this->store->failGet = true;

        $rows = $this->app->make(MeasurementCache::class)->measure([]);

        self::assertGreaterThanOrEqual(4, count($rows));
        // Laravel 13 has a global pause, and the failed read leaves it unknown.
        // An older release has no global pause, so it is known to be off (NativePause::read()).
        $global = NativePause::laravelHasGlobalPause() ? null : false;
        self::assertSame([['queue' => null, 'global' => $global, 'unknown' => NativePause::FAILED]], array_values(array_unique(array_column($rows, 'native'), SORT_REGULAR)));
        self::assertSame(1, $this->store->reads, 'one store read, then the remaining rows are unknown without a call');
    }

    /**
     * Rejects: one isPaused() round trip pair per row where the manager can read a connection's queues at once, and a read per row of a failover connection.
     */
    public function test_the_native_pause_is_read_once_per_connection(): void
    {
        if (! method_exists($this->app['queue'], 'getPausedQueues')) {
            self::markTestSkipped('This Laravel release has no QueueManager::getPausedQueues().');
        }

        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);

        foreach (['a', 'b', 'c'] as $queue) {
            $this->readyJob($queue, (int) self::NOW - 30);
        }

        $this->app['queue']->pause('database', 'b');
        $this->store->reads = 0;

        $rows = array_map(static fn (array $native): ?bool => $native['queue'], array_column($this->app->make(MeasurementCache::class)->measure([]), 'native', 'queue'));

        self::assertSame(['a' => false, 'b' => true, 'c' => false], $rows);
        // Laravel 13's default `failover` connection pops from `database`, so its flags are read too.
        $connections = is_array($this->app['config']->get('queue.connections.failover')) ? 2 : 1;
        self::assertSame(2 * $connections, $this->store->reads, 'per connection the pause-all key, then one many(), the global probe included');
    }

    /**
     * Rejects: a measurement that outlives the cache lifetime without a trace.
     */
    public function test_a_slow_measurement_records_a_degradation(): void
    {
        $this->readyJob('reports', (int) self::NOW - 30);
        $table = DB::connection(TestEnvironment::PRIMARY)->getTablePrefix().'jobs';
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use ($table): void {
            if (str_contains($event->sql, $table) && ! str_contains($event->sql, 'orrery_')) {
                Clock::advanceMonotonicForTesting(6.0);
            }
        });

        $this->workload();

        // The request flushes the degradation at its end: the health event names it.
        $recorded = array_merge(
            array_keys(Degradation::record()['errors'] ?? []),
            $this->mirror()->table('orrery_health_events')->pluck('last_error_operation')->all(),
        );
        self::assertContains('measure_cache:slow', $recorded);
    }

    /**
     * Rejects: a key without the server identity (two servers with the same names share an entry).
     */
    public function test_another_server_gets_its_own_key(): void
    {
        $cache = $this->app->make(MeasurementCache::class);
        $connection = $this->app['config']->get('queue.connections.database.connection') ?? $this->app['config']->get('database.default');
        $first = $cache->key();

        $this->app['config']->set('database.connections.'.$connection.'.host', 'db-two.example.test');

        self::assertNotSame($first, $cache->key());
    }

    /**
     * Rejects: an entry of the wrong shape read as a measurement.
     */
    public function test_an_entry_of_the_wrong_shape_is_a_miss(): void
    {
        $this->readyJob('reports', (int) self::NOW - 30);
        $key = $this->app->make(MeasurementCache::class)->key();
        $this->store->put($key, ['measured_at' => self::NOW, 'rows' => [['connection' => 'database', 'queue' => 'reports']]], 5);

        self::assertSame(1, $this->workload()['reports']['length']);

        $this->store->put($key, 'garbage', 5);
        self::assertSame(1, $this->workload()['reports']['length']);
    }

    /**
     * Rejects: an entry used past the lifetime when the store keeps it longer
     * (a store whose expiry is coarser than the lifetime).
     */
    public function test_an_entry_older_than_the_lifetime_is_a_miss(): void
    {
        $this->readyJob('reports', (int) self::NOW - 30);
        $this->workload();
        $key = $this->app->make(MeasurementCache::class)->key();
        $entry = $this->store->get($key);
        self::assertIsArray($entry);

        // The same entry, measured 5 s ago, with another backlog; the store keeps it for 60 s.
        $entry['measured_at'] = self::NOW - 5;
        $entry['rows'][0]['backlog'] = 99;
        $this->store->put($key, $entry, 60);
        self::assertSame(1, $this->workload()['reports']['length'], 'measured again');

        $entry['measured_at'] = self::NOW - 4.5;
        $this->store->put($key, $entry, 60);
        self::assertSame(99, $this->workload()['reports']['length'], 'within the lifetime the entry is used');
    }

    /**
     * Rejects: alerts from a cached value, and a tick that reads the cache at all.
     */
    public function test_tick_never_reads_the_measurement_cache(): void
    {
        $this->readyJob('reports', (int) self::NOW - 120);
        self::assertTrue($this->workload()['reports']['over_limit'], 'the cached measurement is over the limit');

        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->delete();
        $cacheEvents = [];
        Event::listen(CacheEvent::class, static function (CacheEvent $event) use (&$cacheEvents): void {
            if (str_starts_with($event->key, MeasurementCache::KEY_PREFIX)) {
                $cacheEvents[] = $event::class;
            }
        });
        Event::fake([LongWaitDetected::class]);

        $this->artisan('orrery:tick')->assertExitCode(0);

        Event::assertNotDispatched(LongWaitDetected::class);
        self::assertSame([], $cacheEvents);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workload(): array
    {
        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);

            if ($row['connection'] === 'database') {
                $rows[(string) $row['name']] = $row;
            }
        }

        return $rows;
    }

    /**
     * New instances of the package services that measure (the store stays).
     */
    private function freshServices(): void
    {
        foreach ([WaitTime::class, MeasurementCache::class, DatabaseWorkloadRepository::class, WorkloadRepository::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /**
     * @return \Closure(): int the number of statements on the jobs table so far
     */
    private function countJobsReads(): \Closure
    {
        $table = DB::connection(TestEnvironment::PRIMARY)->getTablePrefix().'jobs';
        $pattern = '/ from (?:[`"]?[a-z0-9_]+[`"]?\.)?[`"]?'.preg_quote($table, '/').'[`"]?( |$)/i';
        $count = 0;

        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use ($pattern, &$count): void {
            if (preg_match($pattern, $event->sql) === 1) {
                $count++;
            }
        });

        return static function () use (&$count): int {
            return $count;
        };
    }

    private function readyJob(string $queue, int $availableAt): void
    {
        DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }
}
