<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Workers\WorkerFixtures;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use RuntimeException;

/**
 * The `current_job` of each worker of GET /api/workers: the
 * reserved mirror row that the worker holds with a live lease, the latest
 * reservation first. Null means unknown, never idle.
 */
final class WorkersCurrentJobTest extends ControllerTest
{
    use WorkerFixtures;

    private const NOW = 1_790_000_052.5;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The lease interval is the retry_after of the consuming worker's connection.
        $app['config']->set('queue.connections.database.retry_after', 100);
        // A second consuming connection name with a longer lease.
        $app['config']->set('queue.connections.database-long', [
            'driver' => 'database',
            'connection' => TestEnvironment::PRIMARY,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 300,
            'after_commit' => false,
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
     * Known limit: it would pass a lease check that uses 60 s instead of retry_after for reservations younger than 60 s (the live job here is 30 s old).
     */
    public function test_a_worker_with_a_live_lease_shows_its_job(): void
    {
        $worker = $this->heartbeatRow('web-1', 10);
        $this->insertHeartbeats([$worker]);
        $this->job($worker['worker_id'], self::NOW - 30, 'App\\Jobs\\SendInvoice');

        self::assertSame(['name' => 'App\\Jobs\\SendInvoice', 'started_at' => self::NOW - 30], $this->workers()['web-1:10']['current_job']);
    }

    /**
     * Known limit: it would pass a lease that ends one microsecond later than reserved_at + retry_after (the boundary rows are 1 s apart).
     */
    public function test_an_expired_lease_gives_no_current_job(): void
    {
        $expired = $this->heartbeatRow('web-1', 10);
        $boundary = $this->heartbeatRow('web-1', 11);
        $live = $this->heartbeatRow('web-1', 12);
        $this->insertHeartbeats([$expired, $boundary, $live]);
        $this->job($expired['worker_id'], self::NOW - 101, 'Expired');
        $this->job($boundary['worker_id'], self::NOW - 100, 'Boundary');
        $this->job($live['worker_id'], self::NOW - 99, 'Live');

        $rows = $this->workers();

        self::assertNull($rows['web-1:10']['current_job'], 'reserved_at + retry_after is before now');
        self::assertNull($rows['web-1:11']['current_job'], 'the lease must end later than now');
        self::assertSame('Live', $rows['web-1:12']['current_job']['name'] ?? null);
    }

    /**
     * Known limit: it would pass a choice by the largest id when the latest reservation also has the largest id; the rows here are inserted newest first.
     */
    public function test_of_two_candidates_the_latest_reservation_wins(): void
    {
        $worker = $this->heartbeatRow('web-1', 10);
        $this->insertHeartbeats([$worker]);
        $this->job($worker['worker_id'], self::NOW - 5, 'Newer');
        $this->job($worker['worker_id'], self::NOW - 50, 'Older');
        // Rows that are not reserved, or that another worker holds, never count.
        $this->job($worker['worker_id'], self::NOW - 1, 'Completed', 'completed');
        $this->job('web-9:1:1.000000', self::NOW - 1, 'Other');

        self::assertSame(['name' => 'Newer', 'started_at' => self::NOW - 5], $this->workers()['web-1:10']['current_job']);
    }

    /**
     * Known limit: it would pass a parent that shows the job of its newest child only when that child holds the latest reservation.
     */
    public function test_a_listen_parent_shows_the_job_of_its_active_child(): void
    {
        $parentStart = self::NOW - 900;
        $older = $this->heartbeatRow('web-1', 21, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 40, 'last_seen_at' => self::NOW - 30]);
        $newer = $this->heartbeatRow('web-1', 22, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 10, 'last_seen_at' => self::NOW - 3]);
        $this->insertHeartbeats([$older, $newer]);
        // The older child (a stale heartbeat) still holds a live lease; the newer child is between jobs.
        $this->job($older['worker_id'], self::NOW - 25, 'App\\Jobs\\Report');

        $parent = $this->workers()['web-1:listen(20)'];

        self::assertSame(['name' => 'App\\Jobs\\Report', 'started_at' => self::NOW - 25], $parent['current_job']);
        $children = [];

        foreach ($parent['children'] as $child) {
            $children[$child['pid']] = $child['current_job'];
        }

        self::assertSame([22 => null, 21 => ['name' => 'App\\Jobs\\Report', 'started_at' => self::NOW - 25]], $children, 'each child is matched by its own id');
    }

    /**
     * Known limit: it would pass a lease that takes the larger retry_after of the two connections (the job's connection has the smaller one here).
     */
    public function test_the_lease_uses_the_retry_after_of_the_workers_consuming_connection(): void
    {
        // The worker consumes through database-long (300 s); the mirror row names the producer's connection (100 s).
        $long = $this->heartbeatRow('web-1', 10, ['connection' => 'database-long']);
        $short = $this->heartbeatRow('web-1', 11);
        $this->insertHeartbeats([$long, $short]);
        $this->job($long['worker_id'], self::NOW - 200, 'LongLease');
        $this->job($short['worker_id'], self::NOW - 200, 'ShortLease', connection: 'database-long');

        $rows = $this->workers();

        self::assertSame(['name' => 'LongLease', 'started_at' => self::NOW - 200], $rows['web-1:10']['current_job']);
        self::assertNull($rows['web-1:11']['current_job'], 'the database worker lease (100 s) has expired, whatever the job connection');
    }

    /**
     * Known limit: it would pass a parent that takes the job of the child with the larger pid when that child also holds the latest reservation (here it does not).
     */
    public function test_of_two_children_with_jobs_the_parent_shows_the_latest_reservation(): void
    {
        $parentStart = self::NOW - 900;
        // The newer child (fresh heartbeat, pid 22) holds the older reservation.
        $older = $this->heartbeatRow('web-1', 21, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 40, 'last_seen_at' => self::NOW - 30]);
        $newer = $this->heartbeatRow('web-1', 22, ['ppid' => 20, 'parent_started_at' => $parentStart, 'mode' => 'listen-child', 'started_at' => self::NOW - 10, 'last_seen_at' => self::NOW - 3]);
        $this->insertHeartbeats([$older, $newer]);
        $this->job($newer['worker_id'], self::NOW - 50, 'Earlier');
        $this->job($older['worker_id'], self::NOW - 8, 'Later');

        $parent = $this->workers()['web-1:listen(20)'];

        self::assertSame(['name' => 'Later', 'started_at' => self::NOW - 8], $parent['current_job']);
    }

    /**
     * Known limit: it would pass a guard around the whole controller that also hides a failure of the worker list itself (that read is not made to fail).
     */
    public function test_a_failing_current_job_read_gives_null_and_the_response_still_answers(): void
    {
        Exceptions::fake();
        $worker = $this->heartbeatRow('web-1', 10);
        $this->insertHeartbeats([$worker]);
        $this->job($worker['worker_id'], self::NOW - 5, 'Hidden');
        Container::getInstance()->make(PackageSession::class)->mirror()->beforeExecuting(static function (string $sql): void {
            // The current-job read selects the name; the lease count does not.
            if (str_starts_with(strtolower(ltrim($sql)), 'select') && str_contains($sql, 'reserved_by') && str_contains($sql, 'name')) {
                throw new RuntimeException('The current jobs cannot be read.');
            }
        });

        self::assertNull($this->workers()['web-1:10']['current_job']);
        Exceptions::assertReported(static fn (WatchedOperationFailed $error): bool => $error->operation === 'workers:current_jobs' && $error->causeClass === RuntimeException::class && str_contains($error->getMessage(), 'The current jobs cannot be read.'));
    }

    /**
     * Known limit: it would pass an implementation that guesses from current_queue only when no reserved row exists at all.
     */
    public function test_a_reservation_without_a_worker_id_gives_null(): void
    {
        // With the connector swap off, reserved_by is never known.
        $worker = $this->heartbeatRow('web-1', 10, ['current_queue' => 'default']);
        $this->insertHeartbeats([$worker]);
        $this->job(null, self::NOW - 5, 'Unknown');

        self::assertNull($this->workers()['web-1:10']['current_job']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workers(): array
    {
        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workers')->assertOk()->json() as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('current_job', $row);
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function job(?string $reservedBy, float $reservedAt, string $name, string $status = 'reserved', string $connection = 'database'): void
    {
        $at = Clock::toDecimal($reservedAt);

        $this->mirror()->table('orrery_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => $connection,
            'queue' => 'default',
            'name' => $name,
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $at,
            'reserved_at' => $at,
            'reserved_by' => $reservedBy,
            'attempts' => 1,
            'updated_at' => $at,
        ]);
    }
}
