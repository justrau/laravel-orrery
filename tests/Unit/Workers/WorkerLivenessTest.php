<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Workers;

use Illuminate\Config\Repository;
use Justrau\Orrery\Workers\WorkerLiveness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The worker liveness fact of a running row:
 * `{id, heartbeat_at, liveness, silent_since}` and the lease end.
 */
final class WorkerLivenessTest extends TestCase
{
    private const NOW = 1_790_000_000.0;

    private const WORKER = 'host:1:1790000000';

    /**
     * Each step of the order, and the step before it that must win.
     *
     * @return array<string, array{0: string|null, 1: float|null, 2: float|null, 3: string, 4: float|null, 5?: string}>
     */
    public static function steps(): array
    {
        // [reserved_by, reserved_at (seconds before NOW), heartbeat (seconds before NOW), liveness, silent_since (seconds before NOW), connection]
        return [
            'no reserved_by, even with a live lease' => [null, 10.0, null, 'unknown', null],
            'an empty reserved_by' => ['', 10.0, 5.0, 'unknown', null],
            'a live lease and a stale heartbeat: a long job inside its lease' => [self::WORKER, 180.0, 180.0, 'live', null],
            'a live lease and no heartbeat row' => [self::WORKER, 10.0, null, 'live', null],
            'an expired lease and a fresh heartbeat' => [self::WORKER, 400.0, 59.0, 'live', null],
            'an expired lease and a heartbeat exactly at the silence' => [self::WORKER, 400.0, 60.0, 'silent', 0.0],
            'an expired lease and a stale heartbeat: lease end later' => [self::WORKER, 400.0, 500.0, 'silent', 100.0],
            'an expired lease and a stale heartbeat: heartbeat later' => [self::WORKER, 1000.0, 200.0, 'silent', 140.0],
            'an expired lease and no heartbeat row' => [self::WORKER, 400.0, null, 'unknown', null],
            'the lease ends exactly now' => [self::WORKER, 300.0, null, 'unknown', null],
            'an unknown lease (connection gone) and a fresh heartbeat' => [self::WORKER, 10.0, 30.0, 'live', null, 'gone'],
            'an unknown lease (connection gone) and a stale heartbeat: never silent from a guessed lease end' => [self::WORKER, 10.0, 90.0, 'silent', 30.0, 'gone'],
            'an unknown lease (connection gone) and no heartbeat row' => [self::WORKER, 10.0, null, 'unknown', null, 'gone'],
        ];
    }

    /**
     * Rejects: liveness from the heartbeat alone (a 3-minute job inside its
     * lease shown as silent), a lease that wins over a missing reserved_by,
     * a fresh-heartbeat bound that counts the silence itself as fresh, a
     * missing heartbeat row shown as silent, and a silent_since that is not
     * the later of the lease end and heartbeat + silence.
     * Still passes: a heartbeat of another worker with the same id (the id
     * holds the host, the pid and the start time).
     */
    #[DataProvider('steps')]
    public function test_each_step_of_the_liveness_order(?string $worker, ?float $reservedAgo, ?float $heartbeatAgo, string $liveness, ?float $silentAgo, string $connection = 'database'): void
    {
        $facts = $this->liveness(['queue.connections.database' => ['driver' => 'database', 'retry_after' => 300]]);
        $row = (object) ['status' => 'reserved', 'connection' => $connection, 'reserved_by' => $worker, 'reserved_at' => $reservedAgo === null ? null : (string) (self::NOW - $reservedAgo)];
        $lease = $facts->leaseExpiresAt($row);
        $heartbeats = $heartbeatAgo === null ? [] : [self::WORKER => self::NOW - $heartbeatAgo];

        $fact = $facts->of($row, $lease, $heartbeats, self::NOW);

        self::assertSame($liveness, $fact['liveness']);
        self::assertSame($worker === '' ? null : $worker, $fact['id']);
        self::assertSame($worker === null || $worker === '' || $heartbeatAgo === null ? null : self::NOW - $heartbeatAgo, $fact['heartbeat_at']);

        if ($silentAgo === null) {
            self::assertNull($fact['silent_since']);
        } else {
            self::assertEqualsWithDelta(self::NOW - $silentAgo, $fact['silent_since'], 0.000_01);
        }
    }

    /**
     * Rejects: a lease from a fixed 60 s instead of the connection's
     * retry_after, a missing retry_after that is not Laravel's 60, a lease
     * for a row without reserved_at, and a lease guessed for a connection
     * that is not a configured `database` queue connection (a removed
     * connection, or one of another driver): its lease is unknown (null).
     */
    public function test_the_lease_ends_at_reserved_at_plus_the_connections_retry_after(): void
    {
        $facts = $this->liveness([
            'queue.connections.database' => ['driver' => 'database', 'retry_after' => 90],
            'queue.connections.other' => ['driver' => 'database', 'connection' => 'secondary'],
            'queue.connections.text' => ['driver' => 'database', 'connection' => 'third', 'retry_after' => '120'],
            'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 30],
        ]);

        self::assertSame(self::NOW + 90.0, $facts->leaseExpiresAt((object) ['connection' => 'database', 'reserved_at' => (string) self::NOW]));
        self::assertSame(self::NOW + 60.0, $facts->leaseExpiresAt((object) ['connection' => 'other', 'reserved_at' => self::NOW]), "Laravel's default");
        self::assertSame(self::NOW + 120.0, $facts->leaseExpiresAt((object) ['connection' => 'text', 'reserved_at' => self::NOW]));
        self::assertNull($facts->leaseExpiresAt((object) ['connection' => 'missing', 'reserved_at' => self::NOW]), 'not configured: unknown');
        self::assertNull($facts->leaseExpiresAt((object) ['connection' => 'redis', 'reserved_at' => self::NOW]), 'not a database queue connection: unknown');
        self::assertNull($facts->leaseExpiresAt((object) ['connection' => 'database', 'reserved_at' => null]));
        self::assertSame(90, $facts->retryAfter('database'));
        self::assertNull($facts->retryAfter('missing'));
    }

    /**
     * Rejects: a lease from the shortest retry_after of the queue connections
     * on the same jobs table: Laravel's pop reads the worker's
     * own queue names first, so a long job on a connection with a long
     * retry_after (`database-long`, queue `long`) is not expired after the
     * shorter retry_after of another connection on that table. Each
     * connection's own retry_after decides, as the README says. Would still
     * pass: a rule that groups only connections on the same table and queue.
     */
    public function test_each_queue_connection_has_its_own_retry_after(): void
    {
        $facts = $this->liveness([
            'database.default' => 'mysql',
            'queue.connections.a' => ['driver' => 'database', 'queue' => 'long', 'retry_after' => 3700],
            'queue.connections.b' => ['driver' => 'database', 'connection' => 'mysql::write', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.connections.c' => ['driver' => 'database', 'table' => 'other_jobs', 'retry_after' => 30],
        ]);

        self::assertSame(3700, $facts->retryAfter('a'));
        self::assertSame(90, $facts->retryAfter('b'));
        self::assertSame(30, $facts->retryAfter('c'));
        self::assertSame(['a' => 3700, 'b' => 90, 'c' => 30], $facts->leases());

        $row = (object) ['status' => 'reserved', 'connection' => 'a', 'reserved_by' => self::WORKER, 'reserved_at' => self::NOW - 300];
        self::assertSame(self::NOW + 3400.0, $facts->leaseExpiresAt($row));
        self::assertSame('live', $facts->of($row, $facts->leaseExpiresAt($row), [], self::NOW)['liveness'], 'a 5-minute job inside its own lease is live');
    }

    /**
     * The lease map of one list read: leases() walks the queue
     * configuration once and gives each configured `database` connection
     * its retryAfter(); retryAfter() and leaseExpiresAt() with that map read
     * no configuration.
     *
     * Rejects: a map that differs from retryAfter() (each connection's own
     * retry_after, Laravel's 60, a connection of another driver), and a
     * lease that walks the configuration again although it got the map.
     * Still passes: a map read again per list read (it is not cached).
     */
    public function test_the_lease_map_equals_retry_after_and_is_used_without_a_config_walk(): void
    {
        $config = new CountingRepository;

        foreach ([
            'database.default' => 'mysql',
            'queue.connections.a' => ['driver' => 'database', 'connection' => 'mysql::write', 'retry_after' => 60],
            'queue.connections.b' => ['driver' => 'database', 'retry_after' => 600],
            'queue.connections.c' => ['driver' => 'database', 'table' => 'other_jobs'],
            'queue.connections.redis' => ['driver' => 'redis', 'retry_after' => 30],
        ] as $key => $value) {
            $config->set($key, $value);
        }

        $facts = new WorkerLiveness($config);
        $leases = $facts->leases();
        self::assertSame(['a' => 60, 'b' => 600, 'c' => 60], $leases);

        foreach (['a', 'b', 'c', 'redis', 'missing'] as $connection) {
            self::assertSame($facts->retryAfter($connection), $leases[$connection] ?? null, $connection);
        }

        $config->reads = 0;
        $row = (object) ['connection' => 'a', 'reserved_at' => self::NOW];
        self::assertSame(self::NOW + 5.0, $facts->leaseExpiresAt($row, ['a' => 5]), 'the given map decides');
        self::assertNull($facts->leaseExpiresAt($row, []), 'a connection outside the map has an unknown lease');
        self::assertSame(5, $facts->retryAfter('a', ['a' => 5]));
        self::assertSame(0, $config->reads, 'no configuration walk with a map');
    }

    /**
     * Rejects: a silence that ignores `orrery.stuck.worker_silence_seconds`,
     * and an invalid value (0, negative, text) taken as it is instead of
     * the default 60.
     */
    public function test_the_silence_comes_from_the_configuration_with_a_default(): void
    {
        $row = (object) ['status' => 'reserved', 'connection' => 'database', 'reserved_by' => self::WORKER, 'reserved_at' => self::NOW - 1000];
        $heartbeats = [self::WORKER => self::NOW - 100.0];

        $configured = $this->liveness(['orrery.stuck.worker_silence_seconds' => 120]);
        self::assertSame(120, $configured->silenceSeconds());
        self::assertSame('live', $configured->of($row, $configured->leaseExpiresAt($row), $heartbeats, self::NOW)['liveness']);

        foreach ([null, 0, -5, 'abc', 1.5] as $invalid) {
            $default = $this->liveness(['orrery.stuck.worker_silence_seconds' => $invalid]);
            self::assertSame(60, $default->silenceSeconds(), var_export($invalid, true));
            $fact = $default->of($row, $default->leaseExpiresAt($row), $heartbeats, self::NOW);
            self::assertSame('silent', $fact['liveness']);
            self::assertEqualsWithDelta(self::NOW - 40.0, $fact['silent_since'], 0.000_01);
        }

        $unset = new WorkerLiveness(new Repository(['orrery' => []]));
        self::assertSame(60, $unset->silenceSeconds(), 'a published configuration without the stuck block');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function liveness(array $values): WorkerLiveness
    {
        $config = new Repository;

        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }

        return new WorkerLiveness($config);
    }
}

/**
 * A configuration that counts the reads of `queue.connections`.
 */
final class CountingRepository extends Repository
{
    public int $reads = 0;

    public function get($key, $default = null)
    {
        if ($key === 'queue.connections') {
            $this->reads++;
        }

        return parent::get($key, $default);
    }
}
