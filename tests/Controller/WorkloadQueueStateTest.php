<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Control\SignalState;
use Justrau\Orrery\Database\Models\Signal;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The queue state fields of GET /api/workload: oldest_age,
 * threshold, over_limit, running, in_per_min, out_per_min, paused,
 * over_limit_since and over_limit_since_censored. The rates come from the
 * per-minute samples; WorkloadRatesTest covers their read rule.
 */
final class WorkloadQueueStateTest extends ControllerTest
{
    /** A minute boundary. */
    private const M = 1_790_000_040;

    /** The request time: 12.5 s into the minute M. */
    private const NOW = self::M + 12.5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // A second queue connection name on the same jobs table: one workload row with two aliases.
        $app['config']->set('queue.connections.database-twin', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('orrery.waits', [
            '*' => 60,
            'database:nolimit' => 0,
            'database-twin:nolimit' => 0,
            'database:rates' => 60,
            'database:idle' => 60,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Known limit: it would pass an over_limit that also counts the estimated clearance time, because no row has a runtime counter.
     */
    public function test_oldest_age_threshold_and_over_limit_per_row(): void
    {
        $this->readyJob('late', self::M - 100);
        $this->readyJob('fresh', self::M);
        $this->readyJob('nolimit', self::M - 500);

        $rows = $this->workload();

        self::assertSame([112.5, 60, true], $this->fields($rows['late'], ['oldest_age', 'threshold', 'over_limit']));
        self::assertSame([12.5, 60, false], $this->fields($rows['fresh'], ['oldest_age', 'threshold', 'over_limit']));
        self::assertSame([512.5, 0, false], $this->fields($rows['nolimit'], ['oldest_age', 'threshold', 'over_limit']), 'threshold 0 disables the limit');
        self::assertSame([null, 60, false], $this->fields($rows['idle'], ['oldest_age', 'threshold', 'over_limit']), 'no backlog: no age');

        // The existing fields keep their names and shapes.
        self::assertSame(
            ['name' => 'late', 'connection' => 'database', 'connections' => ['database', 'database-twin'], 'length' => 1, 'wait' => null, 'processes' => 0, 'split_queues' => null],
            array_intersect_key($rows['late'], array_flip(['name', 'connection', 'connections', 'length', 'wait', 'processes', 'split_queues'])),
        );
    }

    /**
     * Rejects: a workload that answers 500 for a queue name with invalid UTF-8 (a review nit; only SQLite stores
     * one): the JSON encoder refused the whole response. The name is sent with U+FFFD in place of the bad byte.
     * Still passes: an encoder that drops the bad byte instead (the replacement character is not asserted).
     */
    public function test_a_queue_name_with_invalid_utf8_is_answered(): void
    {
        if ($this->app['db']->connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('Only SQLite stores a queue name with invalid UTF-8.');
        }

        $this->readyJob("bad\xff", self::M - 10);

        $this->getJson('/orrery/api/workload')->assertOk();
    }

    /**
     * Rejects: rates over [now - 300, now) instead of the five complete minutes before the current
     * minute, an in rate of the old first push of a retried row, an out rate without the silenced
     * completions or without the failure of a row that waits again (the samples count every row
     * by failed_at), and running rows without a live lease.
     * Known limit: it would pass an in-rate that also counts a row pushed at exactly M - 300 minus a microsecond (not tested).
     */
    public function test_running_counts_live_leases_and_the_rates_come_from_the_samples(): void
    {
        // In: pushed_at in the five complete minutes [M - 300, M). The first push of the row only.
        $this->mirrorJob('rates', 'pending', ['pushed_at' => self::M - 10]);
        $this->mirrorJob('rates', 'pending', ['pushed_at' => self::M - 300]);
        $this->mirrorJob('rates', 'pending', ['pushed_at' => self::M + 1], 'the current minute is outside');
        $this->mirrorJob('rates', 'pending', ['pushed_at' => self::M - 301]);
        // Out: completed by finished_at (silenced included), every row by failed_at.
        $this->mirrorJob('rates', 'completed', ['pushed_at' => self::M - 900, 'finished_at' => self::M - 1, 'silenced' => true]);
        $this->mirrorJob('rates', 'completed', ['pushed_at' => self::M - 900, 'finished_at' => self::M - 301]);
        $this->mirrorJob('rates', 'failed', ['pushed_at' => self::M - 900, 'failed_at' => self::M - 5, 'finished_at' => self::M - 5]);
        $this->mirrorJob('rates', 'failed', ['pushed_at' => self::M - 900, 'failed_at' => self::M + 2, 'finished_at' => self::M + 2]);
        // A row that waits again after a failure in the window: the failure counts.
        $this->mirrorJob('rates', 'pending', ['pushed_at' => self::M - 900, 'failed_at' => self::M - 40]);
        // Running: reserved rows with a live lease.
        $this->mirrorJob('rates', 'reserved', ['pushed_at' => self::M - 900, 'reserved_at' => self::NOW - 3]);
        $this->mirrorJob('rates', 'reserved', ['pushed_at' => self::M - 900, 'reserved_at' => self::NOW - 20]);
        // A finished row keeps its reserved_at: it is not running.
        $this->mirrorJob('rates', 'completed', ['pushed_at' => self::M - 900, 'reserved_at' => self::NOW - 800, 'finished_at' => self::NOW - 700]);
        // Other queues and connections are not counted.
        $this->mirrorJob('fresh', 'completed', ['pushed_at' => self::M - 10, 'finished_at' => self::M - 5]);
        $this->mirrorJob('rates', 'completed', ['pushed_at' => self::M - 10, 'finished_at' => self::M - 5], connection: 'other');
        $this->app->make(SampleCounts::class)->run();

        $row = $this->workload()['rates'];

        self::assertSame(2, $row['running']);
        self::assertEqualsWithDelta(2 / 5, $row['in_per_min'], 1e-9, 'two arrivals in five minutes');
        self::assertEqualsWithDelta(3 / 5, $row['out_per_min'], 1e-9, 'three outcomes in five minutes');
        self::assertSame([self::M - 300, self::M], [$row['rates_from'], $row['rates_to']]);
    }

    /**
     * Known limit: it would pass a lease of any length between 81 and 94 seconds; the retry_after of both aliases is 90.
     */
    public function test_running_counts_only_reservations_with_a_live_lease(): void
    {
        $this->readyJob('shared', self::M);
        // Live: reserved_at later than now - retry_after (90 s), also when a 60 s default would expire it.
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::NOW - 900, 'reserved_at' => self::NOW - 80]);
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::NOW - 900, 'reserved_at' => self::NOW - 5], connection: 'database-twin');
        // Expired: the reservations of a dead worker, and the boundary.
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::NOW - 900, 'reserved_at' => self::NOW - 90]);
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::NOW - 900, 'reserved_at' => self::NOW - 95]);
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::NOW - 900, 'reserved_at' => self::NOW - 4000], connection: 'database-twin');

        self::assertSame(2, $this->workload()['shared']['running']);
    }

    /**
     * Known limit: it would pass a controller that sets paused_unknown also when only a later read fails (no later read is made to fail).
     */
    public function test_a_failing_signal_read_gives_paused_unknown_and_no_pause(): void
    {
        Exceptions::fake();
        $this->readyJob('late', self::M - 100);
        app(SignalState::class)->pause('global', '');

        self::assertSame([['global'], false], [array_column($this->workload()['late']['pauses'], 'scope'), $this->workload()['late']['paused_unknown']]);

        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql): void {
            if (str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'orrery_signals')) {
                throw new RuntimeException('The signals cannot be read.');
            }
        });

        foreach ($this->workload() as $name => $row) {
            self::assertSame([[], true], $this->fields($row, ['pauses', 'paused_unknown']), (string) $name);
        }

        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === 'workload:signals' && $error->causeClass === RuntimeException::class && str_contains($error->getMessage(), 'The signals cannot be read.'));
    }

    /**
     * Known limit: it would pass a sum that counts only the first two aliases of a row with three.
     */
    public function test_running_and_the_rates_count_the_rows_of_every_alias(): void
    {
        $this->readyJob('shared', self::M);
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::M - 10, 'reserved_at' => self::NOW - 2]);
        $this->mirrorJob('shared', 'reserved', ['pushed_at' => self::M - 20, 'reserved_at' => self::NOW - 3], connection: 'database-twin');
        $this->mirrorJob('shared', 'completed', ['pushed_at' => self::M - 30, 'finished_at' => self::M - 4], connection: 'database-twin');
        $this->app->make(SampleCounts::class)->run();

        $row = $this->workload()['shared'];

        self::assertSame(['database', 'database-twin'], $row['connections']);
        self::assertSame(2, $row['running']);
        self::assertEqualsWithDelta(3 / 5, $row['in_per_min'], 1e-9);
        self::assertEqualsWithDelta(1 / 5, $row['out_per_min'], 1e-9);
    }

    /**
     * Known limit: it would pass a rule that orders the causes of one row in another fixed order than global, connection, queue (every case lists them in that order).
     *
     * @param  callable(SignalState): void  $arrange
     * @param  list<array{0: string, 1: string, 2: ?string}>  $late  [scope, source, issued_by] of each cause
     * @param  list<array{0: string, 1: string, 2: ?string}>  $fresh
     */
    #[DataProvider('pauseStates')]
    public function test_pauses_are_every_cause_of_the_or_rule(callable $arrange, array $late, array $fresh): void
    {
        $this->readyJob('late', self::M - 100);
        $this->readyJob('fresh', self::M);
        $arrange(app(SignalState::class));

        $rows = $this->workload();

        foreach (['late' => $late, 'fresh' => $fresh] as $queue => $expected) {
            $pauses = $rows[$queue]['pauses'];

            self::assertFalse($rows[$queue]['paused_unknown'], $queue);
            self::assertSame($expected, array_map(static fn (array $cause): array => [$cause['scope'], $cause['source'], $cause['issued_by']], $pauses), $queue);

            foreach ($pauses as $cause) {
                self::assertSame(['scope', 'source', 'issued_at', 'issued_by', 'possible'], array_keys($cause), $queue);
                self::assertFalse($cause['possible'], $queue);
                self::assertEqualsWithDelta(self::NOW, $cause['issued_at'], 0.001, $queue.': epoch seconds');
            }
        }
    }

    /**
     * @return array<string, array{0: callable(SignalState): void, 1: list<array{0: string, 1: string, 2: ?string}>, 2: list<array{0: string, 1: string, 2: ?string}>}>
     */
    public static function pauseStates(): array
    {
        return [
            'no signal' => [static function (): void {}, [], []],
            'a queue pause' => [
                static fn (SignalState $s) => $s->pause('queue', 'database:late', 'ops@example.com'),
                [['queue', 'orrery', 'ops@example.com']],
                [],
            ],
            'a connection pause' => [
                static fn (SignalState $s) => $s->pause('connection', 'database', 'cli'),
                [['connection', 'orrery', 'cli']],
                [['connection', 'orrery', 'cli']],
            ],
            'a pause of another connection' => [
                static fn (SignalState $s) => $s->pause('connection', 'other'),
                [],
                [],
            ],
            'a global pause' => [
                static fn (SignalState $s) => $s->pause('global', ''),
                [['global', 'orrery', null]],
                [['global', 'orrery', null]],
            ],
            'a global pause with a queue continue' => [
                static function (SignalState $s): void {
                    $s->pause('global', '', 'a');
                    $s->continue('queue', 'database:late');
                },
                [['global', 'orrery', 'a']],
                [['global', 'orrery', 'a']],
            ],
            'a connection pause and a queue pause' => [
                static function (SignalState $s): void {
                    $s->pause('connection', 'database', 'a');
                    $s->pause('queue', 'database:late', 'b');
                },
                [['connection', 'orrery', 'a'], ['queue', 'orrery', 'b']],
                [['connection', 'orrery', 'a']],
            ],
            'every scope' => [
                static function (SignalState $s): void {
                    $s->pause('queue', 'database:late', 'c');
                    $s->pause('connection', 'database', 'b');
                    $s->pause('global', '', 'a');
                },
                [['global', 'orrery', 'a'], ['connection', 'orrery', 'b'], ['queue', 'orrery', 'c']],
                [['global', 'orrery', 'a'], ['connection', 'orrery', 'b']],
            ],
            'an annotation without Laravel\'s pause' => [
                static fn () => self::signal('pause', 'queue', 'database:late', self::NOW),
                [],
                [],
            ],
            'a continue row from before C7' => [
                static function (): void {
                    self::signal('pause', 'connection', 'database', self::NOW);
                    self::signal('continue', 'connection', 'database', self::NOW + 5);
                },
                [['connection', 'orrery', 'x']],
                [['connection', 'orrery', 'x']],
            ],
            'an expired pause' => [
                static function (): void {
                    Signal::query()->create([
                        'signal' => 'pause', 'scope' => 'connection', 'scope_value' => 'database',
                        'issued_at' => Clock::toDecimal(self::NOW), 'expires_at' => Carbon::now()->subSecond(), 'issued_by' => 'x',
                    ]);
                },
                [],
                [],
            ],
            'a pause that expires later' => [
                static function (): void {
                    Signal::query()->create([
                        'signal' => 'pause', 'scope' => 'connection', 'scope_value' => 'database',
                        'issued_at' => Clock::toDecimal(self::NOW), 'expires_at' => Carbon::now()->addMinute(), 'issued_by' => 'x',
                    ]);
                },
                [['connection', 'orrery', 'x']],
                [['connection', 'orrery', 'x']],
            ],
        ];
    }

    /**
     * A signal row written directly (an annotation without Laravel's flag, or a continue row that is no longer written).
     */
    private static function signal(string $signal, string $scope, string $scopeValue, float $issuedAt): void
    {
        Signal::query()->create([
            'signal' => $signal, 'scope' => $scope, 'scope_value' => $scopeValue,
            'issued_at' => Clock::toDecimal($issuedAt), 'expires_at' => null, 'issued_by' => 'x',
        ]);
    }

    /**
     * Known limit: it would pass a row that reports the pause of any alias when the primary alias also has one.
     */
    public function test_paused_of_an_aliased_row_is_the_state_of_the_primary_alias(): void
    {
        $this->readyJob('late', self::M - 100);
        $this->readyJob('fresh', self::M);
        app(SignalState::class)->pause('queue', 'database-twin:late');
        app(SignalState::class)->pause('queue', 'database:fresh');

        $rows = $this->workload();

        self::assertSame([], $rows['late']['pauses'], 'a pause of the second alias is not reported (the documented limit)');
        self::assertSame(['queue'], array_column($rows['fresh']['pauses'], 'scope'));
    }

    /**
     * Known limit: it would pass a reader call that uses the alias of the first live worker when that alias is also the primary one.
     */
    public function test_over_limit_since_comes_from_the_samples_of_the_primary_alias(): void
    {
        $this->readyJob('late', self::M - 100);
        $this->readyJob('fresh', self::M);
        // The sample phase carries the run's start from minute to minute (SamplesTest).
        $this->sample('late', self::M - 180, false);
        $this->sample('late', self::M - 120, true, self::M - 120);
        $this->sample('late', self::M - 60, true, self::M - 120);
        $this->sample('late', self::M, true, self::M - 120);
        $this->sample('fresh', self::M - 60, true, self::M - 60, true);
        $this->sample('fresh', self::M, true, self::M - 60, true);

        $rows = $this->workload();

        self::assertSame([true, self::M - 120, false], $this->fields($rows['late'], ['over_limit', 'over_limit_since', 'over_limit_since_censored']));
        self::assertSame(
            [false, null, false],
            $this->fields($rows['fresh'], ['over_limit', 'over_limit_since', 'over_limit_since_censored']),
            'the samples say over the limit, but the queue is within it now (oldest_age 12.5): no run',
        );
        self::assertSame([null, false], $this->fields($rows['nolimit'], ['over_limit_since', 'over_limit_since_censored']), 'no sample');
    }

    /**
     * Known limit: it would pass a controller that catches only RuntimeException.
     */
    public function test_a_failing_over_limit_since_read_is_reported_and_the_response_still_answers(): void
    {
        Exceptions::fake();
        $this->readyJob('late', self::M - 100);
        $this->sample('late', self::M, true, self::M);
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql): void {
            // Reads only: the teardown's rollback drops the table later.
            if (str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'orrery_samples') && str_contains($sql, 'over_limit_since')) {
                throw new RuntimeException('The samples cannot be read.');
            }
        });

        $rows = $this->workload();

        self::assertSame([true, null, false], $this->fields($rows['late'], ['over_limit', 'over_limit_since', 'over_limit_since_censored']));
        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === 'workload:over_limit_since' && $error->causeClass === RuntimeException::class && str_contains($error->getMessage(), 'The samples cannot be read.'));
    }

    /**
     * Known limit: it counts statements by table name, so it would pass an extra statement on another table.
     */
    public function test_one_worker_read_and_no_mirror_read_per_row_except_over_limit_since(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $queue) {
            $this->readyJob($queue, self::M);
        }

        // One queue over its limit: the only row that reads its samples.
        $this->readyJob('late', self::M - 100);

        $statements = [];
        $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements, $mirror): void {
            if ($query->connection === $mirror) {
                $statements[] = $query->sql;
            }
        });

        $rows = $this->workload();

        $count = static fn (string $table): int => count(array_filter($statements, static fn (string $sql): bool => str_contains($sql, $table)));
        self::assertGreaterThanOrEqual(7, count($rows));
        self::assertSame(1, $count('orrery_worker_heartbeats'), 'one read of the live workers');
        self::assertSame(1, $count('orrery_signals'), 'one read of the signals');
        self::assertSame(1, $count('orrery_jobs'), 'running: one statement for all rows; the rates read the samples (D8 §4.13)');
        self::assertSame(1, $count('over_limit_since'), 'one over_limit_since statement for all rows, because one row is over its limit');
        self::assertSame(2, $count('orrery_samples'), 'the over_limit_since statement and the one runtime and names read of the measurement');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workload(): array
    {
        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function fields(array $row, array $keys): array
    {
        return array_map(static fn (string $key): mixed => $row[$key], $keys);
    }

    private function readyJob(string $queue, int $availableAt): void
    {
        $this->app['db']->connection()->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ]);
    }

    /**
     * @param  array<string, float|bool>  $values
     */
    private function mirrorJob(string $queue, string $status, array $values, string $comment = '', string $connection = 'database'): void
    {
        $row = [
            'uuid' => (string) Str::uuid(),
            'connection' => $connection,
            'queue' => $queue,
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'updated_at' => Clock::toDecimal(self::NOW),
        ];

        foreach ($values as $column => $value) {
            $row[$column] = is_float($value) || is_int($value) ? Clock::toDecimal((float) $value) : $value;
        }

        $this->mirror()->table('orrery_jobs')->insert($row);
    }

    private function sample(string $queue, int $at, bool $overLimit, ?int $since = null, bool $censored = false): void
    {
        $this->mirror()->table('orrery_samples')->insert([
            'scope' => 'queue',
            'series' => SampleCounts::series('queue', 'database', $queue),
            'connection' => 'database',
            'queue' => $queue,
            'minute' => $at,
            'ready' => 1,
            'oldest_age' => '90.000000',
            'workers' => 0,
            'over_limit' => $overLimit,
            'over_limit_since' => $since,
            'over_limit_since_censored' => $censored,
        ]);
    }
}
