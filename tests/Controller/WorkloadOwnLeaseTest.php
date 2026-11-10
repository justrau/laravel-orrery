<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Support\Carbon;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\TestEnvironment;

/**
 * `running` of GET /api/workload counts each reserved mirror row by the
 * lease of its own connection, when two aliases of one jobs table have
 * different retry_after values (`database` 90 s, `database-long` 900 s).
 * The backlog (`length`) keeps the shortest lease of the table on purpose:
 * a live worker of the 90 s alias takes such a job again.
 */
final class WorkloadOwnLeaseTest extends ControllerTest
{
    use WorkerFixtures;

    /** The request time. */
    private const NOW = 1_790_000_052.5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.connections.database-long', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 900,
            'after_commit' => false,
        ]);
        $app['config']->set('orrery.waits', ['*' => 60]);
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
     * Rejects: one lease per table (the shortest, 90 s), which counts the
     * job of the 900 s alias reserved 300 s ago as not running; a lease per
     * table that takes the longest (900 s), which counts the 90 s alias's
     * job reserved 300 s ago as running; or a backlog that drops a
     * reservation that expired for the shortest lease.
     * Still passes: a backlog that counts the expired reservation only while
     * a live 90 s worker subscribes to the queue (the test has one).
     */
    public function test_running_counts_each_job_by_its_own_lease_and_the_backlog_by_the_shortest(): void
    {
        // A live 90 s worker on the queue name.
        $this->insertHeartbeats([$this->heartbeatRow('host-a', 101, ['connection' => 'database', 'subscribed_queues' => 'leases'])]);

        // The job of the 900 s alias, reserved 300 s ago: running, and expired for the 90 s worker.
        $this->hostReservation('leases', self::NOW - 300);
        $this->reservedJob(null, self::NOW - 300, 'database-long', 'leases');
        // The job of the 90 s alias, reserved 300 s ago: expired, not running.
        $this->hostReservation('leases', self::NOW - 300);
        $this->reservedJob(null, self::NOW - 300, 'database', 'leases');
        // A live reservation of each alias.
        $this->hostReservation('leases', self::NOW - 10);
        $this->reservedJob(null, self::NOW - 10, 'database', 'leases');
        $this->hostReservation('leases', self::NOW - 10);
        $this->reservedJob(null, self::NOW - 10, 'database-long', 'leases');
        // Expired for the 900 s alias as well.
        $this->hostReservation('leases', self::NOW - 1000);
        $this->reservedJob(null, self::NOW - 1000, 'database-long', 'leases');

        $rows = array_values(array_filter(
            (array) $this->getJson('/orrery/api/workload')->assertOk()->json(),
            static fn (array $row): bool => $row['name'] === 'leases',
        ));

        self::assertCount(1, $rows);
        self::assertSame(['database', 'database-long'], $rows[0]['connections']);
        self::assertSame(3, $rows[0]['running'], 'the two live reservations and the 900 s job reserved 300 s ago');
        // The backlog: every reservation older than the shortest lease (90 s), the 900 s job of 300 s ago included.
        self::assertSame(3, $rows[0]['length']);
        self::assertSame(1, $rows[0]['processes']);
    }

    private function hostReservation(string $queue, float $reservedAt): void
    {
        $this->app['db']->connection()->table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => (int) floor($reservedAt),
            'available_at' => (int) floor($reservedAt) - 5,
            'created_at' => (int) floor($reservedAt) - 5,
        ]);
    }
}
