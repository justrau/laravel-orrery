<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PDOException;

/**
 * GET /api/workload with a backlog that cannot
 * be read. The rewrite of the probe
 * OperatorViewProbeTest::test_backlog_read_error_drops_the_queue_with_http_200.
 */
final class WorkloadUnknownTest extends ControllerTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The probe's queue is also named in the configuration, so it is known without the jobs table.
        $app['config']->set('orrery.waits', ['*' => 60, 'database:emails' => 60]);
        $app['config']->set('orrery.dashboard.measure_cache_seconds', 0);
    }

    protected function tearDown(): void
    {
        // The rollback of the fence migration reads the queue connections: the broken one goes first.
        $this->app?->make('config')->set('queue.connections.broken', null);

        parent::tearDown();
    }

    /**
     * Rejects: `[]` (the queue vanishes), and `length: 0`.
     */
    public function test_unreadable_target_gives_unknown_rows(): void
    {
        $this->readyJob('emails', time() - 600);
        $this->failJobsReads();

        $rows = $this->workload();

        self::assertArrayHasKey('emails', $rows);
        $expected = ['length' => null, 'oldest_age' => null, 'wait' => null, 'over_limit' => null, 'backlog_unknown' => true, 'threshold' => 60];
        $actual = array_intersect_key($rows['emails'], $expected);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
        self::assertArrayHasKey('default', $rows, 'the default queue of the connection');
        self::assertTrue($rows['default']['backlog_unknown']);
        self::assertSame(['over_limit_since' => null, 'over_limit_since_censored' => false], array_intersect_key($rows['emails'], array_flip(['over_limit_since', 'over_limit_since_censored'])));
    }

    /**
     * Rejects: the `target() === null` skip (the unresolvable connection vanishes).
     */
    public function test_unresolvable_connection_gives_unknown_rows(): void
    {
        $this->app['config']->set('queue.connections.broken', [
            'driver' => 'database',
            'connection' => 'orrery_no_such_connection',
            'table' => 'jobs',
            'queue' => 'brokenq',
            'retry_after' => 90,
        ]);
        $this->readyJob('emails', time() - 600);

        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            $rows[$row['connection'].':'.$row['name']] = $row;
        }

        self::assertTrue($rows['broken:brokenq']['backlog_unknown']);
        self::assertNull($rows['broken:brokenq']['length']);
        self::assertSame(['broken'], $rows['broken:brokenq']['connections'], 'an unresolved connection is never merged with another alias');
        self::assertFalse($rows['database:emails']['backlog_unknown']);
        self::assertSame(1, $rows['database:emails']['length']);
        self::assertTrue($rows['database:emails']['over_limit']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function workload(): array
    {
        $rows = [];

        foreach ((array) $this->getJson('/orrery/api/workload')->assertOk()->json() as $row) {
            self::assertIsArray($row);
            self::assertSame('database', $row['connection']);
            $rows[(string) $row['name']] = $row;
        }

        return $rows;
    }

    private function failJobsReads(): void
    {
        $table = DB::connection(TestEnvironment::PRIMARY)->getTablePrefix().'jobs';
        $pattern = '/ from (?:[`"]?[a-z0-9_]+[`"]?\.)?[`"]?'.preg_quote($table, '/').'[`"]?( |$)/i';

        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use ($pattern): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'select') && preg_match($pattern, $event->sql) === 1) {
                throw new QueryException((string) $event->connectionName, $event->sql, $event->bindings, new PDOException('SQLSTATE[HY000]: General error: 3024 Query execution was interrupted'));
            }
        });
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
}
