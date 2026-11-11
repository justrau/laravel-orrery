<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickRunner;
use Justrau\Orrery\Monitoring\LongWaitIncidents;
use Justrau\Orrery\Monitoring\LongWaitMeasured;
use Justrau\Orrery\Monitoring\MeasurementIncomplete;
use Justrau\Orrery\Monitoring\Phases\LongWaitPhase;
use Justrau\Orrery\Monitoring\Phases\SamplesPhase;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\TickMeasurement;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDOException;

/**
 * A backlog that cannot be read gives unknown rows, never an
 * absent queue or a 0, and the long-wait phase fails after it alerted the
 * readable targets.
 */
final class WaitTimeUnknownTest extends TestCase
{
    use WorkerFixtures;

    /** A minute boundary, plus 17.5 s. */
    private const START = 1_790_000_040 + 17.5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.waits', ['*' => 60]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        // The rollback of the fence migration reads the queue connections: the broken one goes first.
        $this->app?->make('config')->set('queue.connections.broken', null);

        parent::tearDown();
    }

    /**
     * Rejects: names only from the configuration (the recently sampled queue is
     * missing), an unbounded samples window (the queue sampled 10 minutes ago
     * comes back), and an empty list when the samples read fails too (the
     * default queue stays).
     */
    public function test_unknown_rows_cover_the_default_queue_and_sampled_queues(): void
    {
        $this->insertJobsRow('jobsonly', (int) floor(self::START) - 30);
        // Queues with no config entry and no counter: one sampled 2 minutes ago, one 10 minutes ago.
        $this->sample('sampledonly', (int) floor(self::START) - 120);
        $this->sample('sampledlong', (int) floor(self::START) - 600);
        $this->failJobsReads();

        $rows = $this->rowsOf('database');

        self::assertSame(['default', 'sampledonly'], array_keys($rows), 'the default queue and the sampled queue; the jobs-only queue cannot be known');

        foreach ($rows as $row) {
            self::assertTrue($row['backlog_unknown']);
            self::assertNull($row['backlog']);
            self::assertNull($row['oldest_age']);
            self::assertNull($row['wait']);
        }

        // The samples read fails too: the default queue stays.
        // The package session, not the test's mirror connection: with separate sessions they are two objects.
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql): void {
            if (str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'orrery_samples')) {
                throw new \RuntimeException('The samples cannot be read.');
            }
        });

        self::assertSame(['default'], array_keys($this->rowsOf('database')));
    }

    /**
     * Rejects: unknown rows that leave out the queues of the live workers that measure() receives.
     */
    public function test_unknown_rows_cover_the_subscribed_queues_of_the_given_workers(): void
    {
        $this->failJobsReads();
        $workers = [(object) ['connection' => 'database', 'subscribed_queues' => ['mail', 'reports']]];

        $rows = $this->rowsOf('database', $workers);

        self::assertSame(['default', 'mail', 'reports'], array_keys($rows));
        self::assertSame([true, true, true], array_column(array_values($rows), 'backlog_unknown'));
    }

    /**
     * Gate fix 3: a queue that Orrery knows only from its mirror rows or from
     * a queue-scope signal row (an Orrery pause or its annotation) is listed
     * with unknown values when the backlog cannot be read, never hidden.
     *
     * Rejects: names only from the configuration, the samples and the
     * workers (the mirrored and the paused queue vanish), mirror or signal
     * names of another queue connection merged into this one, and a
     * connection-scope signal read as a queue. It still passes an
     * implementation that reads every mirror row instead of a skip scan
     * (test_known_queue_reads_are_bounded and
     * test_the_mirror_skip_scan_seeks_the_index reject that).
     */
    public function test_unknown_rows_cover_mirrored_and_signalled_queues(): void
    {
        $this->mirrorRow('database', 'mirroredonly');
        $this->mirrorRow('other', 'foreign');
        $this->signalRow('queue', 'database:pausedonly');
        $this->signalRow('queue', 'other:foreignpause');
        $this->signalRow('connection', 'database');
        $this->failJobsReads();

        $rows = $this->rowsOf('database');

        self::assertSame(['default', 'mirroredonly', 'pausedonly'], array_keys($rows));
        self::assertSame([true, true, true], array_column(array_values($rows), 'backlog_unknown'));
        self::assertSame([null, null, null], array_column(array_values($rows), 'backlog'));

        // The mirror and the signals cannot be read either: the default queue stays.
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql): void {
            if (preg_match('/^\s*(select|with)/i', $sql) === 1 && (str_contains($sql, 'orrery_jobs') || str_contains($sql, 'orrery_signals'))) {
                throw new \RuntimeException('The mirror cannot be read.');
            }
        });

        self::assertSame(['default'], array_keys($this->rowsOf('database')));
    }

    /**
     * Gate fix 3 and its review: the names of the mirror come from one
     * bounded read per connection of the failing group (a recursive skip
     * scan on SQLite and Postgres, DISTINCT of the queue's bytes on MySQL);
     * the signal names from one read of the newest rows with a limit. Only
     * the failure path reads them.
     *
     * Rejects: an unbounded read of orrery_jobs or orrery_signals, a read of
     * whole mirror rows, a mirror read of a connection outside the failing
     * group, the oldest signal rows instead of the newest, and the reads on
     * the readable path. It still passes a limit larger than
     * WaitTime::ROW_CAP.
     */
    public function test_known_queue_reads_are_bounded(): void
    {
        $this->mirrorRow('database', 'mirroredonly');
        $statements = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            // A pooled session names the table with its schema ("public"."orrery_jobs").
            if (preg_match('/ from (?:[`"]?[a-z0-9_]+[`"]?\.)?[`"]?[a-z0-9_]*orrery_(jobs|signals)[`"]? /i', $event->sql.' ') === 1) {
                $statements[] = [strtolower($event->sql), $event->bindings];
            }
        });

        if (! in_array(true, array_column($this->rowsOf('database'), 'backlog_unknown'), true)) {
            self::assertSame([], $statements, 'the readable path reads no known names');

            $this->failJobsReads();
            $this->rowsOf('database');
        }
        // Else the harness's own SQLite file is in rollback mode (ORRERY_TEST_SQLITE_JOURNAL=delete):
        // the package reads no backlog there, so the first measurement took the failure path.

        $jobs = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'orrery_jobs')));
        $signals = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'orrery_signals')));
        $connections = $this->databaseGroupOf('database');

        self::assertCount(count($connections), $jobs, 'one mirror read per connection of the failing group');
        self::assertCount(1, $signals);

        foreach ($statements as [$sql]) {
            self::assertMatchesRegularExpression('/ limit (\d+|\?)$/', $sql, $sql);
            self::assertStringNotContainsString('*', $sql, $sql);
        }

        foreach ($jobs as $index => [$sql, $bindings]) {
            self::assertSame($connections[$index], $bindings[0], 'the read is scoped to the connection');
            self::assertMatchesRegularExpression(in_array($this->mirror()->getDriverName(), ['mysql', 'mariadb'], true)
                ? '/^select distinct cast\(`queue` as binary\) as queue_bytes from /'
                : '/^with recursive /', $sql);
        }

        self::assertStringContainsString('order by "id" desc', str_replace('`', '"', $signals[0][0]), 'the newest signal rows');
    }

    /**
     * Review fix: on SQLite the mirror read seeks the (connection, queue)
     * index for each queue instead of scanning orrery_jobs, so its cost
     * follows the number of queues, not of rows.
     *
     * Rejects: a DISTINCT or GROUP BY that scans the table or the whole
     * index. It still passes a skip scan on Postgres that the planner runs
     * as a scan (only SQLite's plan is checked here).
     */
    public function test_the_mirror_skip_scan_seeks_the_index(): void
    {
        if ($this->mirror()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The plan check reads SQLite\'s EXPLAIN QUERY PLAN; the other engines run the read in test_known_queue_reads_are_bounded.');
        }

        foreach (['alpha', 'beta', 'gamma'] as $queue) {
            $this->mirrorRow('database', $queue);
        }

        $captured = null;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$captured): void {
            if ($captured === null && str_starts_with(strtolower(ltrim($event->sql)), 'with recursive')) {
                $captured = [$event->sql, $event->bindings, $event->connectionName];
            }
        });
        $this->failJobsReads();

        $rows = $this->rowsOf('database');

        self::assertSame(['alpha', 'beta', 'default', 'gamma'], array_keys($rows));
        self::assertNotNull($captured, 'the skip scan ran');
        [$sql, $bindings] = $captured;
        $plan = array_map(static fn (object $row): string => (string) $row->detail, Container::getInstance()->make(PackageSession::class)->mirror()->select('explain query plan '.$sql, $bindings));

        self::assertNotSame([], $plan);

        foreach ($plan as $detail) {
            self::assertDoesNotMatchRegularExpression('/^SCAN (TABLE )?[a-z0-9_]*orrery_jobs\b/i', $detail, implode("\n", $plan));
        }

        self::assertNotSame([], preg_grep('/^SEARCH [a-z0-9_]*orrery_jobs USING (COVERING )?INDEX/i', $plan), implode("\n", $plan));
    }

    /**
     * The database queue connections that share the target of $connection, in name order.
     *
     * @return list<string>
     */
    private function databaseGroupOf(string $connection): array
    {
        foreach ($this->app->make(WaitTime::class)->measure() as $row) {
            if ($row['connection'] === $connection) {
                return $row['connections'];
            }
        }

        return [$connection];
    }

    /**
     * Rejects: a readable row without the explicit `backlog_unknown: false`.
     */
    public function test_readable_rows_are_marked_known(): void
    {
        $this->insertJobsRow('reports', (int) floor(self::START) - 30);

        $rows = $this->rowsOf('database');

        self::assertFalse($rows['reports']['backlog_unknown']);
        self::assertSame(1, $rows['reports']['backlog']);
    }

    /**
     * Rejects: the long-wait phase reporting `ran` while a target was unknown,
     * and a phase that throws before it alerted the readable target.
     */
    public function test_long_wait_fails_and_still_alerts_readable_targets(): void
    {
        $this->addBrokenConnection();
        $this->insertJobsRow('reports', (int) floor(self::START) - 120);
        Event::fake([LongWaitDetected::class]);

        $report = $this->app->make(TickRunner::class)->run([$this->app->make(LongWaitPhase::class)]);

        self::assertSame(['long_wait'], $report->failed());
        Event::assertDispatched(LongWaitDetected::class, static fn (LongWaitDetected $event): bool => $event->connection === 'database' && $event->queue === 'reports');
    }

    /**
     * Rejects: a runner that skips the samples after long_wait throws, a second
     * measurement in one run, and a repeated alert.
     */
    public function test_long_wait_and_the_samples_run_together_with_an_unknown_row(): void
    {
        $this->addBrokenConnection();
        $this->insertJobsRow('reports', (int) floor(self::START) - 120);
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports'])]);
        Event::fake([LongWaitDetected::class]);

        $report = $this->app->make(TickRunner::class)->run([
            $this->app->make(SamplesPhase::class),
            $this->app->make(LongWaitPhase::class),
        ]);

        self::assertSame(['long_wait'], $report->failed());
        self::assertContains('samples', $report->ran(), 'the samples phase runs after long_wait threw');
        self::assertSame(1, $this->app->make(TickMeasurement::class)->measurements());
        Event::assertDispatchedTimes(LongWaitDetected::class, 1);
        self::assertSame(1, $this->mirror()->table('orrery_samples')->where('scope', 'queue')->whereNotNull('over_limit')->count(), 'only the readable row is sampled');
    }

    /**
     * Rejects: a phase that stops silently when its budget runs out while a target is unknown.
     */
    public function test_an_exhausted_budget_still_names_the_unknown_targets(): void
    {
        $this->addBrokenConnection();
        $this->insertJobsRow('reports', (int) floor(self::START) - 120);
        Event::fake([LongWaitDetected::class]);

        $this->expectException(MeasurementIncomplete::class);
        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() - 1));
    }

    /**
     * Rejects: a phase that records no measurement state, records a run with unknown rows as complete, or forgets the
     * time of the last complete run (the monitoring ping reads it: Monitoring\LongWaitMeasured).
     */
    public function test_the_phase_records_its_last_complete_measurement(): void
    {
        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
        self::assertSame(['at' => self::START, 'complete' => true, 'complete_at' => self::START, 'unmeasured' => []], $this->app->make(LongWaitMeasured::class)->read());

        Carbon::setTestNow(Carbon::createFromTimestamp(self::START + 60, 'UTC'));
        $this->addBrokenConnection();

        try {
            $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + 30));
            self::fail('The phase must throw MeasurementIncomplete.');
        } catch (MeasurementIncomplete) {
            // Recorded before the throw.
        }

        self::assertSame(['at' => self::START + 60, 'complete' => false, 'complete_at' => self::START, 'unmeasured' => []], $this->app->make(LongWaitMeasured::class)->read());
    }

    /**
     * Rejects: a run whose incident update failed (the watcher swallowed it) that records a complete measurement, so
     * the ping would trust incidents that were not updated.
     */
    public function test_a_failed_incident_update_is_not_a_complete_measurement(): void
    {
        Watcher::failOperationsForTesting([LongWaitIncidents::OPERATION]);

        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        self::assertFalse($this->app->make(LongWaitMeasured::class)->read()['complete'] ?? null);
    }

    /**
     * Rejects: a queue that the package may not read (a host SQLite file without WAL, D18) that is recorded as
     * measured, so the ping would call it ok.
     */
    public function test_a_queue_unknown_by_design_is_recorded_as_unmeasured(): void
    {
        $this->addBrokenConnection();
        HostFiles::useForTesting(null, static fn (string $connection): ?bool => $connection === 'orrery_no_such_connection' ? false : null);

        $this->app->make(LongWaitPhase::class)->run(new TickBudget(Clock::monotonic() + 30));

        $state = $this->app->make(LongWaitMeasured::class)->read();
        self::assertTrue($state['complete'] ?? null, 'unknown by design is no failed measurement');
        self::assertSame([['connection' => 'broken', 'queue' => 'brokenq']], $state['unmeasured'] ?? null);
    }

    /**
     * Rejects: a MeasurementIncomplete without the failed target's name.
     */
    public function test_measurement_incomplete_names_the_failed_targets(): void
    {
        $this->addBrokenConnection();
        $phase = $this->app->make(LongWaitPhase::class);

        try {
            $phase->run(new TickBudget(Clock::monotonic() + 30));
            self::fail('The phase must throw MeasurementIncomplete.');
        } catch (MeasurementIncomplete $error) {
            self::assertSame(['broken'], $error->targets);
            self::assertStringContainsString('broken', $error->getMessage());
        }
    }

    /**
     * Rejects: a sample with `ready` 0 for an unknown row.
     */
    public function test_unknown_rows_write_no_sample(): void
    {
        $this->insertJobsRow('reports', (int) floor(self::START) - 30);
        $this->addBrokenConnection();
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports'])]);

        $this->app->make(TickRunner::class)->run([$this->app->make(SamplesPhase::class)]);

        $samples = $this->mirror()->table('orrery_samples')->where('scope', 'queue')->whereNotNull('over_limit')->orderBy('connection')->orderBy('queue')->get(['connection', 'queue', 'ready'])->all();
        self::assertSame([['database', 'reports', 1]], array_map(static fn (object $row): array => [(string) $row->connection, (string) $row->queue, (int) $row->ready], $samples), 'no sample of the broken connection');
    }

    /**
     * @param  list<object{connection: string, subscribed_queues: list<string>}>|null  $workers
     * @return array<string, array<string, mixed>>
     */
    private function rowsOf(string $connection, ?array $workers = null): array
    {
        $rows = [];

        foreach ($this->app->make(WaitTime::class)->measure($workers) as $row) {
            if ($row['connection'] === $connection) {
                $rows[$row['queue']] = $row;
            }
        }

        ksort($rows);

        return $rows;
    }

    private function addBrokenConnection(): void
    {
        // A database queue connection whose database connection does not exist: the queue manager throws.
        $this->app['config']->set('queue.connections.broken', [
            'driver' => 'database',
            'connection' => 'orrery_no_such_connection',
            'table' => 'jobs',
            'queue' => 'brokenq',
            'retry_after' => 90,
        ]);
    }

    private function failJobsReads(): void
    {
        $table = DB::connection(TestEnvironment::PRIMARY)->getTablePrefix().'jobs';
        $pattern = '/ from (?:[`"]?[a-z0-9_]+[`"]?\.)?[`"]?'.preg_quote($table, '/').'[`"]?( |$)/i';

        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use ($pattern): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && preg_match($pattern, $event->sql) === 1) {
                throw new QueryException((string) $event->connectionName, $event->sql, $event->bindings, new PDOException('SQLSTATE[HY000]: General error: 3024 Query execution was interrupted'));
            }
        });
    }

    private function sample(string $queue, int $at): void
    {
        $this->mirror()->table('orrery_samples')->insert([
            'scope' => 'queue',
            'series' => SampleCounts::series('queue', 'database', $queue),
            'connection' => 'database',
            'queue' => $queue,
            'minute' => $at,
            'ready' => 0,
            'oldest_age' => null,
            'workers' => 0,
            'over_limit' => false,
        ]);
    }

    private function mirrorRow(string $connection, string $queue): void
    {
        $now = Clock::toDecimal(self::START - 300);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => $connection,
            'queue' => $queue,
            'status' => 'completed',
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function signalRow(string $scope, string $value): void
    {
        $this->mirror()->table('orrery_signals')->insert([
            'signal' => 'pause',
            'scope' => $scope,
            'scope_value' => $value,
            'issued_at' => Clock::toDecimal(self::START - 60),
        ]);
    }

    private function insertJobsRow(string $queue, int $availableAt): void
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
