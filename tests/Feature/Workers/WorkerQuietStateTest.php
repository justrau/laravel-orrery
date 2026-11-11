<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Workers;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The `state` of a listed worker: `quiet` when its last heartbeat is older
 * than two `workers.heartbeat_seconds` and it holds no live lease, else
 * `live`. The clock is frozen on a whole second, so the boundary is exact.
 */
final class WorkerQuietStateTest extends ControllerTest
{
    use WorkerFixtures;

    private const NOW = 1_800_000_000.0;

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
     * Rejects: a worker called quiet from its age alone, or a state that
     * is missing. It would still pass a window of any length above 5 s.
     */
    public function test_a_fresh_heartbeat_is_live(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 5])]);

        self::assertSame(['live'], $this->states());
    }

    /**
     * Rejects: no quiet state at all (every listed worker live), and a
     * window of the 60-second liveness rule. It would still pass a fixed
     * window of 30 to 39 s with the default interval.
     */
    public function test_a_heartbeat_older_than_two_intervals_without_a_lease_is_quiet(): void
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
     * Rejects: `>=` at the boundary (exactly two intervals is still live),
     * and a window shorter than two intervals.
     */
    public function test_exactly_two_intervals_is_still_live_and_one_microsecond_more_is_quiet(): void
    {
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 30])]);
        self::assertSame(['live'], $this->states());

        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999969.999999']);
        self::assertSame(['quiet'], $this->states());
    }

    /**
     * Rejects: a fixed 30-second window instead of two
     * `workers.heartbeat_seconds`.
     */
    public function test_the_quiet_window_follows_a_non_default_heartbeat_interval(): void
    {
        $this->app['config']->set('orrery.workers.heartbeat_seconds', 5);
        $this->insertHeartbeats([$this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 12])]);
        self::assertSame(['quiet'], $this->states(), 'two intervals of 5 s is 10 s');

        $this->app['config']->set('orrery.workers.heartbeat_seconds', 25);
        $this->mirror()->table('orrery_worker_heartbeats')->update(['last_seen_at' => '1799999955.000000']);
        self::assertSame(['live'], $this->states(), 'two intervals of 25 s is 50 s');
    }

    /**
     * Rejects: a parent that takes its state from one child only, and an
     * API answer without the field on the row and its children.
     */
    public function test_a_listen_parent_is_live_while_a_child_is_live_and_the_api_sends_the_state(): void
    {
        $parentStart = self::NOW - 900;
        $quiet = $this->heartbeatRow('web-1', 21, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'last_seen_at' => self::NOW - 50]);
        $leased = $this->heartbeatRow('web-1', 22, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'last_seen_at' => self::NOW - 55]);
        $this->insertHeartbeats([$quiet, $leased]);
        $this->reservedJob($leased['worker_id'], self::NOW - 10);

        $rows = $this->getJson('/orrery/api/workers')->assertOk()->json();
        self::assertCount(1, $rows);
        self::assertSame('live', $rows[0]['state']);
        self::assertSame(['quiet', 'live'], array_column($rows[0]['children'], 'state'));

        $this->mirror()->table('orrery_jobs')->delete();
        $rows = $this->getJson('/orrery/api/workers')->assertOk()->json();
        self::assertSame('quiet', $rows[0]['state']);
    }

    /**
     * Rejects: a workload row without `quiet_processes`, or one that
     * counts a live worker as quiet.
     */
    public function test_the_workload_counts_the_quiet_workers_of_a_queue(): void
    {
        $this->insertHeartbeats([
            $this->heartbeatRow('web-1', 1, ['last_seen_at' => self::NOW - 40]),
            $this->heartbeatRow('web-1', 2, ['last_seen_at' => self::NOW - 2]),
        ]);

        $rows = collect($this->getJson('/orrery/api/workload')->assertOk()->json())->keyBy('name');
        self::assertSame(2, $rows['default']['processes']);
        self::assertSame(1, $rows['default']['quiet_processes']);
    }

    /** @return list<string> */
    private function states(): array
    {
        return array_map(static fn (object $row): string => $row->state, app(LiveWorkers::class)->list());
    }
}
