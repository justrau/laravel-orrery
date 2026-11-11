<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Monitoring;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Lifecycle\RuntimeClock;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Maintenance\TickOutcome;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Monitoring\SampleCoverage;
use Justrau\Orrery\Monitoring\Samples;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Retention\RetentionTestCase;
use Justrau\Orrery\Tests\Support\PackageStatements;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The per-minute counts of orrery_samples: the runtime on
 * the completion's row, the aggregation of the tick phase `sample_counts`,
 * its coverage rows, its recount window and catch-up, its live frontier,
 * the bounded counts floor of retention and the invalid minutes, the
 * aliases, the byte-exact series and the minute boundaries. These tests
 * read orrery_samples and the settings directly; the readers are separate.
 */
final class SampleCountsTest extends RetentionTestCase
{
    /** A minute boundary. */
    private const B = 1_790_000_040;

    private const MAIL = 'App\\Jobs\\Mail';

    private const REPORT = 'App\\Jobs\\Report';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The time share would stop a run on a loaded server; only the 1M-row test measures it.
        $app['config']->set('orrery.samples.count_seconds', 600);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Hooks::$callback = null;

        parent::tearDown();
    }

    /**
     * Rejects: a completion that leaves runtime_ms empty, that takes the runtime from the reservation while the runtime clock knows it, or that writes a metric itself.
     */
    public function test_completion_writes_runtime_ms_on_the_row(): void
    {
        $this->at(self::B + 10.0);
        $fallback = $this->uuid();
        $this->observe('push', $fallback, ['jobId' => 1, 'at' => self::B + 1.0]);
        $this->observe('reserve', $fallback, ['jobId' => 1, 'attempt' => 1, 'at' => self::B + 2.0]);
        $this->observe('complete', $fallback, ['jobId' => 1, 'attempt' => 1, 'at' => self::B + 4.5]);

        self::assertSame(2500, $this->runtimeOf($fallback), 'Without the runtime clock: (at - reserved_at) x 1000.');

        $clocked = $this->uuid();
        $this->observe('push', $clocked, ['jobId' => 2, 'at' => self::B + 1.0]);
        $this->observe('reserve', $clocked, ['jobId' => 2, 'attempt' => 1, 'at' => self::B + 2.0]);
        RuntimeClock::start($clocked);
        Clock::advanceMonotonicForTesting(0.75);
        $this->observe('complete', $clocked, ['jobId' => 2, 'attempt' => 1, 'at' => self::B + 9.0]);

        $runtime = $this->runtimeOf($clocked);
        self::assertNotNull($runtime);
        self::assertGreaterThanOrEqual(750, $runtime, 'The runtime clock wins over the reservation.');
        self::assertLessThan(5000, $runtime, 'The runtime clock wins over the reservation (7 s).');
        self::assertSame(0, $this->mirror()->table(SampleCounts::TABLE)->count(), 'A completion writes no sample (C6b): only the tick counts.');
    }

    /**
     * Rejects: 0 for a completion whose runtime is unknown (no runtime clock and no reservation).
     */
    public function test_unknown_runtime_is_null_not_zero(): void
    {
        $this->at(self::B + 10.0);
        $unreserved = $this->uuid();
        $this->observe('push', $unreserved, ['jobId' => 1, 'at' => self::B + 1.0]);
        $this->observe('complete', $unreserved, ['jobId' => 1, 'attempt' => 1, 'at' => self::B + 4.0]);

        $inserted = $this->uuid();
        $this->observe('complete', $inserted, ['jobId' => 2, 'attempt' => 1, 'at' => self::B + 4.0]);

        foreach ([$unreserved, $inserted] as $uuid) {
            self::assertSame('completed', $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status'));
            self::assertNull($this->runtimeOf($uuid), 'An unknown runtime is null.');
        }

        // The counts keep the unknown runtime out of the runtime average.
        $this->at(self::B + 70.0);
        $this->aggregate();
        $total = $this->total(self::B);
        self::assertSame([2, 0, 0, 0], [$total['completed'], $total['failed'], $total['runtime_ms_sum'], $total['runtime_count']]);
    }

    /**
     * Rejects: counting by pushed_at, a failed count of `status = failed` only, and a runtime count that includes the unknown runtimes.
     */
    public function test_counts_match_the_mirror_rows(): void
    {
        $this->at(self::B + 300 + 10.0);
        $queues = ['alpha', 'beta', 'gamma'];
        $names = [self::MAIL, self::REPORT];
        $expected = ['queue' => [], 'job' => [], 'total' => []];
        $add = static function (array &$bucket, string $key, int $minute, string $column, int $value): void {
            $bucket[$key][$minute] ??= ['completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0];
            $bucket[$key][$minute][$column] += $value;
        };
        $rows = [];

        for ($i = 0; $i < 300; $i++) {
            $minute = self::B + 60 * ($i % 4);
            $queue = $queues[$i % 3];
            $name = $names[$i % 2];
            $runtime = $i % 7 === 0 ? null : $i * 10;
            $rows[] = $this->mirrorRow(['queue' => $queue, 'name' => $name, 'status' => 'completed', 'finished_at' => $minute + ($i % 59) + 0.5, 'runtime_ms' => $runtime, 'pushed_at' => self::B - 1000.0]);

            foreach ([['queue', "database\0{$queue}"], ['job', $name], ['total', '']] as [$scope, $key]) {
                $add($expected[$scope], $key, $minute, 'completed', 1);

                if ($runtime !== null) {
                    $add($expected[$scope], $key, $minute, 'runtime_ms_sum', $runtime);
                    $add($expected[$scope], $key, $minute, 'runtime_count', 1);
                }
            }
        }

        for ($i = 0; $i < 40; $i++) {
            $minute = self::B + 60 * ($i % 4);
            $queue = $queues[$i % 3];
            $name = $names[$i % 2];
            $at = $minute + ($i % 50) + 0.25;
            // A quarter is waiting for a retry: still a failure of its minute.
            $status = $i % 4 === 0 ? 'pending' : 'failed';
            $rows[] = $this->mirrorRow(['queue' => $queue, 'name' => $name, 'status' => $status, 'failed_at' => $at, 'finished_at' => $status === 'failed' ? $at : null, 'pushed_at' => self::B - 1000.0]);

            foreach ([['queue', "database\0{$queue}"], ['job', $name], ['total', '']] as [$scope, $key]) {
                $add($expected[$scope], $key, $minute, 'failed', 1);
            }
        }

        $this->insertRows($rows);

        self::assertSame(5, $this->aggregate(), 'The run publishes the 5 minutes before the current one.');

        $quiet = ['completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0];
        $expected['total'][''][self::B + 240] = $quiet;

        foreach ($expected as $scope => $series) {
            $actual = [];

            foreach ($this->samples($scope) as $row) {
                $key = match ($scope) {
                    'queue' => $row['connection']."\0".$row['queue'],
                    'job' => $row['name'],
                    default => '',
                };
                $actual[$key][$row['minute']] = array_intersect_key($row, $quiet);
            }

            ksort($actual);
            ksort($series);

            foreach ($series as &$minutes) {
                ksort($minutes);
            }

            foreach ($actual as &$minutes) {
                ksort($minutes);
            }

            self::assertSame($series, $actual, "The {$scope} rows.");
        }

        self::assertSame(self::B + 300, $this->through());
    }

    /**
     * Rejects: aggregating each minute once (a run that starts at M).
     */
    public function test_observation_four_minutes_late_is_counted(): void
    {
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();
        self::assertSame(0, $this->total(self::B + 120)['completed']);

        // The completion of minute B + 120 reaches the mirror four minutes after its minute ended.
        $this->at(self::B + 360 + 20.0);
        $this->insertRows([$this->mirrorRow(['finished_at' => self::B + 120 + 10.0])]);
        $this->aggregate();

        self::assertSame(1, $this->total(self::B + 120)['completed'], 'The recount window counted the late row.');
        self::assertSame(self::B + 360, $this->through());
    }

    /**
     * Rejects: unknown (no total row) for a counted quiet minute, and a total row for a minute that no run counted.
     */
    public function test_covered_minute_without_activity_is_zero(): void
    {
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();

        $totals = $this->totals();
        self::assertSame([self::B, self::B + 60, self::B + 120, self::B + 180, self::B + 240], array_keys($totals));

        foreach ($totals as $total) {
            self::assertSame([0, 0], [$total['completed'], $total['failed']]);
        }

        self::assertSame([], $this->samples('queue'));
        self::assertSame([], $this->samples('job'));
    }

    /**
     * Rejects: an unbounded catch-up (every missed minute in one run), and no catch-up (a run that only counts its last 5 minutes).
     */
    public function test_catch_up_after_an_outage_is_bounded_per_run(): void
    {
        $now = self::B + 3 * 3600;
        $this->setFrontier(self::B, $now - 3 * 3600.0);
        $this->at($now + 10.0);

        self::assertSame(65, $this->aggregate());
        self::assertSame(self::B + 65 * 60, $this->through());
        self::assertSame(65, $this->countTotals());

        self::assertSame(65, $this->aggregate());
        self::assertSame(self::B + 130 * 60, $this->through());

        self::assertSame(50, $this->aggregate(), 'The third run covers the rest.');
        self::assertSame($now, $this->through());
        self::assertSame(180, $this->countTotals(), 'Every minute of the outage is covered.');
    }

    /**
     * Rejects: an unbounded counts floor: retention frozen while the aggregation fails.
     */
    public function test_failing_aggregation_stops_holding_retention_after_the_bound(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        $this->insertRows([
            $this->mirrorRow(['finished_at' => self::B + 360 + 5.0]),
            $this->mirrorRow(['finished_at' => self::B + 420 + 5.0]),
        ]);
        $this->failAggregation();

        // 30 minutes later: M is 30 minutes old, within the bound of 120 minutes.
        $this->at($start + 1800);
        $this->tick();
        self::assertSame(2, $this->mirror()->table('orrery_jobs')->count(), 'The floor holds the rows that the counts have not passed.');
        self::assertSame(self::B + 300, $this->through(), 'The aggregation failed.');

        // Past the bound: retention trims, and the trimmed minutes are invalid. The scheduler ran every minute
        // (the outcome of the tick a minute before), so the time since the tick above is no blind gap (BlindGaps).
        $this->previousTick($start + 120 * 60);
        $this->at($start + 121 * 60);
        $this->tick();
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'Past the bound retention trims as usual.');
        self::assertSame([[self::B + 360, self::B + 480]], $this->invalidIntervals());
        self::assertGreaterThan(0, $this->invalidVersion(), 'The trim raised the version.');
    }

    /**
     * Rejects: coverage published from an empty or partial minute after a trim.
     */
    #[DataProvider('trims')]
    public function test_trimmed_minutes_stay_unknown_after_recovery(bool $partial): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        // Minute X holds two rows, minute Y one.
        $x = self::B + 360;
        $y = self::B + 420;
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $x + 5.0]),
            $this->mirrorRow(['finished_at' => $x + 6.0]),
            $this->mirrorRow(['finished_at' => $y + 5.0]),
        ]);
        $this->failAggregation();

        if ($partial) {
            // One batch of one row: half of minute X.
            Retention::useLimitsForTesting(batch: 1, rows: 1);
        }

        $this->at($start + 121 * 60);
        $this->tick();
        Retention::useLimitsForTesting();
        self::assertSame($partial ? 2 : 0, $this->mirror()->table('orrery_jobs')->count());
        self::assertSame($partial ? [[$x, $x + 60]] : [[$x, $y + 60]], $this->invalidIntervals());

        // The aggregation recovers and crosses the gap.
        Hooks::$callback = null;
        $now = SampleCounts::minuteOf($start + 121 * 60);

        for ($runs = 0; $this->through() < $now; $runs++) {
            self::assertLessThan(5, $runs, 'The recovery converges.');
            $this->aggregate();
        }

        self::assertNull($this->totals()[$x] ?? null, 'The trimmed minute X has no total row: unknown, never zero.');

        if ($partial) {
            self::assertSame(1, $this->total($y)['completed'], 'Minute Y kept its row and is counted.');
        } else {
            self::assertNull($this->totals()[$y] ?? null, 'The trimmed minute Y has no total row.');
        }

        self::assertSame(0, $this->total($y + 60)['completed'], 'A quiet minute after the gap is 0.');
        self::assertSame(0, $this->total(self::B + 300)['completed'], 'A quiet minute before the gap stays 0.');
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function trims(): array
    {
        return ['full trim' => [false], 'partial trim' => [true]];
    }

    /**
     * Rejects: a trim and a minute publication that interleave (the invalid interval written after the trim's commit, or a publication without the lock), so a minute ends covered without its rows.
     */
    public function test_a_trim_racing_a_minute_publication_ends_covered_or_invalid(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $x = self::B + 360;
        $now = self::B + 420 + 10.0;

        // The trim commits first: the publication sees the interval and writes no total row.
        $this->at($now);
        $this->setFrontier(self::B + 300, $now - 7201);
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 5.0])]);
        $trimmed = false;
        Hooks::$callback = function (string $point) use (&$trimmed): void {
            if ($point === SampleCounts::BEFORE_LOCK && ! $trimmed) {
                $trimmed = true;
                $this->retention()->run();
            }
        };
        $this->aggregate();
        Hooks::$callback = null;

        self::assertTrue($trimmed);
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
        self::assertNull($this->totals()[$x] ?? null, 'The minute is invalid, not covered.');
        self::assertSame([[$x, $x + 60]], $this->invalidIntervals());
        self::assertSame(self::B + 420, $this->through(), 'M moved past the invalid minute.');

        // The publication commits first: covered with all its rows; the trim after it adds the interval.
        $this->mirror()->table(SampleCounts::TABLE)->delete();
        $this->mirror()->table('orrery_settings')->whereIn('key', [SampleCounts::INVALID, SampleCounts::COUNTED_THROUGH, SampleCounts::COUNTED_AT])->delete();
        $this->setFrontier(self::B + 300, $now - 7201);
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 5.0])]);
        $this->aggregate();
        self::assertSame(1, $this->total($x)['completed'], 'Covered with all its rows.');
        $this->at($now + 7300);
        $this->retention()->run();
        self::assertSame(0, $this->mirror()->table('orrery_jobs')->count());
        self::assertSame(1, $this->total($x)['completed']);
        self::assertSame([[$x, $x + 60]], $this->invalidIntervals(), 'The later trim makes the counted minute invalid: the readers show it unknown.');

        // The interval is written in the delete's transaction: when its write fails, nothing is deleted.
        $this->mirror()->table(SampleCounts::TABLE)->delete();
        $this->mirror()->table('orrery_settings')->whereIn('key', [SampleCounts::INVALID, SampleCounts::COUNTED_THROUGH, SampleCounts::COUNTED_AT])->delete();
        $this->at($now);
        $this->setFrontier(self::B + 300, $now - 7201);
        $kept = $this->mirrorRow(['finished_at' => $x + 5.0]);
        $this->insertRows([$kept]);
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_INVALID) {
                throw new RuntimeException('The interval write failed.');
            }
        };

        try {
            $this->retention()->run();
            self::fail('The failed interval write fails the batch.');
        } catch (RuntimeException) {
        } finally {
            Hooks::$callback = null;
        }

        self::assertSame(1, $this->mirror()->table('orrery_jobs')->where('uuid', $kept['uuid'])->count(), 'The delete rolled back with the interval write.');
        self::assertSame([], $this->invalidIntervals());

        // During a publication the row is locked: a trim cannot interleave.
        if ($this->testEnvironment()->inMemory()) {
            return;
        }

        $this->mirror()->table(SampleCounts::TABLE)->delete();
        $this->at($now);
        $this->setFrontier(self::B + 300, $now);
        self::assertFalse($this->lockIsTaken(), 'Control: without a publication the lock is free.');
        $blocked = null;
        Hooks::$callback = function (string $point) use (&$blocked): void {
            if ($point === SampleCounts::BEFORE_PUBLISH && $blocked === null) {
                $blocked = $this->lockIsTaken();
            }
        };
        $this->aggregate();
        self::assertTrue($blocked, 'Another session cannot lock the invalid minutes while a minute publishes.');
    }

    /**
     * Rejects: upserting only the groups that the recount returns (a vanished name keeps its old count).
     */
    public function test_vanished_name_returns_to_zero_on_recount(): void
    {
        $x = self::B + 120;
        $this->at(self::B + 300 + 10.0);
        // The vanished job is the only row of its queue in minute X.
        $mail = $this->mirrorRow(['name' => self::MAIL, 'queue' => 'mail', 'finished_at' => $x + 5.0]);
        $this->insertRows([$mail, $this->mirrorRow(['name' => self::REPORT, 'finished_at' => $x + 6.0])]);
        $failure = $this->mirrorRow(['name' => 'App\\Jobs\\Flaky', 'status' => 'failed', 'finished_at' => $x + 7.0, 'failed_at' => $x + 7.0]);
        $this->insertRows([$failure]);
        $this->aggregate();
        self::assertSame(['App\\Jobs\\Flaky' => [0, 1], self::MAIL => [1, 0], self::REPORT => [1, 0]], $this->jobCounts($x));

        // The mail job is forgotten; the flaky job is retried and fails again two minutes later.
        $this->mirror()->table('orrery_jobs')->where('uuid', $mail['uuid'])->delete();
        $this->mirror()->table('orrery_jobs')->where('uuid', $failure['uuid'])->update(['failed_at' => Clock::toDecimal($x + 125.0), 'finished_at' => Clock::toDecimal($x + 125.0)]);
        $this->at(self::B + 360 + 10.0);
        $this->aggregate();

        // The flaky job keeps its row of minute X with 0 outcomes: it still arrived then (pushed_at).
        self::assertSame(['App\\Jobs\\Flaky' => [0, 0], self::REPORT => [1, 0]], $this->jobCounts($x));
        $queues = [];

        foreach ($this->samples('queue') as $row) {
            if ($row['minute'] === $x) {
                $queues[(string) $row['queue']] = [$row['completed'], $row['failed']];
            }
        }

        ksort($queues);
        self::assertSame(['default' => [1, 0], 'mail' => [0, 0]], $queues, 'The vanished queue reads 0.');
        $sum = [array_sum(array_column($queues, 0)), array_sum(array_column($queues, 1))];
        self::assertSame($sum, [$this->total($x)['completed'], $this->total($x)['failed']], 'The total equals the sum of the queue rows.');
        self::assertSame(['App\\Jobs\\Flaky' => [0, 1]], $this->jobCounts($x + 120));
        self::assertSame(1, $this->total($x + 120)['failed']);
    }

    /**
     * Rejects: write chunks committed one by one, and M raised before the minute's writes committed.
     */
    #[DataProvider('failurePoints')]
    public function test_a_failed_minute_leaves_the_previous_complete_minute(string $point): void
    {
        $x = self::B + 180;
        $this->at($x + 60 + 10.0);
        $this->insertRows([$this->mirrorRow(['queue' => 'alpha', 'finished_at' => $x + 5.0]), $this->mirrorRow(['queue' => 'beta', 'finished_at' => $x + 6.0])]);
        $this->aggregate();
        $before = $this->samplesOfMinute($x);
        self::assertSame(2, $this->total($x)['completed']);
        self::assertSame($x + 60, $this->through());

        // One more row of minute X (a recount), and two rows of the new minute X + 60.
        $this->insertRows([
            $this->mirrorRow(['queue' => 'gamma', 'finished_at' => $x + 7.0]),
            $this->mirrorRow(['queue' => 'alpha', 'finished_at' => $x + 65.0]),
            $this->mirrorRow(['queue' => 'beta', 'finished_at' => $x + 66.0]),
        ]);
        $this->at($x + 120 + 10.0);
        SampleCounts::useRowsPerChunkForTesting(1);

        // The run counts X - 180 to X + 60: X is its 4th minute, X + 60 its 5th.
        $this->failAt($point, 4);
        self::assertSame($before, $this->samplesOfMinute($x), 'A failed recount leaves the previous complete minute, never partial zeros.');
        self::assertSame($x + 60, $this->through(), 'M did not move.');
        self::assertNull($this->totals()[$x + 60] ?? null, 'The run stopped: the later minute is not published.');

        $this->failAt($point, 5);
        self::assertSame(3, $this->total($x)['completed'], 'The recount of X committed on its own.');
        self::assertNull($this->totals()[$x + 60] ?? null, 'The failed new minute stays uncovered.');
        self::assertSame([], array_values(array_filter($this->samples('queue'), static fn (array $row): bool => $row['minute'] === $x + 60)), 'No partial rows of the failed minute.');
        self::assertSame($x + 60, $this->through(), 'M did not move past the failed minute.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failurePoints(): array
    {
        return ['the second upsert chunk throws' => ['chunk'], 'the M write throws' => ['frontier']];
    }

    /**
     * Rejects: one series per alias of one jobs table.
     */
    public function test_aliases_count_under_the_primary_alias(): void
    {
        $this->app['config']->set('queue.connections.zeta', $this->app['config']->get('queue.connections.database'));
        $x = self::B + 120;
        $this->at(self::B + 300 + 10.0);
        $this->insertRows([
            $this->mirrorRow(['connection' => 'database', 'finished_at' => $x + 1.0]),
            $this->mirrorRow(['connection' => 'database', 'finished_at' => $x + 2.0]),
            $this->mirrorRow(['connection' => 'zeta', 'finished_at' => $x + 3.0]),
            $this->mirrorRow(['connection' => 'zeta', 'status' => 'failed', 'failed_at' => $x + 4.0, 'finished_at' => $x + 4.0]),
            // A connection that is no longer configured keeps its own series.
            $this->mirrorRow(['connection' => 'retired', 'finished_at' => $x + 5.0]),
        ]);
        $this->aggregate();

        $queues = [];

        foreach ($this->samples('queue') as $row) {
            $queues[$row['connection'].':'.$row['queue']] = [$row['completed'], $row['failed']];
        }

        ksort($queues);
        self::assertSame(['database:default' => [3, 1], 'retired:default' => [1, 0]], $queues);
    }

    /**
     * Rejects: grouping or keying the series by a collated string: case, accent and trailing-space variants of a queue, job names that differ only in case, and a queue name with a colon.
     */
    public function test_series_are_byte_exact(): void
    {
        $x = self::B + 120;
        $variants = ['high', 'HIGH', 'hígh', 'high ', 'high:low'];
        $rows = [];

        foreach ($variants as $i => $queue) {
            $rows[] = $this->mirrorRow(['queue' => $queue, 'name' => $i % 2 === 0 ? 'App\\Jobs\\Mail' : 'App\\Jobs\\MAIL', 'finished_at' => $x + 1.0 + $i]);
        }

        // In the next minute only HIGH runs: the exact series high is quiet.
        $rows[] = $this->mirrorRow(['queue' => 'HIGH', 'name' => 'App\\Jobs\\MAIL', 'finished_at' => $x + 61.0]);
        $this->insertRows($rows);
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();

        $byMinute = [];

        foreach ($this->samples('queue') as $row) {
            $byMinute[$row['minute']][$row['queue']] = $row['completed'];
            self::assertSame($row['series'], SampleCounts::series('queue', $row['connection'], $row['queue']));
        }

        ksort($byMinute[$x], SORT_STRING);
        $expected = array_fill_keys($variants, 1);
        ksort($expected, SORT_STRING);
        self::assertSame($expected, $byMinute[$x], 'Each exact queue is its own series.');
        self::assertSame(['HIGH' => 1], $byMinute[$x + 60], 'The quiet series high has no row (0): a merged series would read 1.');
        self::assertSame(['App\\Jobs\\MAIL' => [2, 0], 'App\\Jobs\\Mail' => [3, 0]], $this->jobCounts($x));
    }

    /**
     * Rejects: a worklist trusted without the live frontier: a held aggregator recounts a minute that retention trimmed.
     */
    public function test_straddling_aggregators_never_zero_a_counted_minute(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $x = self::B + 3600;
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 30.0])]);
        $this->at($x + 300.0);
        $this->aggregate();
        self::assertSame(1, $this->total($x)['completed']);

        // A chooses X at 12:05:59 and is held before its first lock.
        $this->at($x + 359.0);
        $held = false;
        Hooks::$callback = function (string $point) use (&$held, $x): void {
            if ($point !== SampleCounts::BEFORE_LOCK || $held) {
                return;
            }

            $held = true;
            // B runs at 12:06:00 and moves M to 12:06; retention trims X's row.
            $this->at($x + 360.0);
            $this->aggregate();
            self::assertSame($x + 360, $this->through());
            $this->retention()->run();
            self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'Retention trimmed the counted row.');
            $this->at($x + 359.0);
        };
        $this->aggregate();

        self::assertTrue($held);
        self::assertSame(1, $this->total($x)['completed'], 'A skipped X: its count stays 1.');
    }

    /**
     * Rejects: an inclusive upper bound of a minute.
     */
    public function test_minute_boundaries_on_every_engine(): void
    {
        $x = self::B + 120;
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $x + 59.999]),
            $this->mirrorRow(['finished_at' => $x + 60.0]),
            $this->mirrorRow(['status' => 'failed', 'failed_at' => $x + 59.999, 'finished_at' => $x + 59.999]),
            $this->mirrorRow(['status' => 'failed', 'failed_at' => $x + 60.0, 'finished_at' => $x + 60.0]),
        ]);
        $this->at(self::B + 300 + 10.0);
        $this->aggregate();

        self::assertSame([1, 1], [$this->total($x)['completed'], $this->total($x)['failed']]);
        self::assertSame([1, 1], [$this->total($x + 60)['completed'], $this->total($x + 60)['failed']]);
    }

    /**
     * Rejects: one window per run (a catch-up that publishes all or nothing), and a stopped run that loses its finished minutes.
     */
    public function test_catch_up_of_1m_rows_advances_per_minute(): void
    {
        $environment = $this->testEnvironment();

        if ($environment->isServer() || $environment->inMemory()) {
            self::markTestSkipped('The 1,000,000-row catch-up runs on a SQLite file.');
        }

        $x = self::B + 3600;
        $now = $x + 65 * 60;
        // The rows in finished_at order, spread evenly over the 65 minutes (about 15,400 per minute).
        $this->mirror()->statement(sprintf(
            "INSERT INTO %sorrery_jobs (uuid, connection, queue, name, type, status, silenced, monitored, payload, payload_bytes, pushed_at, finished_at, updated_at, runtime_ms)
             WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < 999999)
             SELECT printf('%%08x-0000-4000-8000-%%012x', i, i), 'database', 'q' || (i %% 3), 'App\\Jobs\\J' || (i %% 2), 'job', 'completed', 0, 0, '{}', 2, %d, %d + i * 0.0039, %d, i %% 1000 FROM seq",
            $this->mirror()->getTablePrefix(),
            $x,
            $x,
            $x,
        ));
        self::assertSame(1_000_000, $this->mirror()->table('orrery_jobs')->count());
        $this->setFrontier($x, $now - 3900.0);
        $this->at($now + 10.0);
        $this->app['config']->set('orrery.samples.count_seconds', 0.5);

        $first = $this->aggregate();
        self::assertGreaterThanOrEqual(1, $first);
        self::assertLessThan(65, $first, 'The time share stopped the run.');
        self::assertSame($x + 60 * $first, $this->through(), 'M advanced minute by minute.');
        self::assertSame($first, $this->countTotals(), 'The stopped run kept its finished minutes.');

        $this->app['config']->set('orrery.samples.count_seconds', 600);
        $this->aggregate();
        self::assertSame($now, $this->through());
        self::assertSame(1_000_000, (int) $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->sum('completed'));
    }

    /**
     * Rejects: a runtime above the signed 32-bit range written as is (Postgres keeps unsignedInteger signed, so the completion update fails), and a runtime that is dropped instead of capped.
     */
    public function test_runtime_is_capped_to_the_portable_integer(): void
    {
        $t = self::B + 1.0;
        $this->at($t + 2_200_010.0);
        $uuid = $this->uuid();
        $this->observe('push', $uuid, ['jobId' => 1, 'at' => $t - 1.0]);
        $this->observe('reserve', $uuid, ['jobId' => 1, 'attempt' => 1, 'at' => $t]);
        $this->observe('complete', $uuid, ['jobId' => 1, 'attempt' => 1, 'at' => $t + 2_200_000.0]);

        self::assertSame('completed', $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status'));
        self::assertSame(2_147_483_647, JobStore::MAX_RUNTIME_MS);
        self::assertSame(2_147_483_647, $this->runtimeOf($uuid));
    }

    /**
     * Rejects: a run without the frontier M that trusts its worklist: after retention trimmed a minute's rows, the held run publishes that minute as a covered 0.
     */
    public function test_straddling_aggregator_without_frontier_never_zeroes_a_minute(): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $x = self::B + 3600;
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 30.0])]);
        self::assertNull($this->through(), 'No M: a first install, or a cleared M.');

        // A chooses X at 12:05:59 (its run starts at m_now - 300 = X) and is held before its first lock.
        $this->at($x + 359.0);
        $held = false;
        Hooks::$callback = function (string $point) use (&$held): void {
            if ($point !== SampleCounts::BEFORE_LOCK || $held) {
                return;
            }

            $held = true;
            // At 12:06:00 retention (floor: the current minute - 300 = X + 60) trims X's row.
            $this->at(self::B + 3600 + 360.0);
            $this->retention()->run();
            self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'Retention trimmed the uncounted row.');
        };
        $this->aggregate();

        self::assertTrue($held);
        self::assertNull($this->totals()[$x] ?? null, 'X is unknown (no total row), never a covered 0.');
        self::assertSame(0, $this->total($x + 60)['completed'], 'The minutes still in the window are counted.');
        // Without M the delete batches take the INVALID lock too (servers; a SQLite file locks the whole file anyway).
        if ($this->testEnvironment()->isServer()) {
            $this->insertRows([$this->mirrorRow(['finished_at' => $x + 30.0])]);
            $this->mirror()->table('orrery_settings')->whereIn('key', [SampleCounts::COUNTED_THROUGH, SampleCounts::COUNTED_AT])->delete();
            $locked = null;
            Hooks::$callback = function (string $point) use (&$locked): void {
                if ($point === Retention::IN_TRANSACTION && $locked === null) {
                    $locked = $this->lockIsTaken();
                }
            };
            $this->retention()->run();
            Hooks::$callback = null;
            self::assertTrue($locked, 'A delete batch without M holds the INVALID lock.');
        }
    }

    /**
     * Rejects: a catch-up that holds a SQLite file's write lock from one minute transaction to the next, and a pause on an engine that locks rows.
     */
    public function test_catch_up_yields_the_sqlite_file_between_minutes(): void
    {
        $this->at(self::B + 300 + 10.0);
        // Each unit does 200 ms of work, so every unit after the first pauses once.
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_LOCK) {
                Clock::advanceMonotonicForTesting(0.2);
            }
        };
        $pauses = [];
        SampleCounts::useLockModeForTesting('file', static function (int $microseconds) use (&$pauses): void {
            $pauses[] = $microseconds;
        });

        self::assertSame(5, $this->aggregate());
        self::assertSame(array_fill(0, 4, Retention::PAUSE_MICROSECONDS), $pauses, 'A pause before each unit that follows a write.');

        $pauses = [];
        SampleCounts::useLockModeForTesting('rows', static function (int $microseconds) use (&$pauses): void {
            $pauses[] = $microseconds;
        });
        $this->aggregate();
        self::assertSame([], $pauses, 'No pause where the engine locks rows.');

        // Short units share one pause per 150 ms of work.
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_LOCK) {
                Clock::advanceMonotonicForTesting(0.08);
            }
        };
        // Real time also passes (a loaded machine adds to it), so the test checks the rule itself:
        // at least 150 ms of work before each pause, counted from the run's start or the last pause.
        $at = [];
        SampleCounts::useLockModeForTesting('file', static function () use (&$at): void {
            $at[] = Clock::monotonic();
        });
        $started = Clock::monotonic();
        $this->aggregate();
        self::assertNotSame([], $at, '5 units of 80 ms of work pause at least once.');
        $previous = $started;

        foreach ($at as $pause) {
            self::assertGreaterThanOrEqual(Retention::PAUSE_MICROSECONDS / 1e6, $pause - $previous, 'At most one pause per 150 ms of work.');
            $previous = $pause;
        }

        $environment = $this->testEnvironment();
        self::assertSame($environment->isServer() || $environment->inMemory() ? 'rows' : 'file', Retention::lockModeOf($this->app->make(PackageSession::class)->mirror()));
    }

    /**
     * Rejects: a sample_counts phase that orrery:tick does not run (not registered or not tagged).
     */
    public function test_tick_counts_the_minutes(): void
    {
        $this->insertRows([$this->mirrorRow(['finished_at' => self::B + 120 + 5.0])]);
        $this->at(self::B + 300 + 10.0);

        self::assertSame(0, Artisan::call('orrery:tick'), Artisan::output());

        self::assertSame(self::B + 300, $this->through());
        self::assertSame(1, $this->total(self::B + 120)['completed']);
        self::assertSame(5, $this->countTotals());
    }

    /**
     * Rejects: a stored M off its minute boundary that is used as is (the run then counts unaligned minutes).
     */
    public function test_an_unaligned_frontier_is_floored(): void
    {
        $this->setFrontier(self::B + 17, self::B + 17.0);
        $this->at(self::B + 600 + 10.0);
        $this->aggregate();

        self::assertSame(range(self::B, self::B + 540, 60), array_keys($this->totals()), 'The run starts at the minute of M.');
        self::assertSame(self::B + 600, $this->through());
    }

    /**
     * Rejects: a run that ignores its time share (`samples.count_seconds`), and a run that ignores the tick budget; either would count all five due minutes.
     */
    public function test_time_share_and_tick_budget_stop_a_run(): void
    {
        $this->at(self::B + 300 + 10.0);
        $this->app['config']->set('orrery.samples.count_seconds', 1);
        // Each unit takes 2 s of work: the share ends after the first one.
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_LOCK) {
                Clock::advanceMonotonicForTesting(2.0);
            }
        };

        self::assertSame(1, $this->aggregate(), 'The first unit always runs; the share stops the run after it.');
        self::assertSame(self::B + 60, $this->through(), 'M moved by one minute.');

        // An exhausted tick budget stops the run after its first unit too.
        Hooks::$callback = null;
        $this->app['config']->set('orrery.samples.count_seconds', 600);
        self::assertSame(1, $this->app->make(SampleCounts::class)->run(new TickBudget(Clock::monotonic() - 1)));
        self::assertSame(self::B + 60, $this->through(), 'The recount of the first minute does not move M.');
    }

    // ---------------------------------------------------------------- the readers

    /**
     * Rejects: zero-filled buckets for a minute that was never counted (a fresh install with rows
     * older than its first run) in the throughput, the queue history and the job class series.
     */
    public function test_uncovered_minute_is_unknown_not_zero(): void
    {
        $this->app['config']->set('orrery.trim.completed', 1440);
        $old = self::B;
        $now = self::B + 3000 + 10.0;
        $current = SampleCounts::minuteOf($now);
        $counted = $current - 120;
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $old + 5.0]),
            $this->mirrorRow(['finished_at' => $counted + 5.0]),
        ]);
        $this->queueState('database', 'default', $old);
        $this->at($now);
        $this->aggregate();
        self::assertSame($current, $this->through(), 'The first run counted the last five minutes only.');

        $throughput = array_column($this->api('/orrery/api/throughput?minutes=60')['buckets'], 'completed', 'at');
        self::assertNull($throughput[$old], 'The minute before the first run is unknown in the throughput, although its row is kept.');
        self::assertSame(1, $throughput[$counted]);
        self::assertSame(0, $throughput[$counted - 60], 'A counted quiet minute is 0.');

        $history = array_column($this->api('/orrery/api/queues/history?connection=database&queue=default&minutes=60')['buckets'], null, 'at');
        self::assertSame([1, null], [$history[$old]['ready'], $history[$old]['completed']], 'The sampled minute has its state and unknown counts.');
        self::assertSame(1, $history[$counted]['completed']);

        $points = $this->classCompletions(60);
        self::assertNull($points[$old] ?? null, 'The job class series leaves the unknown minute unknown.');
        self::assertSame(1, $points[$counted]);
    }

    /**
     * Rejects: a trimmed minute that a reader shows as 0 (the API half of
     * test_trimmed_minutes_stay_unknown_after_recovery), and a quiet minute after the
     * gap that is unknown.
     */
    #[DataProvider('trims')]
    public function test_trimmed_minutes_read_as_unknown_in_every_api(bool $partial): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        $x = self::B + 360;
        $y = self::B + 420;
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $x + 5.0]),
            $this->mirrorRow(['finished_at' => $x + 6.0]),
            $this->mirrorRow(['finished_at' => $y + 5.0]),
        ]);
        $this->failAggregation();

        if ($partial) {
            Retention::useLimitsForTesting(batch: 1, rows: 1);
        }

        $this->at($start + 121 * 60);
        $this->tick();
        Retention::useLimitsForTesting();
        Hooks::$callback = null;
        $now = SampleCounts::minuteOf($start + 121 * 60);

        for ($runs = 0; $this->through() < $now; $runs++) {
            self::assertLessThan(5, $runs, 'The recovery converges.');
            $this->aggregate();
        }

        $this->queueState('database', 'default', $x);
        $throughput = array_column($this->api('/orrery/api/throughput?minutes=1440')['buckets'], 'completed', 'at');
        $history = array_column($this->api('/orrery/api/queues/history?connection=database&queue=default&minutes=1440')['buckets'], null, 'at');
        $points = $this->classCompletions(1440);

        self::assertNull($throughput[$x], 'The trimmed minute X is unknown in the throughput.');
        self::assertNull($history[$x]['completed'], 'and in the queue history');
        self::assertNull($points[$x] ?? null, 'and in the job class series');
        self::assertSame($partial ? 1 : null, $throughput[$y], $partial ? 'Minute Y kept its row and is counted.' : 'The trimmed minute Y is unknown.');
        self::assertSame(0, $throughput[$y + 60], 'A quiet minute after the gap is 0.');
    }

    /**
     * Rejects: the old full scan of the mirror, a scan that ignores samples_invalid, a `total` row
     * that outranks an invalid interval, a scan of a minute that the trim horizon may have cut, and
     * a scan of earlier minutes without M.
     */
    public function test_throughput_reads_samples_and_scans_only_from_m(): void
    {
        $this->app['config']->set('orrery.trim.completed', 1440);
        $now = self::B + 600 + 10.0;
        $m = SampleCounts::minuteOf($now);
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $m - 240 + 5.0]),
            $this->mirrorRow(['finished_at' => $m + 5.0]),
        ]);
        $this->at($now);
        $this->aggregate();
        self::assertSame($m, $this->through());
        // A stored count that differs from the rows: the reader must take it, not scan the minute.
        $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->where('minute', $m - 240)->update(['completed' => 7]);

        $statements = $this->jobStatements(fn () => $this->api('/orrery/api/throughput?minutes=10'));
        $completed = array_column($this->api('/orrery/api/throughput?minutes=10')['buckets'], 'completed', 'at');
        self::assertSame(7, $completed[$m - 240], 'The stored count of a minute before M, never a scan of it.');
        self::assertSame(1, $completed[$m], 'The minute from M on is scanned.');
        self::assertCount(2, $statements, 'One scan per kind (completions, failures), from M: '.json_encode($statements));

        // An invalid interval outranks the stored total row of its minute.
        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->update(['value' => json_encode(['version' => 1, 'intervals' => [[$m - 240, $m - 180]]])]);
        self::assertNull(array_column($this->api('/orrery/api/throughput?minutes=10')['buckets'], 'completed', 'at')[$m - 240], 'An invalid minute is unknown, its older total row too.');

        // The aggregation stalls and the scan reaches back: a retained minute after M is scanned,
        // an invalid one is not, and a minute that the trim horizon cuts is unknown.
        $this->setFrontier($m - 300, $now - 60);
        $this->mirror()->table(SampleCounts::TABLE)->where('minute', '>=', $m - 300)->delete();
        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->update(['value' => json_encode(['version' => 2, 'intervals' => [[$m - 120, $m - 60]]])]);
        $this->insertRows([$this->mirrorRow(['finished_at' => $m - 120 + 5.0]), $this->mirrorRow(['finished_at' => $m - 60 + 5.0])]);
        $this->app['config']->set('orrery.trim.completed', 3);
        $completed = array_column($this->api('/orrery/api/throughput?minutes=10')['buckets'], 'completed', 'at');
        self::assertNull($completed[$m - 240], 'Before the trim horizon (now - 3 minutes): unknown, although its row is kept.');
        self::assertNull($completed[$m - 180], 'The minute that the horizon cuts is unknown.');
        self::assertNull($completed[$m - 120], 'The invalid minute is never scanned.');
        self::assertSame(1, $completed[$m - 60], 'A retained minute after M is scanned.');

        // Without M only the current minute is scanned.
        $this->mirror()->table('orrery_settings')->whereIn('key', [SampleCounts::COUNTED_THROUGH, SampleCounts::COUNTED_AT, SampleCounts::INVALID])->delete();
        $this->app['config']->set('orrery.trim.completed', 1440);
        $completed = array_column($this->api('/orrery/api/throughput?minutes=10')['buckets'], 'completed', 'at');
        self::assertSame([$m => 1], array_filter($completed, static fn (?int $value): bool => $value !== null), 'Without M only the current minute is known.');
    }

    /**
     * Rejects: intervals read only before the scan, a version check that only
     * covers the interval's own minute, a stored-count read in two statements, and a split-minute
     * retention: a reader racing a trim, a clear or the sample retention never
     * reports a covered undercount.
     */
    #[DataProvider('racers')]
    public function test_reader_racing_a_trim_never_reports_a_covered_undercount(string $racer): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $now = self::B + 600 + 10.0;
        $m = SampleCounts::minuteOf($now);
        $this->at($now);
        $this->insertRows([$this->mirrorRow(['finished_at' => $m - 600 + 5.0])]);
        // M is 11 minutes behind and stalled for longer than its bound: the trim of a scanned
        // minute adds an interval.
        $this->setFrontier($m - 600, $now - 121 * 60);
        $this->mirror()->table(SampleCounts::TABLE)->insert([
            'scope' => 'total', 'series' => SampleCounts::series('total'), 'minute' => $m - 660, 'completed' => 4,
        ]);
        $raced = false;
        Hooks::$callback = function (string $point) use (&$raced, $racer): void {
            if ($point !== SampleCoverage::AFTER_COVERAGE || $raced) {
                return;
            }

            $raced = true;
            Hooks::$callback = null;

            if ($racer === 'trim') {
                $this->retention()->run();
                self::assertSame(0, $this->mirror()->table('orrery_jobs')->count(), 'The bounded-floor trim deleted the scanned row.');
            } else {
                $this->artisan('orrery:clear-metrics')->assertExitCode(0);
            }
        };

        $window = $this->coverage()->of('total', null, $m - 660, $m + 60);

        self::assertTrue($raced);

        foreach ($window->minutes() as $minute) {
            self::assertNull($window->value($minute, SampleCounts::series('total'), 'completed'), "Minute {$minute} of a read that a trim or a clear raced is unknown.");
        }

        // The next read decides again: the trimmed minute stays unknown, never 0.
        $after = $this->coverage()->of('total', null, $m - 660, $m + 60);
        self::assertNull($after->value($m - 600, SampleCounts::series('total'), 'completed'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function racers(): array
    {
        return ['the bounded-floor trim' => ['trim'], 'orrery:clear-metrics' => ['clear']];
    }

    /**
     * Rejects: a retention that deletes a retired minute's rows before its total row (a reader would
     * see a covered minute without its groups), and one that starts the next minute before the
     * last one is finished.
     */
    public function test_reader_racing_the_sample_retention_reads_old_values_or_unknown(): void
    {
        $now = self::B + 25 * 3600 + 10.0;
        $old = self::B;
        $this->at($now);

        foreach ([$old, $old + 60] as $minute) {
            $rows = [['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'name' => null, 'minute' => $minute, 'completed' => 6]];

            for ($i = 0; $i < 6; $i++) {
                $name = 'App\\Jobs\\Job'.$i;
                $rows[] = ['scope' => 'job', 'series' => SampleCounts::series('job', $name), 'connection' => null, 'queue' => null, 'name' => $name, 'minute' => $minute, 'completed' => 1];
            }

            $this->mirror()->table(SampleCounts::TABLE)->insert($rows);
        }

        $this->setFrontier($old + 120, $now);
        Samples::useRetentionRowsForTesting(3);
        $seen = [];
        Hooks::$callback = function (string $point) use (&$seen, $old): void {
            if ($point === Samples::AFTER_RETIRE) {
                // Between the retirement and the deletion of the groups: the minute reads as unknown.
                $window = $this->coverage()->of('job', ['name' => 'App\\Jobs\\Job0'], $old, $old + 60);
                $seen[] = $window->value($old, SampleCounts::series('job', 'App\\Jobs\\Job0'), 'completed');
            }
        };

        $deleted = $this->app->make(Samples::class)->retire(new TickBudget(Clock::monotonic() + 30));

        self::assertSame([null], $seen, 'A retired minute is unknown before its rows go, never a covered 0.');
        self::assertSame(3, $deleted, 'At most the run limit.');
        self::assertSame((string) $old, $this->mirror()->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->value('value'));
        self::assertSame(7, $this->mirror()->table(SampleCounts::TABLE)->where('minute', $old + 60)->count(), 'The next minute waits until the retired one is finished.');

        // The next runs finish the retired minute before the next one starts.
        for ($runs = 0; $this->mirror()->table(SampleCounts::TABLE)->where('minute', $old)->count() > 0; $runs++) {
            self::assertLessThan(3, $runs);
            self::assertSame(7, $this->mirror()->table(SampleCounts::TABLE)->where('minute', $old + 60)->count(), 'The next minute keeps its total row while the retired one has rows.');
            $this->app->make(Samples::class)->retire(new TickBudget(Clock::monotonic() + 30));
        }

        self::assertSame((string) ($old + 60), $this->mirror()->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->value('value'), 'Then the next minute is retired.');
        self::assertSame(0, $this->mirror()->table(SampleCounts::TABLE)->where('minute', $old + 60)->where('scope', 'total')->count(), 'Its total row went first.');

        // A reader held after its stored read while the retention retires that minute: the
        // reader applies RETIRED_THROUGH to everything it read, so the minute is unknown.
        $next = $old + 120;
        $this->mirror()->table(SampleCounts::TABLE)->insert([
            ['scope' => 'total', 'series' => SampleCounts::series('total'), 'connection' => null, 'queue' => null, 'name' => null, 'minute' => $next, 'completed' => 1],
            ['scope' => 'job', 'series' => SampleCounts::series('job', 'App\\Jobs\\Job0'), 'connection' => null, 'queue' => null, 'name' => 'App\\Jobs\\Job0', 'minute' => $next, 'completed' => 1],
        ]);
        $this->setFrontier($next + 60, $now);
        $this->mirror()->table(SampleCounts::TABLE)->where('minute', '<=', $old + 60)->delete();
        Hooks::$callback = function (string $point): void {
            if ($point === SampleCoverage::AFTER_COVERAGE) {
                Hooks::$callback = null;
                $this->app->make(Samples::class)->retire(new TickBudget(Clock::monotonic() + 30));
            }
        };
        $window = $this->coverage()->of('job', ['name' => 'App\\Jobs\\Job0'], $next, $next + 60);
        self::assertSame((string) $next, $this->mirror()->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->value('value'));
        self::assertNull($window->value($next, SampleCounts::series('job', 'App\\Jobs\\Job0'), 'completed'), 'A minute retired during the read is unknown.');
    }

    /**
     * Rejects: deleting samples_invalid on clear, a clear that keeps M (the cleared
     * span would read as covered zeros), and a clear that a racing minute publication undoes.
     */
    #[DataProvider('trims')]
    public function test_clear_metrics_keeps_trimmed_minutes_unknown(bool $partial): void
    {
        $this->app['config']->set('orrery.trim.completed', 0);
        $start = self::B + 300 + 10.0;
        $this->at($start);
        $this->aggregate();
        $x = self::B + 360;
        $this->insertRows([
            $this->mirrorRow(['finished_at' => $x + 5.0]),
            $this->mirrorRow(['finished_at' => $x + 6.0]),
        ]);
        $this->failAggregation();

        if ($partial) {
            Retention::useLimitsForTesting(batch: 1, rows: 1);
        }

        $clearAt = $start + 121 * 60;
        $this->at($clearAt);
        $this->tick();
        Retention::useLimitsForTesting();
        Hooks::$callback = null;
        self::assertNotSame([], $this->invalidIntervals(), 'The trim recorded the destroyed minute.');

        $this->artisan('orrery:clear-metrics')->assertExitCode(0);
        $cleared = SampleCounts::minuteOf($clearAt);

        // Recovery: the aggregation runs again in the next minutes.
        foreach ([70, 130] as $later) {
            $this->at($clearAt + $later);
            $this->aggregate();
        }

        $window = $this->coverage()->of('total', null, $x, $cleared + 120);

        foreach ($window->minutes() as $minute) {
            if ($minute < $cleared) {
                self::assertNull($window->value($minute, SampleCounts::series('total'), 'completed'), "Minute {$minute} before the clear is unknown.");
            }
        }

        self::assertSame(0, $window->value($cleared + 60, SampleCounts::series('total'), 'completed'), 'Counting resumes after the clear.');

        // A clear that races a minute publication: either order leaves no covered minute before the clear.
        foreach (['clear first', 'publication first'] as $order) {
            $this->mirror()->table(SampleCounts::TABLE)->delete();
            $this->mirror()->table('orrery_settings')->whereIn('key', [SampleCounts::INVALID, SampleCounts::COUNTED_THROUGH, SampleCounts::COUNTED_AT])->delete();
            $this->at($start);
            $this->setFrontier(self::B + 300, $start);
            $this->insertRows([$this->mirrorRow(['finished_at' => self::B + 360 + 30.0])]);
            $this->at(self::B + 420 + 10.0);
            $cleared = self::B + 420;
            $done = false;
            Hooks::$callback = function (string $point) use (&$done, $order): void {
                $at = $order === 'clear first' ? SampleCounts::BEFORE_LOCK : SampleCounts::BEFORE_FRONTIER;

                if ($point === $at && ! $done) {
                    $done = true;

                    if ($order === 'clear first') {
                        $this->artisan('orrery:clear-metrics')->assertExitCode(0);
                    }
                }
            };
            $this->aggregate();
            Hooks::$callback = null;

            if ($order === 'publication first') {
                $this->artisan('orrery:clear-metrics')->assertExitCode(0);
            }

            self::assertTrue($done);
            $covered = array_filter(array_keys($this->totals()), static fn (int $minute): bool => $minute < $cleared);
            self::assertSame([], array_values($covered), "{$order}: no covered minute before the clear.");
        }
    }

    /**
     * Rejects: a minute decided against the trim horizon at the end of the reads only:
     * when the wall clock steps back during a read, a minute that the scan left out (before the
     * start horizon) would read as a covered 0.
     */
    public function test_a_clock_step_back_during_a_read_never_reports_an_unscanned_minute(): void
    {
        $this->app['config']->set('orrery.trim.completed', 3);
        $t = self::B + 3600 + 10.0;
        $this->at($t);
        $this->setFrontier(SampleCounts::minuteOf($t) - 600, $t);
        $x = SampleCounts::minuteOf($t - 240);
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 5.0])]);
        Hooks::$callback = function (string $point) use ($t): void {
            if ($point === SampleCoverage::AFTER_COVERAGE) {
                Hooks::$callback = null;
                // The clock steps back two minutes between the coverage lookup and the scan.
                $this->at($t - 120);
            }
        };

        $window = $this->coverage()->of('total', null, $x, $x + 60);

        self::assertNull($window->value($x, SampleCounts::series('total'), 'completed'), 'Before the start horizon the scan left the minute out: unknown, never 0.');
    }

    /**
     * Rejects: a reader that shows a stored minute past the samples' retention: the
     * aggregation and the retirement treat it as gone, so its counts may be partly deleted.
     */
    public function test_minutes_past_the_samples_retention_are_unknown(): void
    {
        $t = self::B + 25 * 3600 + 10.0;
        $this->at($t);
        $edge = SampleCounts::minuteOf($t - 24 * 3600) + 60;

        foreach ([$edge - 120, $edge - 60, $edge] as $minute) {
            $this->mirror()->table(SampleCounts::TABLE)->insert(['scope' => 'total', 'series' => SampleCounts::series('total'), 'minute' => $minute, 'completed' => 3]);
        }

        $this->setFrontier($edge + 60, $t);
        $window = $this->coverage()->of('total', null, $edge - 120, $edge + 60);

        self::assertNull($window->value($edge - 120, SampleCounts::series('total'), 'completed'));
        self::assertNull($window->value($edge - 60, SampleCounts::series('total'), 'completed'), 'The last minute before the horizon is unknown.');
        self::assertSame(3, $window->value($edge, SampleCounts::series('total'), 'completed'), 'The first minute that the aggregation still counts is known.');
    }

    /**
     * Rejects: reads before BEGIN that are kept after the frontier changed (a cost of the
     * tick): a trim or a clear that commits between the reads and the lock raises the version,
     * and the minute is read again under the lock.
     */
    public function test_reads_before_begin_are_redone_when_the_frontier_changed(): void
    {
        $x = self::B + 120;
        $this->at(self::B + 300 + 10.0);
        $this->insertRows([$this->mirrorRow(['finished_at' => $x + 5.0])]);
        $raced = false;
        $reads = 0;
        Hooks::$callback = function (string $point) use (&$raced, &$reads, $x): void {
            // The run counts B, B + 60 and X: the third reads are X's.
            if ($point !== SampleCounts::AFTER_READS || ++$reads !== 3) {
                return;
            }

            $raced = true;
            // Between the reads and the lock of minute X: a row of minute X arrives and the version
            // of INVALID moves (as a trim of another minute does).
            $this->insertRows([$this->mirrorRow(['finished_at' => $x + 6.0])]);
            $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->update(['value' => json_encode(['version' => 7, 'intervals' => []])]);
        };
        $this->aggregate();
        Hooks::$callback = null;

        self::assertTrue($raced);
        self::assertSame(2, $this->total($x)['completed'], 'Minute X was published from reads after the version changed: both rows.');

        // Control: with the frontier unchanged, the reads before BEGIN stand (a row that arrives
        // later is counted by the next recount).
        $this->at(self::B + 360 + 10.0);
        $late = false;
        $reads = 0;
        Hooks::$callback = function (string $point) use (&$late, &$reads, $x): void {
            // This run counts B + 60 and X: the second reads are X's.
            if ($point === SampleCounts::AFTER_READS && ++$reads === 2) {
                $late = true;
                $this->insertRows([$this->mirrorRow(['finished_at' => $x + 7.0])]);
            }
        };
        $this->aggregate();
        Hooks::$callback = null;
        self::assertTrue($late);
        self::assertSame(2, $this->total($x)['completed'], 'The reads before BEGIN stood.');
        $this->aggregate();
        self::assertSame(3, $this->total($x)['completed'], 'The next recount counts the late row.');
    }

    /**
     * Rejects: a lock order in which the queue state of the current minute waits on an aggregation
     * transaction of an earlier minute: on MySQL a range UPDATE or DELETE over the minute takes gap
     * locks up to the next minute's rows; this needs a second session of the
     * same database, so it runs on MySQL and Postgres.
     */
    public function test_current_minute_samples_never_wait_on_a_minute_transaction(): void
    {
        $driver = $this->mirror()->getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('A SQLite file has one writer: the queue state waits for the minute transaction by design (busy timeout). This run uses '.$driver.'.');
        }

        $now = self::B + 600 + 10.0;
        $m = SampleCounts::minuteOf($now);
        $this->at($now);
        $queues = ['alpha', 'beta', 'gamma', 'delta'];
        $rows = [];

        foreach ($queues as $i => $queue) {
            foreach ([$m - 180, $m - 120, $m - 60] as $minute) {
                $rows[] = $this->mirrorRow(['queue' => $queue, 'finished_at' => $minute + 5.0 + $i]);
            }
        }

        $this->insertRows($rows);
        $measured = array_map(static fn (string $queue): array => ['connection' => 'database', 'connections' => ['database'], 'queue' => $queue, 'backlog' => 2, 'oldest_age' => 5.0, 'threshold' => 60], $queues);
        // The steady state: the queue state of the earlier minutes and their counts exist.
        foreach ([$m - 180, $m - 120, $m - 60] as $minute) {
            $this->app->make(Samples::class)->write($measured, [], $minute + 30.0, new TickBudget(Clock::monotonic() + 30));
        }

        $this->aggregate();
        // A recount: one more row in the minute before, and one row less in another queue.
        $this->insertRows([$this->mirrorRow(['queue' => 'beta', 'finished_at' => $m - 60 + 40.0])]);
        $this->mirror()->table('orrery_jobs')->where('queue', 'gamma')->where('finished_at', '>=', Clock::toDecimal($m - 60))->delete();
        $other = $this->secondSession();
        // The current minute also samples a new queue whose series sorts before every existing one:
        // a range statement over the earlier minute locks the gap where its key goes.
        $first = min(array_map(static fn (string $queue): string => SampleCounts::series('queue', 'database', $queue), $queues));
        $new = null;

        for ($i = 0; $new === null; $i++) {
            self::assertLessThan(100_000, $i);
            $new = strcmp(SampleCounts::series('queue', 'database', 'new'.$i), $first) < 0 ? 'new'.$i : null;
        }

        $current = [...$measured, ['connection' => 'database', 'connections' => ['database'], 'queue' => $new, 'backlog' => 2, 'oldest_age' => 5.0, 'threshold' => 60]];
        $waited = null;
        $units = 0;
        Hooks::$callback = function (string $point) use (&$waited, &$units, $other, $current, $now): void {
            // The run publishes M - 300 to M - 60: the 5th minute is M - 60, the one before the current minute.
            if ($point !== SampleCounts::BEFORE_FRONTIER || ++$units !== 5) {
                return;
            }

            // Inside the minute transaction of M - 60, after its writes: the queue state of the current minute.
            $started = microtime(true);
            $this->app->make(Samples::class)->write($current, [], $now, new TickBudget(Clock::monotonic() + 30), $other);
            $waited = microtime(true) - $started;
        };
        $this->aggregate();
        Hooks::$callback = null;

        self::assertNotNull($waited, 'The hold point was reached.');
        self::assertLessThan(1.0, $waited, 'The current-minute write did not wait on the minute transaction (its lock timeout is 1 s).');
        $state = $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->where('minute', $m)->orderBy('queue')->pluck('ready', 'queue')->map(static fn ($ready): int => (int) $ready)->all();
        self::assertSame(['alpha' => 2, 'beta' => 2, 'delta' => 2, 'gamma' => 2, $new => 2], $state);
        $counts = $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->where('minute', $m - 60)->orderBy('queue')->pluck('completed', 'queue')->map(static fn ($n): int => (int) $n)->all();
        self::assertSame(['alpha' => 1, 'beta' => 2, 'delta' => 1, 'gamma' => 0], $counts, 'The recount replaced the counts and kept the queue state rows.');
        self::assertSame(4, $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'queue')->where('minute', $m - 60)->whereNotNull('over_limit')->count());

        if ($driver === 'mysql') {
            // The plan of the statements that zero and delete a minute's stale rows: the primary key
            // (point lookups, record locks only), never the minute index or a scope range.
            $table = $this->mirror()->getTablePrefix().SampleCounts::TABLE;
            $series = [SampleCounts::series('queue', 'database', 'gamma'), SampleCounts::series('queue', 'database', 'beta')];

            foreach ([
                "EXPLAIN UPDATE `{$table}` SET `completed` = 0, `failed` = 0, `runtime_ms_sum` = 0, `runtime_count` = 0 WHERE `scope` = ? AND `series` IN (?, ?) AND `minute` = ?",
                "EXPLAIN DELETE FROM `{$table}` WHERE `scope` = ? AND `series` IN (?, ?) AND `minute` = ?",
            ] as $sql) {
                $plan = $this->mirror()->select($sql, ['queue', ...$series, $m - 60]);
                self::assertSame('PRIMARY', $plan[0]->key ?? null, 'The plan uses the primary key: '.json_encode($plan));
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A JSON API response of the dashboard, allowed for this request.
     *
     * @return array<mixed>
     */
    private function api(string $uri): array
    {
        Orrery::auth(static fn (): bool => true);

        return (array) $this->getJson($uri)->assertOk()->json();
    }

    private function coverage(): SampleCoverage
    {
        return $this->app->make(SampleCoverage::class);
    }

    /**
     * The completions of the job class MAIL per minute (GET /api/classes/series):
     * null, or no key, for an unknown minute.
     *
     * @return array<int|string, int|null>
     */
    private function classCompletions(int $minutes): array
    {
        return array_column($this->api('/orrery/api/classes/series?minutes='.$minutes.'&name='.rawurlencode(self::MAIL))['buckets'], 'completed', 'at');
    }

    /**
     * The sampled queue state of one minute (ready 1).
     */
    private function queueState(string $connection, string $queue, int $minute): void
    {
        $this->mirror()->table(SampleCounts::TABLE)->upsert([[
            'scope' => 'queue', 'series' => SampleCounts::series('queue', $connection, $queue), 'connection' => $connection, 'queue' => $queue,
            'minute' => $minute, 'ready' => 1, 'workers' => 0, 'oldest_age' => '1.000000', 'over_limit' => false,
        ]], ['scope', 'series', 'minute'], ['ready', 'workers', 'oldest_age', 'over_limit']);
    }

    /**
     * The statements on orrery_jobs of the package's mirror session while $work runs.
     *
     * @return list<string>
     */
    private function jobStatements(callable $work): array
    {
        $statements = [];
        PackageStatements::listen($this->app, static function (string $kind, string $sql) use (&$statements): void {
            if ($kind === PackageSession::STATEMENT && str_contains($sql, 'orrery_jobs')) {
                $statements[] = $sql;
            }
        });
        $work();
        PackageStatements::reset();

        return $statements;
    }

    /**
     * A second package session of the mirror's database, with a lock timeout of 1 s: another
     * process's session.
     */
    private function secondSession(): Connection
    {
        $name = (string) ($this->app['config']->get('orrery.database') ?: $this->app['config']->get('database.default'));
        $this->app['config']->set('database.connections.c6b_second', $this->app['config']->get('database.connections.'.$name));
        $session = $this->app->make(PackageSession::class)->for('c6b_second');

        if ($session->getDriverName() === 'mysql') {
            $session->statement('SET SESSION innodb_lock_wait_timeout = 1');
        } else {
            $session->statement("SET lock_timeout = '1s'");
        }

        return $session;
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function aggregate(): int
    {
        return $this->app->make(SampleCounts::class)->run();
    }

    /** The aggregation throws on every run (the tick's watcher reports it). */
    private function failAggregation(): void
    {
        Hooks::$callback = static function (string $point): void {
            if ($point === SampleCounts::BEFORE_LOCK) {
                throw new RuntimeException('The aggregation fails.');
            }
        };
    }

    /**
     * Runs the aggregation with a failure in its $unit-th minute: at the 2nd
     * write chunk ('chunk') or before the M write ('frontier').
     */
    private function failAt(string $point, int $unit): void
    {
        $reached = 0;
        $chunks = 0;
        Hooks::$callback = static function (string $p) use ($point, $unit, &$reached, &$chunks): void {
            if ($p === SampleCounts::BEFORE_LOCK) {
                $reached++;
            }

            if ($reached !== $unit) {
                return;
            }

            if ($point === 'chunk' && $p === SampleCounts::AFTER_CHUNK && ++$chunks === 2) {
                throw new RuntimeException('The second chunk failed.');
            }

            if ($point === 'frontier' && $p === SampleCounts::BEFORE_FRONTIER) {
                throw new RuntimeException('The M write failed.');
            }
        };

        try {
            $this->aggregate();
            self::fail('The run stops at the failing minute.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('failed', $error->getMessage());
        } finally {
            Hooks::$callback = null;
        }
    }

    /**
     * The outcome of a tick that started at $at (BlindGaps measures from it).
     */
    private function previousTick(float $at): void
    {
        $this->mirror()->table('orrery_settings')->upsert([
            ['key' => TickOutcome::KEY, 'value' => json_encode(['started_at' => $at, 'finished_at' => $at + 1, 'failed' => [], 'skip_streaks' => (object) []])],
        ], ['key'], ['value']);
    }

    private function setFrontier(int $through, float $countedAt): void
    {
        $this->mirror()->table('orrery_settings')->upsert([
            ['key' => SampleCounts::COUNTED_THROUGH, 'value' => (string) $through],
            ['key' => SampleCounts::COUNTED_AT, 'value' => Clock::toDecimal($countedAt)],
        ], ['key'], ['value']);
    }

    private function through(): ?int
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->value('value');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function invalidIntervals(): array
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true);

        return is_array($value) ? $value['intervals'] : [];
    }

    private function invalidVersion(): int
    {
        $value = json_decode((string) $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->value('value'), true);

        return is_array($value) ? (int) $value['version'] : -1;
    }

    /**
     * A mirror row for insertRows(): a completed job of database:default.
     *
     * @param  array<string, mixed>  $columns
     * @return array<string, mixed>
     */
    private function mirrorRow(array $columns): array
    {
        $finished = array_key_exists('finished_at', $columns) ? $columns['finished_at'] : self::B;
        $row = array_replace([
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => 'database',
            'queue' => 'default',
            'name' => self::MAIL,
            'type' => 'job',
            'status' => 'completed',
            'silenced' => false,
            'monitored' => false,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $finished ?? self::B,
            'finished_at' => $finished,
            'updated_at' => self::B,
        ], $columns);

        foreach (['pushed_at', 'finished_at', 'failed_at', 'updated_at'] as $time) {
            if (is_float($row[$time] ?? null) || is_int($row[$time] ?? null)) {
                $row[$time] = Clock::toDecimal((float) $row[$time]);
            }
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertRows(array $rows): void
    {
        $columns = [];

        foreach ($rows as $row) {
            $columns += array_flip(array_keys($row));
        }

        $full = array_map(static fn (array $row): array => array_replace(array_fill_keys(array_keys($columns), null), $row), $rows);

        foreach (array_chunk($full, 50) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }
    }

    private function runtimeOf(string $uuid): ?int
    {
        $value = $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('runtime_ms');

        return $value === null ? null : (int) $value;
    }

    /**
     * @return list<array{scope: string, series: string, connection: string|null, queue: string|null, name: string|null, minute: int, completed: int, failed: int, runtime_ms_sum: int, runtime_count: int}>
     */
    private function samples(string $scope): array
    {
        return $this->mirror()->table(SampleCounts::TABLE)->where('scope', $scope)->orderBy('minute')->get()
            ->map(static fn (object $row): array => [
                'scope' => (string) $row->scope,
                'series' => (string) $row->series,
                'connection' => $row->connection === null ? null : (string) $row->connection,
                'queue' => $row->queue === null ? null : (string) $row->queue,
                'name' => $row->name === null ? null : (string) $row->name,
                'minute' => (int) $row->minute,
                'completed' => (int) $row->completed,
                'failed' => (int) $row->failed,
                'runtime_ms_sum' => (int) $row->runtime_ms_sum,
                'runtime_count' => (int) $row->runtime_count,
            ])->values()->all();
    }

    /**
     * @return array<int, array{completed: int, failed: int, runtime_ms_sum: int, runtime_count: int}>
     */
    private function totals(): array
    {
        $totals = [];

        foreach ($this->samples('total') as $row) {
            $totals[$row['minute']] = ['completed' => $row['completed'], 'failed' => $row['failed'], 'runtime_ms_sum' => $row['runtime_ms_sum'], 'runtime_count' => $row['runtime_count']];
        }

        return $totals;
    }

    /**
     * @return array{completed: int, failed: int, runtime_ms_sum: int, runtime_count: int}
     */
    private function total(int $minute): array
    {
        $totals = $this->totals();
        self::assertArrayHasKey($minute, $totals, "Minute {$minute} has a total row.");

        return $totals[$minute];
    }

    private function countTotals(): int
    {
        return $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->count();
    }

    /**
     * @return array<string, array{0: int, 1: int}> name => [completed, failed]
     */
    private function jobCounts(int $minute): array
    {
        $counts = [];

        foreach ($this->samples('job') as $row) {
            if ($row['minute'] === $minute) {
                $counts[(string) $row['name']] = [$row['completed'], $row['failed']];
            }
        }

        ksort($counts, SORT_STRING);

        return $counts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function samplesOfMinute(int $minute): array
    {
        return array_values(array_filter(
            [...$this->samples('total'), ...$this->samples('queue'), ...$this->samples('job')],
            static fn (array $row): bool => $row['minute'] === $minute,
        ));
    }

    /**
     * Whether another session fails to take the write lock of the invalid
     * minutes at once with the engine's lock error: Postgres 55P03 (FOR
     * UPDATE NOWAIT), MySQL 3572 (FOR UPDATE NOWAIT), SQLite SQLITE_BUSY
     * (BEGIN IMMEDIATE without a busy timeout; the previous timeout is
     * restored). Any other error fails the test.
     */
    private function lockIsTaken(): bool
    {
        $other = $this->mirror();
        $table = $other->getTablePrefix().'orrery_settings';

        if ($other->getDriverName() === 'sqlite') {
            $pdo = $other->getPdo();
            $previous = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
            $pdo->exec('PRAGMA busy_timeout = 0');

            try {
                $pdo->exec('BEGIN IMMEDIATE');
                $pdo->exec('ROLLBACK');

                return false;
            } catch (PDOException $error) {
                self::assertSame(5, $error->errorInfo[1] ?? null, 'SQLITE_BUSY: '.$error->getMessage());

                return true;
            } finally {
                $pdo->exec('PRAGMA busy_timeout = '.$previous);
            }
        }

        $key = $other->getDriverName() === 'pgsql' ? '"key"' : '`key`';

        try {
            $other->select("select value from {$table} where {$key} = ? for update nowait", [SampleCounts::INVALID]);

            return false;
        } catch (QueryException $error) {
            if ($other->getDriverName() === 'pgsql') {
                self::assertSame('55P03', $error->getCode(), $error->getMessage());
            } else {
                // MySQL's NOWAIT error; MariaDB reports a NOWAIT miss as a lock wait timeout.
                self::assertSame($other->getDriverName() === 'mariadb' ? 1205 : 3572, $error->errorInfo[1] ?? null, $error->getMessage());
            }

            return true;
        }
    }
}
