<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Queue\Connectors\DatabaseConnector as LaravelDatabaseConnector;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Listeners\RecordWorkerHeartbeat;
use Justrau\Orrery\Runtime\WorkerEvidence;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Workers\Heartbeats;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The `state` of a listed worker. A row is quiet when its last heartbeat
 * is older than its own quiet window and it holds no live lease:
 * 2 × max(the writer's heartbeat interval, `sleep`, `rest`) + 5 s. A row
 * of an uninstrumented connection is `unknown` instead of quiet. The
 * listing window is at least 25 s longer than the quiet window. The clock
 * is frozen on a whole second, so the boundaries are exact.
 */
final class WorkerQuietStateTest extends ControllerTest
{
    use WorkerFixtures;

    private const NOW = 1_800_000_000.0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        // A second database queue connection with its own jobs table, and one that a test makes foreign.
        // Both name the harness's queue connection: without it they follow database.default, which
        // `migrate --database=testbench_mirror` makes the mirror while the package migration decides.
        $app['config']->set('queue.connections.second', ['driver' => 'database', 'connection' => TestEnvironment::PRIMARY, 'table' => 'jobs_second', 'queue' => 'default', 'retry_after' => 90]);
        $app['config']->set('queue.connections.foreign', ['driver' => 'database', 'connection' => TestEnvironment::PRIMARY, 'table' => 'jobs_foreign', 'queue' => 'default', 'retry_after' => 90]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: a worker called quiet from any age, or a row without a
     * state. It would still pass a window of any length above 5 s.
     */
    public function test_a_fresh_heartbeat_is_live(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 5])]);

        self::assertSame(['live'], $this->states());
    }

    /**
     * Rejects: no quiet state at all (every listed worker live), and the
     * 60-second listing window as the quiet window.
     */
    public function test_a_heartbeat_older_than_the_quiet_window_without_a_lease_is_quiet(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40])]);

        self::assertSame(['quiet'], $this->states());
    }

    /**
     * Rejects: a rule that ignores the live lease of a worker that runs a
     * long job. It would still pass a rule that counts an expired lease.
     */
    public function test_an_old_heartbeat_with_a_live_lease_is_live(): void
    {
        $row = $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40]);
        $this->insertHeartbeats([$row]);
        $this->reservedJob($row['worker_id'], self::NOW - 10);

        self::assertSame(['live'], $this->states());
    }

    /**
     * Rejects: `>=` at the boundary, a window without the margin (two
     * intervals, 30 s), and a longer margin. Default: 2 × 15 + 5 = 35 s.
     */
    public function test_the_quiet_window_is_two_write_periods_and_the_margin(): void
    {
        self::assertSame(35.0, LiveWorkers::quietSeconds());

        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 35])]);
        self::assertSame(['live'], $this->states());

        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999964.999999']);
        self::assertSame(['quiet'], $this->states());
    }

    /**
     * Rejects: a fixed window instead of one from `workers.heartbeat_seconds`
     * (rows without the writer's interval take the configured one).
     */
    public function test_the_quiet_window_follows_a_non_default_heartbeat_interval(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 5);
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 16])]);
        self::assertSame(['quiet'], $this->states(), '2 × 5 + 5 is 15 s');

        $this->app['config']->set('orrery.workers.heartbeat_seconds', 25);
        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999946.000000']);
        self::assertSame(['live'], $this->states(), '2 × 25 + 5 is 55 s');
    }

    /**
     * Rejects: a window from the interval alone. An idle daemon writes once
     * per loop, so `--sleep` (and `--rest`) longer than the interval
     * stretch its write period. It would still pass a rule that takes only
     * the larger of the interval and `sleep`, for the sleep case.
     */
    public function test_each_row_is_judged_by_its_own_sleep_and_rest(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 1);
        $this->insertHeartbeats([
            // 2 × max(1, 3) + 5 = 11 s.
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 10, 'options' => json_encode(['sleep' => 3, 'rest' => 0])]),
            $this->heartbeatRow('web-1', 2, ['last_seen_at' => self::NOW - 12, 'options' => json_encode(['sleep' => 3, 'rest' => 0])]),
            // 2 × max(1, 3, 20) + 5 = 45 s.
            $this->heartbeatRow('web-1', 3, ['last_seen_at' => self::NOW - 44, 'options' => json_encode(['sleep' => 3, 'rest' => 20])]),
        ]);

        self::assertSame(['live', 'quiet', 'live'], $this->states());
        self::assertSame([11.0, 11.0, 45.0], array_map(static fn (object $row): float => $row->quiet_seconds, app(LiveWorkers::class)->list()));
    }

    /**
     * Rejects: a sleep longer than the listing window that drops the idle
     * worker from the list (the old 60-second window), or shows it quiet.
     * The default interval and --sleep=35: 2 × 35 + 5 = 75 s quiet, 100 s
     * listed.
     */
    public function test_a_long_sleep_keeps_an_idle_worker_listed_and_live(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 70, 'options' => json_encode(['sleep' => 35])])]);

        self::assertSame(['live'], $this->states());

        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999901.000000']);
        self::assertSame(['quiet'], $this->states(), 'listed and quiet at 99 s');

        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999899.000000']);
        self::assertSame([], $this->states(), 'not listed after 100 s');
    }

    /**
     * Rejects: a queue:listen child judged without its --sleep (Laravel
     * passes the parent's --sleep to each child, and a --once child raises
     * no WorkerStarting), and without the parent's --rest. With --sleep=60:
     * 2 × 60 + 5 = 125 s.
     */
    public function test_a_listen_child_writes_its_sleep_and_the_parent_rest(): void
    {
        $this->actAsWorker(ppid: 4000, parentStartedAt: self::NOW - 100, parentCommand: 'php artisan queue:listen database --rest=30 --sleep=60',
            evidence: new WorkerEvidence(1, 60, 'database', 'default', true, 60.0, 0.0));
        RecordWorkerHeartbeat::write('database');

        $options = json_decode((string) $this->mirror()->table('orrery_worker_heartbeats')->value('options'), true);
        self::assertSame(60, (int) $options['sleep']);
        self::assertSame(30, (int) $options['rest']);

        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999880.000000']);
        $rows = app(LiveWorkers::class)->list();
        self::assertSame(125.0, $rows[0]->quiet_seconds);
        self::assertSame('live', $rows[0]->state, 'a listen child that sleeps 60 s is quiet after 120 s');
    }

    /**
     * Rejects: the web process's interval over the writer's own. The
     * writer stores its interval in the row's options when it writes.
     */
    public function test_the_writer_interval_in_the_row_wins_over_the_configured_one(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 5);
        $this->insertHeartbeats([
            // The writer writes every 30 s: 2 × 30 + 5 = 65 s.
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40, 'options' => json_encode([Heartbeats::INTERVAL_OPTION => 30])]),
        ]);
        self::assertSame(['live'], $this->states());

        // The writer writes every 2 s, the web process says 30: 2 × 2 + 5 = 9 s.
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 30);
        $this->mirror()->table('orrery_worker_heartbeats')->update([
            'options' => json_encode([Heartbeats::INTERVAL_OPTION => 2]),
            'last_seen_at' => '1799999990.000000',
        ]);
        self::assertSame(['quiet'], $this->states());
    }

    /**
     * Rejects: an interval of 0 (a write on every loop) read as a 0 s write
     * period, which makes every worker quiet after 5 s, and a 0 that
     * ignores the default interval. 0 takes the default interval (15 s).
     */
    public function test_an_interval_of_zero_takes_the_default_interval(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 0);
        self::assertSame(35.0, LiveWorkers::quietSeconds());
        self::assertSame(60, LiveWorkers::freshSeconds());

        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 34]),
            $this->heartbeatRow('web-1', 2, ['last_seen_at' => self::NOW - 36]),
            $this->heartbeatRow('web-1', 3, ['last_seen_at' => self::NOW - 34, 'options' => json_encode([Heartbeats::INTERVAL_OPTION => 0, 'sleep' => 3])]),
        ]);

        self::assertSame(['live', 'quiet', 'live'], $this->states());
    }

    /**
     * Rejects: an option read as a number when it is not one (a negative,
     * a word, an infinite or a boolean), so a broken row shrinks or
     * breaks its window, and a numeric string that is ignored. Each bad
     * value falls back: the configured interval, no sleep, no rest.
     */
    public function test_the_window_reads_only_finite_numbers_of_at_least_zero(): void
    {
        $interval = Heartbeats::INTERVAL_OPTION;

        self::assertSame(125.0, LiveWorkers::quietSeconds(['sleep' => '60']));
        self::assertSame(65.0, LiveWorkers::quietSeconds([$interval => '30']));
        self::assertSame(35.0, LiveWorkers::quietSeconds([$interval => -30, 'sleep' => -60, 'rest' => '-5']));
        self::assertSame(35.0, LiveWorkers::quietSeconds([$interval => 'fast', 'sleep' => 'long', 'rest' => true]));
        self::assertSame(35.0, LiveWorkers::quietSeconds(['sleep' => INF, 'rest' => NAN]));
        self::assertSame(35.0, LiveWorkers::quietSeconds(['sleep' => '1e999']));
        self::assertSame(35.0, LiveWorkers::quietSeconds(['sleep' => null, 'rest' => []]));
    }

    /**
     * Rejects: a listing window equal to the quiet window (the old
     * max(60, 2 × interval)), so that with an interval of 30 s or more a
     * killed worker drops off without ever being quiet. With 60 s: 125 s
     * quiet, 150 s listed.
     */
    public function test_a_killed_worker_is_quiet_before_it_drops_off_with_a_long_interval(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 60);
        self::assertSame(150, LiveWorkers::freshSeconds());

        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 130])]);
        self::assertSame(['quiet'], $this->states());

        foreach ([0, 1, 5, 15, 30, 60, 300] as $interval) {
            foreach ([0, 3, 35, 600] as $sleep) {
                $quiet = LiveWorkers::quietSeconds([Heartbeats::INTERVAL_OPTION => $interval, 'sleep' => $sleep]);
                self::assertGreaterThanOrEqual($quiet + 25, LiveWorkers::listedSeconds($quiet), "interval {$interval}, sleep {$sleep}");
            }
        }
    }

    /**
     * Rejects: a parent that takes its state from its oldest child, and a
     * finished child of queue:listen that stays listed as a quiet child.
     * queue:listen runs one child at a time, so an older child without a
     * lease has exited. It would still pass a rule that hides an older
     * child with a live lease too (the next test rejects it).
     */
    public function test_a_listen_parent_with_a_fresh_newest_child_is_live_and_shows_no_finished_child(): void
    {
        $parentStart = self::NOW - 900;
        $older = $this->heartbeatRow('web-1', 21, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 60, 'last_seen_at' => self::NOW - 50]);
        $newest = $this->heartbeatRow('web-1', 22, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 3, 'last_seen_at' => self::NOW - 2]);
        $this->insertHeartbeats([$older, $newest]);

        $rows = $this->getJson('/orrery/api/workers')->assertOk()->json();
        self::assertCount(1, $rows);
        self::assertSame('live', $rows[0]['state']);
        self::assertSame([$newest['worker_id']], array_column($rows[0]['children'], 'worker_id'));
        self::assertSame(['live'], array_column($rows[0]['children'], 'state'));
    }

    /**
     * Rejects: a parent that takes its state from its newest child only, or
     * a hidden older child that holds a live lease, and an API answer
     * without the state on the row and its children.
     */
    public function test_a_listen_parent_is_live_while_a_child_holds_a_lease_and_the_api_sends_the_state(): void
    {
        $parentStart = self::NOW - 900;
        $leased = $this->heartbeatRow('web-1', 21, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 70, 'last_seen_at' => self::NOW - 55]);
        $quiet = $this->heartbeatRow('web-1', 22, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 52, 'last_seen_at' => self::NOW - 50]);
        $this->insertHeartbeats([$leased, $quiet]);
        $this->reservedJob($leased['worker_id'], self::NOW - 10);

        $rows = $this->getJson('/orrery/api/workers')->assertOk()->json();
        self::assertCount(1, $rows);
        self::assertSame('live', $rows[0]['state']);
        self::assertSame(['quiet', 'live'], array_column($rows[0]['children'], 'state'));
        self::assertEquals(35, $rows[0]['quiet_seconds']);

        $this->mirror()->table('orrery_jobs')->delete();
        $rows = $this->getJson('/orrery/api/workers')->assertOk()->json();
        self::assertSame('quiet', $rows[0]['state']);
        self::assertSame([$quiet['worker_id']], array_column($rows[0]['children'], 'worker_id'), 'the older child without a lease has exited');
    }

    /**
     * Rejects: a definite quiet for a worker of an uninstrumented
     * connection, where a running job holds no lease that the package
     * can see (busy and dead look the same), and for a connection that the
     * web process does not know. It would still pass a rule that calls
     * every old row unknown; the instrumented row rejects that.
     */
    public function test_an_old_heartbeat_on_an_uninstrumented_connection_is_unknown(): void
    {
        $this->app['queue']->connection('database');
        $this->app['queue']->addConnector('database', fn (): LaravelDatabaseConnector => new LaravelDatabaseConnector($this->app['db']));

        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40]),
            $this->heartbeatRow('web-1', 2, ['last_seen_at' => self::NOW - 40, 'connection' => 'foreign']),
            $this->heartbeatRow('web-1', 3, ['last_seen_at' => self::NOW - 2, 'connection' => 'foreign']),
            $this->heartbeatRow('web-1', 4, ['last_seen_at' => self::NOW - 40, 'connection' => 'not-configured']),
        ]);

        self::assertSame(['quiet', 'unknown', 'live', 'unknown'], $this->states());
    }

    /**
     * Rejects: a workload row without `quiet_processes` or
     * `unknown_state_processes`, counts that take a live worker as quiet,
     * and counts without the connection or queue filter (the quiet workers
     * of another queue and of another connection).
     */
    public function test_the_workload_counts_the_quiet_and_unknown_workers_of_a_queue(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60, 'database:default' => 60]);
        $this->app['queue']->connection('database');
        $this->app['queue']->connection('second');
        $this->app['queue']->addConnector('database', fn (): LaravelDatabaseConnector => new LaravelDatabaseConnector($this->app['db']));

        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40]),
            $this->heartbeatRow('web-1', 2, ['last_seen_at' => self::NOW - 2]),
            $this->heartbeatRow('web-1', 3, ['last_seen_at' => self::NOW - 40, 'subscribed_queues' => 'other']),
            $this->heartbeatRow('web-1', 4, ['last_seen_at' => self::NOW - 40, 'connection' => 'second']),
            $this->heartbeatRow('web-1', 5, ['last_seen_at' => self::NOW - 40, 'connection' => 'foreign']),
        ]);

        $rows = collect($this->getJson('/orrery/api/workload')->assertOk()->json())->keyBy(static fn (array $row): string => $row['connection'].':'.$row['name']);
        self::assertSame(2, $rows['database:default']['processes'], json_encode($rows));
        self::assertSame(1, $rows['database:default']['quiet_processes']);
        self::assertSame(0, $rows['database:default']['unknown_state_processes']);
    }

    /**
     * Rejects: a workload that counts a worker of unknown state as quiet
     * (the SPA would say "Workers quiet").
     */
    public function test_the_workload_counts_a_worker_of_an_uninstrumented_connection_as_unknown(): void
    {
        $this->app['config']->set('orrery.waits', ['*' => 60, 'foreign:default' => 60]);
        $this->app['queue']->connection('database');
        $this->app['queue']->addConnector('database', fn (): LaravelDatabaseConnector => new LaravelDatabaseConnector($this->app['db']));

        $this->insertHeartbeats([$this->heartbeatRow('web-1', 5, ['last_seen_at' => self::NOW - 40, 'connection' => 'foreign'])]);

        $row = collect($this->getJson('/orrery/api/workload')->assertOk()->json())->firstWhere('name', 'default');
        self::assertNotNull($row);
        self::assertSame(1, $row['processes'], json_encode($row));
        self::assertSame(0, $row['quiet_processes']);
        self::assertSame(1, $row['unknown_state_processes']);
    }

    /** @return list<string> */
    private function states(): array
    {
        return array_map(static fn (object $row): string => $row->state, app(LiveWorkers::class)->list());
    }
}
