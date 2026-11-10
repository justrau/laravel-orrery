<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Contracts\WorkloadRepository;
use Justrau\Orrery\Events\LongWaitDetected;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Monitoring\TickMeasurement;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\Fixtures\Metrics\SeedsSamples;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;

/**
 * The queue state of orrery_samples (before: the queue samples):
 * the tick phase `samples`, its run-scoped measurement shared
 * with `long_wait`, its bounds and cursor, and the retention phase
 * `sample_retention`.
 */
final class SamplesTest extends TestCase
{
    use SeedsSamples;
    use WorkerFixtures;

    /** A minute boundary, plus 17.5 s. */
    private const START = 1_790_000_040 + 17.5;

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
     * Known limit: it would pass a sample that uses the tick's start time instead of the measurement time (both are in one minute here).
     */
    public function test_a_tick_writes_one_sample_per_queue_at_the_minute_boundary(): void
    {
        $this->at(self::START);
        $t = (int) floor(self::START);
        $this->insertJobsRow('reports', $t - 120);
        $this->insertJobsRow('reports', $t - 30);
        $this->insertJobsRow('reports', $t - 10);
        $this->insertJobsRow('mail', $t - 5);
        $this->insertHeartbeats([
            $this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports,mail']),
            $this->heartbeatRow('host-a', 102, ['subscribed_queues' => 'reports']),
            $this->heartbeatRow('host-b', 201, ['subscribed_queues' => 'reports', 'connection' => 'other']),
        ]);

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([
            ['connection' => 'database', 'queue' => 'mail', 'sampled_at' => 1_790_000_040, 'ready' => 1, 'oldest_age' => 5.5, 'workers' => 1, 'over_limit' => false],
            ['connection' => 'database', 'queue' => 'reports', 'sampled_at' => 1_790_000_040, 'ready' => 3, 'oldest_age' => 120.5, 'workers' => 2, 'over_limit' => true],
        ], $this->samples());
    }

    /**
     * Known limit: it would pass an insert that ignores a duplicate minute when the first sample already has the final values.
     */
    public function test_a_repeated_minute_overwrites_its_sample(): void
    {
        $this->at(self::START);
        $t = (int) floor(self::START);
        $this->insertJobsRow('reports', $t - 10);

        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame([1], array_column($this->samples(), 'ready'));

        $this->insertJobsRow('reports', $t - 5);
        $this->at(self::START + 20);
        $this->artisan('orrery:tick')->assertExitCode(0);

        $samples = $this->samples();
        self::assertCount(1, $samples, 'the same minute has one sample');
        self::assertSame(2, $samples[0]['ready'], 'the later tick of the minute overwrites the sample');
        self::assertSame(30.5, $samples[0]['oldest_age']);
    }

    /**
     * Known limit: it would pass a backfill that copies a missed minute only when more than three minutes are missing.
     */
    public function test_missed_minutes_are_not_backfilled(): void
    {
        $this->at(self::START);
        $this->insertJobsRow('reports', (int) floor(self::START) - 10);

        $this->artisan('orrery:tick')->assertExitCode(0);
        $this->at(self::START + 180);
        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([1_790_000_040, 1_790_000_220], array_column($this->samples(), 'sampled_at'));
    }

    /**
     * Known limit: it would pass a predicate that uses a threshold of 0 as "always over" only for queues with runtime counters.
     */
    public function test_a_threshold_of_0_is_never_over_the_limit(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 0]);
        $this->at(self::START);
        $this->insertJobsRow('reports', (int) floor(self::START) - 86_400);

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertSame([false], array_column($this->samples(), 'over_limit'));
    }

    /**
     * Known limit: it would pass a cursor that restarts at the first queue only after a full pass (the test runs three passes of five queues with a cap of two).
     */
    public function test_the_row_cap_bounds_each_run_and_the_cursor_reaches_every_queue(): void
    {
        Samples::useRowCapForTesting(2);
        $this->at(self::START);
        $t = (int) floor(self::START);

        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $queue) {
            $this->insertJobsRow($queue, $t - 10);
        }

        $runs = [];

        for ($run = 0; $run < 3; $run++) {
            $this->at(self::START + 60 * $run);
            $this->artisan('orrery:tick')->assertExitCode(0);
            $minute = 1_790_000_040 + 60 * $run;
            $runs[] = array_column(array_filter($this->samples(), static fn (array $row): bool => $row['sampled_at'] === $minute), 'queue');
        }

        self::assertSame([['q1', 'q2'], ['q3', 'q4'], ['q1', 'q5']], $runs, 'each run writes 2 rows, after the cursor, and wraps around');
    }

    /**
     * Known limit: it would pass a chunk of 142 rows (994 bindings) as well; only the bound of 999 is checked.
     */
    public function test_no_statement_binds_more_than_999_values_with_600_queues(): void
    {
        $this->at(self::START);
        $t = (int) floor(self::START);
        $rows = [];

        for ($i = 0; $i < 600; $i++) {
            $rows[] = ['queue' => sprintf('q%04d', $i), 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $t - 10, 'created_at' => $t - 10];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert($chunk);
        }

        $largest = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$largest): void {
            $largest = max($largest, count($query->bindings));
        });

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertCount(600, $this->samples());
        self::assertLessThanOrEqual(999, $largest, 'old SQLite builds allow 999 bindings per statement');
    }

    /**
     * Known limit: it would pass a cursor that is written before the chunk's upsert, in its own statement, if that write never failed.
     */
    public function test_a_deadline_stop_after_the_first_chunk_still_moves_the_cursor(): void
    {
        Samples::useChunkForTesting(2);
        $rows = [];

        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $queue) {
            $rows[] = ['connection' => 'database', 'connections' => ['database'], 'queue' => $queue, 'backlog' => 1, 'oldest_age' => 1.0, 'threshold' => 60];
        }

        // The first chunk's upsert outlasts the deadline, so the check before the second chunk stops the run.
        // The clock advances instead of a sleep.
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'orrery_samples') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
                Clock::advanceMonotonicForTesting(0.4);
            }
        });

        $written = $this->app->make(Samples::class)->write($rows, [], self::START, new TickBudget(Clock::monotonic() + 0.3));

        self::assertSame(2, $written);
        self::assertSame(['q1', 'q2'], array_column($this->samples(), 'queue'));
        self::assertSame(Samples::cursorValue('database', 'q2'), $this->mirror()->table('orrery_settings')->where('key', Samples::CURSOR_KEY)->value('value'));
    }

    /**
     * Known limit: it would pass a comparison that is numeric only for names longer than two digits.
     */
    public function test_numeric_queue_names_follow_byte_order(): void
    {
        Samples::useRowCapForTesting(1);
        $t = (int) floor(self::START);
        $this->insertJobsRow('9', $t - 10);
        $this->insertJobsRow('10', $t - 10);

        $runs = [];

        for ($run = 0; $run < 3; $run++) {
            $this->at(self::START + 60 * $run);
            $this->artisan('orrery:tick')->assertExitCode(0);
            $minute = 1_790_000_040 + 60 * $run;
            $runs[] = array_column(array_filter($this->samples(), static fn (array $row): bool => $row['sampled_at'] === $minute), 'queue');
        }

        self::assertSame([['10'], ['9'], ['10']], $runs, '"10" sorts before "9" as bytes, and the cursor wraps');
    }

    /**
     * Known limit: it would pass a count that uses only the aliases whose workers are fresh (every heartbeat here is fresh).
     */
    public function test_the_worker_count_includes_the_workers_of_every_alias(): void
    {
        $this->app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $this->at(self::START);
        $this->insertJobsRow('reports', (int) floor(self::START) - 10);
        $this->insertHeartbeats([
            $this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports']),
            $this->heartbeatRow('host-b', 201, ['subscribed_queues' => 'reports', 'connection' => 'database-twin']),
            $this->heartbeatRow('host-c', 301, ['subscribed_queues' => 'mail', 'connection' => 'database-twin']),
        ]);

        $this->artisan('orrery:tick')->assertExitCode(0);

        $samples = $this->samples();
        // The worker's subscribed queue mail is measured too, with its one worker.
        self::assertSame([['database', 'mail', 1], ['database', 'reports', 2]], array_map(static fn (array $row): array => [$row['connection'], $row['queue'], $row['workers']], $samples), 'one sample per queue under the primary alias, with the workers of both aliases');
    }

    /**
     * Known limit: it would pass a check that runs only before the first chunk (every chunk is refused here).
     */
    public function test_an_exhausted_budget_writes_no_sample(): void
    {
        $rows = [['connection' => 'database', 'connections' => ['database'], 'queue' => 'reports', 'backlog' => 1, 'oldest_age' => 1.0, 'threshold' => 60]];

        $written = $this->app->make(Samples::class)->write($rows, [], self::START, new TickBudget(Clock::monotonic() - 1));

        self::assertSame(0, $written);
        self::assertSame([], $this->samples());
    }

    /**
     * Known limit: it would pass two measurements that each read the workers once, when the second is skipped for another reason.
     */
    public function test_long_wait_and_the_samples_share_one_measurement_per_run(): void
    {
        $this->at(self::START);
        $t = (int) floor(self::START);
        $this->seedCompletion('queue', 'database:reports', 1000.0);
        $this->insertJobsRow('reports', $t - 120);
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['subscribed_queues' => 'reports'])]);

        $heartbeatReads = 0;
        $queueReads = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$heartbeatReads, &$queueReads): void {
            if (str_contains($query->sql, 'orrery_worker_heartbeats') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $heartbeatReads++;
            }

            // The one grouped read of each jobs table (WaitTime::backlogs()) also gives its queues.
            if (str_contains($query->sql, 'queue_bytes')) {
                $queueReads++;
            }
        });

        Event::fake([LongWaitDetected::class]);
        $this->artisan('orrery:tick')->assertExitCode(0);

        Event::assertDispatched(LongWaitDetected::class, static fn (LongWaitDetected $event): bool => $event->queue === 'reports');
        self::assertSame(1, $this->app->make(TickMeasurement::class)->measurements(), 'one WaitTime::measure() per tick run');
        self::assertSame(1, $queueReads, 'the distinct queues of the jobs table are read once');
        self::assertSame(1, $heartbeatReads, 'the live workers are read once');
        self::assertSame([1], array_column($this->samples(), 'workers'));

        $this->at(self::START + 60);
        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame(2, $this->app->make(TickMeasurement::class)->measurements(), 'the next run measures again');
    }

    /**
     * Known limit: it would pass a sample phase that writes a sample with 0 workers when the rest of the measurement also failed.
     */
    public function test_no_sample_when_the_workers_cannot_be_read_and_long_wait_still_alerts(): void
    {
        $this->at(self::START);
        $this->insertJobsRow('reports', (int) floor(self::START) - 120);
        Watcher::failOperationsForTesting(['tick:live_workers']);

        Event::fake([LongWaitDetected::class]);
        $this->artisan('orrery:tick')->assertExitCode(0);

        Event::assertDispatched(LongWaitDetected::class, static fn (LongWaitDetected $event): bool => $event->queue === 'reports' && $event->seconds === 120);
        self::assertSame([], $this->samples(), 'a minute without a successful observation stays missing');
    }

    /**
     * Known limit: it would pass a retention that deletes by minute only when every old minute fits in the limit (the test has one minute that does not).
     */
    public function test_retention_deletes_old_samples_oldest_first_at_most_5000_per_tick(): void
    {
        // The tick deadline must not skip the sample_retention phase on a loaded machine.
        Clock::freezeMonotonicForTesting();
        $this->at(self::START);
        $cutoff = (int) floor(self::START) - 24 * 3600;
        $oldMinutes = [$cutoff - 600, $cutoff - 300, $cutoff - 60];

        foreach ($oldMinutes as $minute) {
            $this->seedSamples($minute, 2000);
        }

        $this->seedSamples($cutoff + 60, 3);

        $largest = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$largest): void {
            $largest = max($largest, count($query->bindings));
        });

        $this->artisan('orrery:tick')->assertExitCode(0);

        self::assertLessThanOrEqual(999, $largest, 'the partial minute deletes its 1,000 queues in chunks');

        $counts = $this->mirror()->table('orrery_samples')
            ->where('minute', '<=', $cutoff + 60)
            ->groupBy('minute')->orderBy('minute')
            ->selectRaw('minute, COUNT(*) AS aggregate')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->minute => (int) $row->aggregate])->all();

        self::assertSame([$cutoff - 60 => 1000, $cutoff + 60 => 3], $counts, 'the two oldest minutes and 1,000 rows of the third are deleted; the recent rows stay');

        $this->artisan('orrery:tick')->assertExitCode(0);
        self::assertSame([$cutoff + 60], array_values(array_unique(array_column(array_filter($this->samples(), static fn (array $row): bool => $row['sampled_at'] <= $cutoff + 60), 'sampled_at'))), 'the next tick deletes the rest, and keeps the samples of the last 24 hours');
        self::assertSame((string) ($cutoff - 60), $this->mirror()->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->value('value'), 'the newest retired minute is the cursor');
    }

    /**
     * Rejects: `since` = now for a run that continues (each minute would restart it),
     * and a carried run that loses its censoring.
     */
    public function test_over_limit_since_is_carried_minute_to_minute(): void
    {
        $m = 1_790_000_040;

        foreach ([0, 1, 2] as $minute) {
            $this->writeAt($m + 60 * $minute + 5, ['reports' => 90.0]);
        }

        self::assertSame([
            [$m, $m, true],
            [$m + 60, $m, true],
            [$m + 120, $m, true],
        ], $this->runs('reports'), 'the first sample starts the run, censored (no earlier sample); later minutes copy both values');
    }

    /**
     * Rejects: censored after an observed start (an under-limit sample just before the run).
     */
    public function test_a_run_after_an_observed_under_limit_minute_is_not_censored(): void
    {
        $m = 1_790_000_040;
        $this->writeAt($m + 5, ['reports' => 10.0]);
        $this->writeAt($m + 65, ['reports' => 90.0]);
        $this->writeAt($m + 125, ['reports' => 150.0]);

        self::assertSame([
            [$m, null, false],
            [$m + 60, $m + 60, false],
            [$m + 120, $m + 60, false],
        ], $this->runs('reports'));
    }

    /**
     * Rejects: a run carried across a gap (the minute before the run's sample is missing).
     */
    public function test_a_run_after_a_missing_minute_is_censored(): void
    {
        $m = 1_790_000_040;
        $this->writeAt($m + 5, ['reports' => 10.0]);
        $this->writeAt($m + 65, ['reports' => 90.0]);
        // No sample at $m + 120.
        $this->writeAt($m + 185, ['reports' => 210.0]);
        $this->writeAt($m + 245, ['reports' => 270.0]);

        self::assertSame([
            [$m, null, false],
            [$m + 60, $m + 60, false],
            [$m + 180, $m + 180, true],
            [$m + 240, $m + 180, true],
        ], $this->runs('reports'), 'the run after the gap starts at its first sample, and its start is not seen');
    }

    /**
     * Rejects: a carried value of another queue or another connection.
     */
    public function test_the_previous_sample_is_matched_by_connection_and_queue(): void
    {
        $m = 1_790_000_040;
        $this->writeAt($m + 5, ['reports' => 10.0, 'mail' => 90.0], 'other');
        $this->writeAt($m + 65, ['mail' => 10.0]);
        $this->writeAt($m + 125, ['reports' => 90.0, 'mail' => 90.0]);

        self::assertSame([[$m + 120, $m + 120, true]], $this->runs('reports'), 'no sample of database:reports before');
        self::assertSame([[$m + 60, null, false], [$m + 120, $m + 120, false]], $this->runs('mail'));
    }

    /**
     * Rejects: one samples read per workload row (1-4 statements per over-limit queue before).
     */
    public function test_workload_reads_samples_in_one_statement(): void
    {
        $m = 1_790_000_040;
        $this->at($m + 12.5);
        $jobs = [];
        $samples = [];

        for ($i = 0; $i < 300; $i++) {
            $queue = sprintf('q%03d', $i);
            $jobs[] = ['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $m - 100, 'created_at' => $m - 100];
            $series = SampleCounts::series('queue', 'database', $queue);
            $samples[] = ['scope' => 'queue', 'series' => $series, 'connection' => 'database', 'queue' => $queue, 'minute' => $m, 'ready' => 1, 'oldest_age' => '100.000000', 'workers' => 0, 'over_limit' => true, 'over_limit_since' => $m - 60 * ($i % 5), 'over_limit_since_censored' => $i % 2 === 0];
            // An older sample of the same queue: the latest one decides.
            $samples[] = ['scope' => 'queue', 'series' => $series, 'connection' => 'database', 'queue' => $queue, 'minute' => $m - 60, 'ready' => 1, 'oldest_age' => '40.000000', 'workers' => 0, 'over_limit' => false, 'over_limit_since' => null, 'over_limit_since_censored' => false];
        }

        foreach (array_chunk($jobs, 100) as $chunk) {
            DB::connection(TestEnvironment::PRIMARY)->table('jobs')->insert($chunk);
        }

        foreach (array_chunk($samples, 50) as $chunk) {
            $this->mirror()->table('orrery_samples')->insert($chunk);
        }

        $statements = 0;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            // The over-limit runs; WaitTime's runtime and names read is the other samples statement.
            if (str_contains($query->sql, 'orrery_samples') && str_contains($query->sql, 'over_limit')) {
                $statements++;
            }
        });

        $rows = $this->app->make(WorkloadRepository::class)->get();

        self::assertSame(1, $statements, 'one over-limit statement on orrery_samples for all 300 over-limit rows');
        $byQueue = array_column($rows, null, 'name');
        self::assertCount(300, $byQueue);
        self::assertSame([$m - 120, false], [$byQueue['q007']['over_limit_since'], $byQueue['q007']['over_limit_since_censored']]);
        self::assertSame([$m, true], [$byQueue['q000']['over_limit_since'], $byQueue['q000']['over_limit_since_censored']]);
    }

    /**
     * Writes one measurement at $time: queue => oldest_age, threshold 60.
     *
     * @param  array<string, float>  $ages
     */
    private function writeAt(float $time, array $ages, string $connection = 'database'): void
    {
        $rows = [];

        foreach ($ages as $queue => $age) {
            $rows[] = ['connection' => $connection, 'connections' => [$connection], 'queue' => $queue, 'backlog' => 1, 'oldest_age' => $age, 'threshold' => 60];
        }

        $this->app->make(Samples::class)->write($rows, [], $time, new TickBudget(Clock::monotonic() + 30));
    }

    /**
     * [sampled_at, over_limit_since, over_limit_since_censored] of database:<queue>, oldest first.
     *
     * @return list<array{0: int, 1: int|null, 2: bool}>
     */
    private function runs(string $queue): array
    {
        return $this->mirror()->table('orrery_samples')
            ->where('scope', 'queue')->where('series', SampleCounts::series('queue', 'database', $queue))->whereNotNull('over_limit')->orderBy('minute')
            ->get(['minute', 'over_limit_since', 'over_limit_since_censored'])
            ->map(static fn (object $row): array => [(int) $row->minute, $row->over_limit_since === null ? null : (int) $row->over_limit_since, (bool) $row->over_limit_since_censored])
            ->all();
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function seedSamples(int $minute, int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $queue = sprintf('q%05d', $i);
            $rows[] = ['scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', $queue), 'connection' => 'database', 'queue' => $queue, 'minute' => $minute, 'ready' => 0, 'oldest_age' => null, 'workers' => 0, 'over_limit' => false];
        }

        foreach (array_chunk($rows, 80) as $chunk) {
            $this->mirror()->table('orrery_samples')->insert($chunk);
        }
    }

    /**
     * @return list<array{connection: string, queue: string, sampled_at: int, ready: int, oldest_age: ?float, workers: int, over_limit: bool}>
     */
    private function samples(): array
    {
        return $this->mirror()->table('orrery_samples')
            ->where('scope', 'queue')->whereNotNull('over_limit')
            ->orderBy('minute')->orderBy('connection')->orderBy('queue')
            ->get()
            ->map(static fn (object $row): array => [
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'sampled_at' => (int) $row->minute,
                'ready' => (int) $row->ready,
                'oldest_age' => $row->oldest_age === null ? null : (float) $row->oldest_age,
                'workers' => (int) $row->workers,
                'over_limit' => (bool) $row->over_limit,
            ])
            ->values()
            ->all();
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
