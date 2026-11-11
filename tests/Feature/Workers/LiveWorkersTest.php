<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Workers\LiveWorkers;
use Justrau\Orrery\Workers\Phases\HeartbeatSweepPhase;

/**
 * LiveWorkers: liveness evidence, capacity and the row
 * shape that the API reads.
 */
final class LiveWorkersTest extends ControllerTest
{
    use WorkerFixtures;

    /** The fields of every list() row. */
    private const FIELDS = [
        'worker_id', 'host', 'pid', 'ppid', 'started_at', 'mode', 'connection', 'subscribed_queues',
        'current_queue', 'options', 'last_seen_at', 'heartbeat_fresh', 'active_leases', 'quiet_seconds', 'state', 'children',
    ];

    /**
     * Rejects: liveness from the heartbeat alone.
     */
    public function test_worker_death_with_a_lease_shows_both_kinds_of_evidence(): void
    {
        $now = Clock::now();

        // The worker died inside a job 100 seconds ago: its heartbeat is stale,
        // and its reservation (retry_after 90 on the test connection) is younger.
        $dead = $this->heartbeatRow('web-1', 500, ['last_seen_at' => $now - 100]);
        $this->insertHeartbeats([$dead]);
        $this->reservedJob($dead['worker_id'], $now - 30);

        $rows = app(LiveWorkers::class)->list();
        self::assertCount(1, $rows, 'a worker with an active lease is missing');
        self::assertSame($dead['worker_id'], $rows[0]->worker_id);
        self::assertFalse($rows[0]->heartbeat_fresh);
        self::assertSame(1, $rows[0]->active_leases);
        self::assertSame(1, app(LiveWorkers::class)->capacity('database', 'default'));

        // An expired reservation is no lease: the worker is gone.
        $this->mirror()->table('orrery_jobs')->update(['reserved_at' => Clock::toDecimal($now - 91)]);
        self::assertSame([], app(LiveWorkers::class)->list());

        // A fresh heartbeat without a lease is the other kind of evidence.
        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => Clock::toDecimal($now - 1)]);
        $rows = app(LiveWorkers::class)->list();
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->heartbeat_fresh);
        self::assertSame(0, $rows[0]->active_leases);
    }

    /**
     * Rejects: a fixed 60-second fresh window with a longer heartbeat
     * interval, so an idle worker that writes once per 120 s is absent half
     * of the time, `processes` reads a known 0 and a healthy queue can raise
     * a long-wait alert. With 120 s: 2 × 120 + 5 quiet, 25 s more listed.
     * It would still pass a window of three intervals instead of two.
     */
    public function test_the_fresh_window_follows_a_long_heartbeat_interval(): void
    {
        $now = Clock::now();
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 501, ['last_seen_at' => $now - 100])]);

        self::assertSame([], app(LiveWorkers::class)->list(), 'the default interval keeps the 60-second window');

        $this->app['config']->set('orrery.workers.heartbeat_seconds', 120);
        self::assertSame(270, LiveWorkers::freshSeconds());
        $rows = app(LiveWorkers::class)->list();
        self::assertCount(1, $rows, 'an idle worker within two heartbeat intervals is absent');
        self::assertTrue($rows[0]->heartbeat_fresh);
    }

    /**
     * Rejects: a heartbeat sweep that deletes rows by the lease window alone (the larger of 300 s and the largest
     * `retry_after` + 60), so with a heartbeat interval of 600 an idle worker inside the fresh window loses its row,
     * `processes` reads 0 and a healthy queue raises a long-wait alert.
     * Still passes: a sweep that keeps rows for longer than the fresh window.
     */
    public function test_the_heartbeat_sweep_keeps_the_workers_inside_the_fresh_window(): void
    {
        $now = Clock::now();
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 600);
        $kept = $this->heartbeatRow('web-1', 502, ['last_seen_at' => $now - 400]);
        $this->insertHeartbeats([$kept, $this->heartbeatRow('web-2', 503, ['last_seen_at' => $now - 1300])]);

        (new HeartbeatSweepPhase)->run(new TickBudget(Clock::monotonic() + 60));

        self::assertSame([$kept['worker_id']], $this->mirror()->table('orrery_worker_heartbeats')->pluck('worker_id')->all(), 'the sweep deleted a fresh worker, or kept one older than the fresh window');
        self::assertCount(1, app(LiveWorkers::class)->list());
    }

    /**
     * Rejects: a lease that also needs the producer's connection name of the mirror row to equal the consuming worker's connection.
     */
    public function test_a_lease_counts_when_producer_and_consumer_name_one_table_differently(): void
    {
        $now = Clock::now();

        foreach (['producer' => 30, 'consumer' => 90] as $name => $retryAfter) {
            $this->app['config']->set("queue.connections.{$name}", ['driver' => 'database', 'connection' => 'testbench', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => $retryAfter]);
        }

        // The worker consumes through `consumer`; the job was pushed through `producer`.
        $worker = $this->heartbeatRow('web-1', 700, ['connection' => 'consumer', 'last_seen_at' => $now - 100]);
        $this->insertHeartbeats([$worker]);
        $this->reservedJob($worker['worker_id'], $now - 60, 'producer');

        $rows = app(LiveWorkers::class)->list();
        self::assertCount(1, $rows, 'the lease of a job pushed through another connection name was lost');
        self::assertSame(1, $rows[0]->active_leases);
        self::assertFalse($rows[0]->heartbeat_fresh);

        // The lease uses the consuming worker's retry_after (90 s), not the producer's (30 s).
        $this->mirror()->table('orrery_jobs')->update(['reserved_at' => Clock::toDecimal($now - 91)]);
        self::assertSame([], app(LiveWorkers::class)->list());
    }

    /**
     * Rejects: capacity from all workers, subscribed or not.
     */
    public function test_capacity_counts_live_subscribed_workers(): void
    {
        $now = Clock::now();
        $this->app['config']->set('queue.connections.other', ['driver' => 'database', 'connection' => 'testbench', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['subscribed_queues' => 'default,high']),
            $this->heartbeatRow('web-1', 2, ['subscribed_queues' => 'low']),
            $this->heartbeatRow('web-1', 3, ['subscribed_queues' => 'high', 'last_seen_at' => $now - 200]),
            $this->heartbeatRow('web-1', 4, ['subscribed_queues' => 'high', 'connection' => 'other']),
            $this->heartbeatRow('web-1', 5, ['subscribed_queues' => 'highest']),
        ]);

        $live = app(LiveWorkers::class);
        self::assertSame(1, $live->capacity('database', 'high'));
        self::assertSame(1, $live->capacity('database', 'low'));
        self::assertSame(1, $live->capacity('database', 'default'));
        self::assertSame(1, $live->capacity('other', 'high'));
        self::assertSame(0, $live->capacity('database', 'missing'));
        self::assertCount(4, $live->list());
    }

    /**
     * Rejects: the old pid * -1 expression on an unsigned column.
     */
    public function test_mysql_workers_do_not_overflow_on_a_zero_ppid(): void
    {
        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['ppid' => 0]),
            $this->heartbeatRow('web-1', 2, ['ppid' => 0]),
        ]);

        self::assertCount(2, $this->getJson('/orrery/api/workers')->assertOk()->json());
    }

    /**
     * Rejects: a renamed field that the API reads.
     */
    public function test_list_rows_have_the_documented_fields(): void
    {
        $now = Clock::now();
        $parentStart = $now - 900;

        $daemon = $this->heartbeatRow('web-1', 10, [
            'ppid' => 1,
            'subscribed_queues' => 'default,high',
            'current_queue' => 'high',
            'options' => json_encode(['tries' => 3, 'timeout' => 60]),
            'started_at' => $now - 300,
            'last_seen_at' => $now - 2,
        ]);
        $olderChild = $this->heartbeatRow('web-1', 21, [
            'ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child',
            'subscribed_queues' => 'default', 'current_queue' => 'default', 'started_at' => $now - 40, 'last_seen_at' => $now - 30,
        ]);
        $newerChild = $this->heartbeatRow('web-1', 22, [
            'ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child',
            'subscribed_queues' => 'emails', 'current_queue' => 'emails', 'options' => json_encode(['tries' => 5]),
            'started_at' => $now - 10, 'last_seen_at' => $now - 3,
        ]);
        $this->insertHeartbeats([$daemon, $olderChild, $newerChild]);
        $this->reservedJob($daemon['worker_id'], $now - 5);
        $this->reservedJob($olderChild['worker_id'], $now - 20);
        $this->reservedJob($newerChild['worker_id'], $now - 4);

        $rows = app(LiveWorkers::class)->list();
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame(self::FIELDS, array_keys(get_object_vars($row)));
        }

        $byMode = [];

        foreach ($rows as $row) {
            $byMode[$row->mode] = $row;
        }

        $worker = $byMode['daemon'];
        self::assertSame($daemon['worker_id'], $worker->worker_id);
        self::assertSame('web-1', $worker->host);
        self::assertSame(10, $worker->pid);
        self::assertSame(1, $worker->ppid);
        self::assertEqualsWithDelta($now - 300, $worker->started_at, 0.000001);
        self::assertSame('database', $worker->connection);
        self::assertSame(['default', 'high'], $worker->subscribed_queues);
        self::assertSame('high', $worker->current_queue);
        self::assertSame(['tries' => 3, 'timeout' => 60], $worker->options);
        self::assertEqualsWithDelta($now - 2, $worker->last_seen_at, 0.000001);
        self::assertTrue($worker->heartbeat_fresh);
        self::assertSame(1, $worker->active_leases);
        self::assertSame([], $worker->children);

        $listen = $byMode['listen'];
        self::assertSame('web-1:20:'.Clock::toDecimal($parentStart), $listen->worker_id);
        self::assertSame(20, $listen->pid);
        self::assertNull($listen->ppid);
        self::assertEqualsWithDelta($parentStart, $listen->started_at, 0.000001);
        self::assertSame(['emails'], $listen->subscribed_queues, 'the queue fields come from the newest child');
        self::assertSame('emails', $listen->current_queue);
        self::assertSame(['tries' => 5], $listen->options);
        self::assertEqualsWithDelta($now - 3, $listen->last_seen_at, 0.000001);
        self::assertTrue($listen->heartbeat_fresh);
        self::assertSame(2, $listen->active_leases, 'the parent sums the leases of its children');
        self::assertCount(2, $listen->children);
        self::assertSame([$newerChild['worker_id'], $olderChild['worker_id']], array_map(static fn (object $child): string => $child->worker_id, $listen->children));

        foreach ($listen->children as $child) {
            self::assertSame(self::FIELDS, array_keys(get_object_vars($child)));
            self::assertSame('listen-child', $child->mode);
            self::assertSame(20, $child->ppid);
            self::assertSame(1, $child->active_leases);
            self::assertSame([], $child->children);
        }

        self::assertCount(2, app(LiveWorkers::class)->list());
    }

    /**
     * Rejects: a workload without the native queue:pause of a worker's subscribed queue, or with a
     * pause of an unknown queue or of another connection.
     */
    public function test_workload_shows_the_native_pause_of_a_subscribed_queue(): void
    {
        // The worker's queues are measured rows, and a measured row
        // carries the native pause state of the shared measurement (5 s). Laravel's own pause()
        // below does not forget that entry, so the test reads without the cache.
        $this->app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['subscribed_queues' => 'default,emails'])]);
        self::assertSame(['database:default' => [], 'database:emails' => []], $this->pauseSources());

        $this->app['queue']->pause('database', 'emails');
        $this->app['queue']->pause('database', 'unknown-to-orrery');
        $this->app['queue']->pause('redis', 'default');

        self::assertSame(['database:default' => [], 'database:emails' => ['queue:pause']], $this->pauseSources());

        $this->app['queue']->resume('database', 'emails');
        self::assertSame(['database:default' => [], 'database:emails' => []], $this->pauseSources());
    }

    /**
     * The pause sources of each workload row, keyed `connection:queue`.
     *
     * @return array<string, list<string>>
     */
    private function pauseSources(): array
    {
        $sources = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            $sources[$row['connection'].':'.$row['name']] = array_column($row['pauses'], 'source');
        }

        ksort($sources);

        return $sources;
    }

    /**
     * Rejects: matching a null reserved_by to a worker.
     */
    public function test_reservation_without_a_verified_worker_gives_no_lease(): void
    {
        $now = Clock::now();
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1)]);

        // A read-confirmed (swap-off) reservation names no worker.
        $this->reservedJob(null, $now - 5);
        $this->reservedJob('', $now - 5);

        $rows = app(LiveWorkers::class)->list();
        self::assertCount(1, $rows, 'a reservation without a verified worker added a worker row');
        self::assertSame(0, $rows[0]->active_leases);
        self::assertCount(1, $this->getJson('/orrery/api/workers')->assertOk()->json());
    }
}
