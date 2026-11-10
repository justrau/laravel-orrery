<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * The rates of GET /api/workload from the per-minute samples:
 * `in_per_min` = Σ pushed ÷ 5 and `out_per_min` = Σ (completed
 * + failed) ÷ 5 over the five complete minutes before the current minute,
 * read through SampleCoverage, with `rates_from` and `rates_to`.
 */
final class WorkloadRatesTest extends ControllerTest
{
    /** The current minute: a minute boundary. */
    private const M0 = 1_790_000_040;

    /** The request time: 12.5 s into the current minute. */
    private const NOW = self::M0 + 12.5;

    private const QUEUES = ['alpha', 'beta', 'gamma'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Every queue has a workload row without a job; the measurement is not shared between requests.
        $app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        $app['config']->set('orrery.waits', ['*' => 60, 'database:alpha' => 60, 'database:beta' => 60, 'database:gamma' => 60, 'database:quiet' => 60]);
        // The trim horizons reach past the window (a 10-minute trim would cut the scan of the oldest minute).
        $app['config']->set('orrery.trim.completed', 1440);
        $app['config']->set('orrery.trim.silenced', 1440);
        // A second name of the same jobs table: its rows count under the primary alias.
        $app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: rates that still scan orrery_jobs on every poll (the two removed scans of counts()),
     * a window that includes the current minute or the minute before the window, an out rate of
     * `status = failed` rows only (the samples count every row by failed_at), an in rate of
     * `pending` rows only, and a divisor other than 5.
     * Still passes: a reader that decides the rate of a second alias of the row from its own series
     * (the samples store every alias under the primary one).
     */
    public function test_with_m_at_the_current_minute_the_rates_come_from_the_samples_only(): void
    {
        $expected = [];

        foreach (self::QUEUES as $q => $queue) {
            $expected[$queue] = ['in' => 0, 'out' => 0];

            // Outside the window: the minute before it and the current minute.
            $this->row($queue, 'completed', ['pushed_at' => self::M0 - 305.0, 'finished_at' => self::M0 - 301.0]);
            $this->row($queue, 'pending', ['pushed_at' => self::M0 + 1.0]);
            $this->row($queue, 'failed', ['pushed_at' => self::M0 - 900.0, 'failed_at' => self::M0 + 2.0, 'finished_at' => self::M0 + 2.0]);

            for ($minute = 0; $minute < 5; $minute++) {
                $start = self::M0 - 300 + 60 * $minute;

                for ($i = 0; $i <= $q + $minute; $i++) {
                    // An arrival in every status; the pushed rows that finished in the same minute count once per kind.
                    $status = ['pending', 'reserved', 'completed', 'failed', 'retrying'][$i % 5];
                    $values = ['pushed_at' => $start + 1.0 + $i];

                    if ($status === 'completed') {
                        $values['finished_at'] = $start + 30.0;
                        $expected[$queue]['out']++;
                    } elseif ($status === 'failed' || $status === 'retrying') {
                        $values['failed_at'] = $start + 40.0;
                        $values['finished_at'] = $start + 40.0;
                        $expected[$queue]['out']++;
                    } elseif ($status === 'reserved') {
                        $values['reserved_at'] = self::NOW - 600.0;
                    }

                    $this->row($queue, $status, $values);
                    $expected[$queue]['in']++;
                }
            }
        }

        // A row of the second alias counts under the primary alias.
        $this->row('alpha', 'pending', ['pushed_at' => self::M0 - 100.0], 'database-twin');
        $expected['alpha']['in']++;

        $this->at(self::NOW);
        $this->aggregate();
        self::assertSame(self::M0, $this->through(), 'M is at the current minute.');

        [$rows, $statements] = $this->workloadWithStatements();

        foreach (self::QUEUES as $queue) {
            $row = $rows[$queue];
            self::assertEqualsWithDelta($expected[$queue]['in'] / 5, $row['in_per_min'], 1e-9, "{$queue}: in");
            self::assertEqualsWithDelta($expected[$queue]['out'] / 5, $row['out_per_min'], 1e-9, "{$queue}: out");
            self::assertSame([self::M0 - 300, self::M0], [$row['rates_from'], $row['rates_to']], "{$queue}: the window");
        }

        $scans = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_jobs')));
        self::assertCount(1, $scans, 'Only the running read reads orrery_jobs: '.json_encode($scans));
        self::assertStringContainsString('reserved_at', $scans[0]);
        $samples = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_samples')));
        self::assertCount(2, $samples, 'One stored read for every row, and the runtime and names read of the measurement (no read per row): '.json_encode($samples));
    }

    /**
     * Rejects: a reader that answers null (or stale counts) while M is one minute behind, a scan
     * of more than the minutes from M on, and a scan that misses a row added after the count.
     * Still passes: three scans of that minute instead of one grouped statement per kind (the
     * statement count pins three).
     */
    public function test_with_m_one_minute_behind_the_last_minute_is_scanned(): void
    {
        $this->row('alpha', 'completed', ['pushed_at' => self::M0 - 250.0, 'finished_at' => self::M0 - 245.0]);
        $this->at(self::NOW - 60);
        $this->aggregate();
        self::assertSame(self::M0 - 60, $this->through());

        // After the count: rows of the minute that M has not passed.
        $this->row('alpha', 'completed', ['pushed_at' => self::M0 - 50.0, 'finished_at' => self::M0 - 40.0]);
        $this->row('alpha', 'pending', ['pushed_at' => self::M0 - 30.0]);
        $this->row('alpha', 'pending', ['pushed_at' => self::M0 - 25.0]);
        $this->row('alpha', 'failed', ['pushed_at' => self::M0 - 900.0, 'failed_at' => self::M0 - 20.0, 'finished_at' => self::M0 - 20.0]);
        $this->at(self::NOW);

        [$rows, $statements] = $this->workloadWithStatements();

        self::assertEqualsWithDelta(4 / 5, $rows['alpha']['in_per_min'], 1e-9, 'the stored arrival and the three scanned ones');
        self::assertEqualsWithDelta(3 / 5, $rows['alpha']['out_per_min'], 1e-9);
        $scans = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_jobs') && ! str_contains($sql, 'reserved_at')));
        self::assertCount(3, $scans, 'One scan per kind (completions, failures, arrivals), of the minute from M on: '.json_encode($scans));
    }

    /**
     * Rejects: a rate of 0 (or of the known minutes only) when a minute of the window is uncovered
     * or invalid, and a rate without M from the current minute's scan.
     */
    public function test_an_uncovered_or_invalid_minute_gives_null_rates(): void
    {
        $this->row('alpha', 'completed', ['pushed_at' => self::M0 - 250.0, 'finished_at' => self::M0 - 245.0]);
        $this->at(self::NOW);

        // Without M nothing before the current minute is known.
        $rows = $this->workload();
        self::assertSame([null, null], [$rows['alpha']['in_per_min'], $rows['alpha']['out_per_min']], 'without M');

        $this->aggregate();
        self::assertEqualsWithDelta(1 / 5, $this->workload()['alpha']['in_per_min'], 1e-9);

        // An invalid minute inside the window: unknown, never an undercount.
        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)
            ->update(['value' => json_encode(['version' => 9, 'intervals' => [[self::M0 - 180, self::M0 - 120]]])]);
        $rows = $this->workload();
        self::assertSame([null, null], [$rows['alpha']['in_per_min'], $rows['alpha']['out_per_min']], 'an invalid minute');

        // A minute without a total row before M (uncovered): unknown.
        $this->mirror()->table('orrery_settings')->where('key', SampleCounts::INVALID)->update(['value' => json_encode(['version' => 10, 'intervals' => []])]);
        $this->mirror()->table(SampleCounts::TABLE)->where('scope', 'total')->where('minute', self::M0 - 120)->delete();
        $rows = $this->workload();
        self::assertSame([null, null], [$rows['alpha']['in_per_min'], $rows['alpha']['out_per_min']], 'an uncovered minute');
    }

    /**
     * Rejects: arrivals summed from the queue rows of a minute whose `total` row has no arrivals
     * (a minute counted before the schema step 7, S-12): it must read as unknown, never 0, while
     * the outcomes of that minute stay known.
     */
    public function test_a_minute_counted_before_the_upgrade_gives_a_null_in_rate(): void
    {
        $this->row('alpha', 'completed', ['pushed_at' => self::M0 - 250.0, 'finished_at' => self::M0 - 245.0]);
        $this->at(self::NOW);
        $this->aggregate();
        // The minute was counted by the release before step 7: its arrivals are null in every row.
        $this->mirror()->table(SampleCounts::TABLE)->where('minute', self::M0 - 240)->update(['pushed' => null]);

        $row = $this->workload()['alpha'];

        self::assertNull($row['in_per_min'], 'the arrivals of a pre-upgrade minute are unknown');
        self::assertEqualsWithDelta(1 / 5, $row['out_per_min'], 1e-9, 'its outcomes stay known');
    }

    /**
     * Rejects: a null arrival count for a queue row whose `pushed` is null in a minute
     * whose `total` row has a number: the queue-state row of the samples phase, and a queue with
     * completions but no arrivals. Both read 0 arrivals.
     */
    public function test_a_queue_state_row_and_a_queue_without_arrivals_read_zero_arrivals(): void
    {
        // beta: completions in the window, its arrivals long before.
        $this->row('beta', 'completed', ['pushed_at' => self::M0 - 3000.0, 'finished_at' => self::M0 - 200.0]);
        $this->row('alpha', 'pending', ['pushed_at' => self::M0 - 200.0]);
        $this->at(self::NOW);
        $this->aggregate();
        // quiet: only the queue state of the samples phase (pushed null), in two minutes of the window.
        foreach ([self::M0 - 240, self::M0 - 120] as $minute) {
            $this->mirror()->table(SampleCounts::TABLE)->insert([
                'scope' => 'queue', 'series' => SampleCounts::series('queue', 'database', 'quiet'), 'connection' => 'database', 'queue' => 'quiet',
                'minute' => $minute, 'ready' => 1, 'workers' => 0, 'oldest_age' => '1.000000', 'over_limit' => false,
            ]);
        }

        self::assertNull($this->mirror()->table(SampleCounts::TABLE)->where('series', SampleCounts::series('queue', 'database', 'quiet'))->value('pushed'));
        // beta's counted row: no arrival group, so its arrivals may be stored as 0 or null; both read 0.
        $this->mirror()->table(SampleCounts::TABLE)->where('series', SampleCounts::series('queue', 'database', 'beta'))->update(['pushed' => null]);
        $rows = $this->workload();

        self::assertSame(0.0, $rows['quiet']['in_per_min']);
        self::assertSame(0.0, $rows['beta']['in_per_min']);
        self::assertEqualsWithDelta(1 / 5, $rows['beta']['out_per_min'], 1e-9);
        self::assertEqualsWithDelta(1 / 5, $rows['alpha']['in_per_min'], 1e-9);
    }

    /**
     * Rejects: an arrivals scan horizon from the completion keys only (D-10): a pushed row may since
     * have become `unknown` (or failed) and been trimmed under that shorter retention, so a minute
     * before now − the shortest of `completed`, `silenced`, `failed` and `unknown` must read as
     * unknown, never as a covered undercount.
     * Still passes: a horizon that also includes `monitored` (it only lengthens a retention).
     */
    public function test_the_arrivals_scan_horizon_is_the_shortest_trimmed_state(): void
    {
        $this->row('alpha', 'pending', ['pushed_at' => self::M0 - 200.0]);
        $this->at(self::NOW - 240);
        $this->aggregate();
        self::assertSame(self::M0 - 240, $this->through(), 'M is four minutes behind: the last four minutes are scanned.');
        $this->at(self::NOW);
        self::assertEqualsWithDelta(1 / 5, $this->workload()['alpha']['in_per_min'], 1e-9, 'every scanned minute is retained');

        // Unknown rows are kept 3 minutes: the scanned minutes before now − 180 s may have lost rows.
        $this->app['config']->set('orrery.trim.unknown', 3);
        $row = $this->workload()['alpha'];

        self::assertNull($row['in_per_min'], 'an arrival minute before the shortest horizon is unknown');
        self::assertNull($row['out_per_min'], 'so is its failure count');
    }

    /**
     * Rejects: a rates read that fails on a mirror before schema step 7 (no `pushed`
     * column) and so nulls the out rate too, or reports a raw query error on every poll. The
     * arrivals of the stored minutes are unknown, the outcomes stay known, SchemaOutdated is
     * reported once, and the column probe is cached (no schema read on every poll).
     */
    #[FreshSchema]
    public function test_a_schema_before_step_7_gives_unknown_arrivals_and_known_outcomes(): void
    {
        Exceptions::fake();
        $this->row('alpha', 'completed', ['pushed_at' => self::M0 - 250.0, 'finished_at' => self::M0 - 245.0]);
        $this->at(self::NOW);
        $this->aggregate();
        $this->mirrorSchema()->table(SampleCounts::TABLE, static function (Blueprint $table): void {
            $table->dropColumn('pushed');
        });

        $counts = [];

        foreach ([1, 2] as $poll) {
            [$rows, $statements] = $this->workloadWithStatements();
            $counts[] = count($statements);
            self::assertNull($rows['alpha']['in_per_min'], "poll {$poll}: the arrivals are unknown");
            self::assertEqualsWithDelta(1 / 5, $rows['alpha']['out_per_min'], 1e-9, "poll {$poll}: the outcomes stay known");
        }

        self::assertGreaterThan($counts[1], $counts[0], 'The column probe of the first poll is cached for the second: '.json_encode($counts));
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->causeClass === SchemaOutdated::class && str_contains($error->getMessage(), 'schema_outdated'));
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
     * The workload and the package statements on the mirror while it was read.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<string>}
     */
    private function workloadWithStatements(): array
    {
        $statements = [];
        PackageStatements::listen($this->app, static function (string $kind, string $sql) use (&$statements): void {
            if ($kind === PackageSession::STATEMENT) {
                $statements[] = $sql;
            }
        });

        try {
            return [$this->workload(), $statements];
        } finally {
            PackageStatements::reset();
        }
    }

    /**
     * @param  array<string, float>  $values
     */
    private function row(string $queue, string $status, array $values, string $connection = 'database'): void
    {
        $row = [
            'uuid' => strtolower((string) Str::uuid()),
            'connection' => $connection,
            'queue' => $queue,
            'name' => 'App\\Jobs\\Rate',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'updated_at' => Clock::toDecimal(self::M0 - 1000.0),
        ];

        foreach ($values as $column => $value) {
            $row[$column] = Clock::toDecimal($value);
        }

        $this->mirror()->table('orrery_jobs')->insert($row);
    }

    private function at(float $time): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($time, 'UTC'));
    }

    private function aggregate(): void
    {
        $this->app->make(SampleCounts::class)->run();
    }

    private function through(): ?int
    {
        $value = $this->mirror()->table('orrery_settings')->where('key', SampleCounts::COUNTED_THROUGH)->value('value');

        return $value === null ? null : (int) $value;
    }
}
