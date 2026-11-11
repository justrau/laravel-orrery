<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The partition discovery of the retention:
 * one index seek per distinct (connection, queue, status,
 * silenced, monitored) tuple on the `retention` index, never a GROUP BY
 * over the whole index. SQLite: EXPLAIN QUERY PLAN; MySQL: EXPLAIN (a
 * range access); Postgres: EXPLAIN (FORMAT JSON) with sequential and bitmap
 * scans turned off for the statement, so the test checks the use of the
 * index condition, not the choice against a table scan of a small table.
 *
 * FreshSchema: ANALYZE writes planner statistics (on SQLite the table
 * sqlite_stat1).
 */
#[FreshSchema]
final class RetentionDiscoveryPlanTest extends TestCase
{
    /**
     * Rejects: a seek that the engine runs as a scan of the index from its
     * start (for example a MySQL row-value comparison, which its range
     * optimizer does not serve), or as a scan of the table.
     */
    public function test_the_discovery_seek_is_an_index_range(): void
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $this->seedTuples($connection);
        $query = Retention::discovery($connection, ['database', 'q3', 'completed', false, false])
            ->select(['connection', 'queue', 'status', 'silenced', 'monitored'])
            ->orderBy('connection')->orderBy('queue')->orderBy('status')->orderBy('silenced')->orderBy('monitored')
            ->limit(1);
        $index = IndexName::for($connection, 'orrery_jobs', 'retention');

        self::assertSame(['database', 'q3', 'failed', false, false], array_values(array_map(
            static fn (mixed $value): mixed => is_string($value) ? $value : (bool) $value,
            (array) $query->first(),
        )), 'The seek finds the next tuple.');

        [$used, $range] = $this->plan($connection, $query->toSql(), $query->getBindings());

        self::assertSame([$index], $used, 'The seek reads orrery_jobs through the retention index only.');
        self::assertTrue($range, 'The seek starts at the bound, not at the start of the index.');
    }

    /**
     * Rejects: a GROUP BY over orrery_jobs, and a discovery whose number of
     * statements grows with the rows instead of the tuples.
     */
    public function test_discovery_costs_one_seek_per_tuple(): void
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $this->seedTuples($connection);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements, $connection): void {
            if ($query->connection !== $connection) {
                return;
            }

            if (str_contains($query->sql, 'orrery_jobs') && str_contains($query->sql, 'limit 1') && ! str_contains($query->sql, 'finished_at')) {
                $statements[] = $query->sql;
            }
        });
        $sqls = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$sqls, $connection): void {
            if ($query->connection !== $connection) {
                return;
            }

            $sqls[] = strtolower($query->sql);
        });

        Retention::useLimitsForTesting(seconds: 3600);
        $this->app->make(Retention::class)->run();

        // 10 queues × (completed, failed, pending) = 30 tuples, plus the seek that finds none.
        self::assertCount(31, $statements);
        self::assertSame([], array_values(array_filter($sqls, static fn (string $sql): bool => str_contains($sql, 'group by'))));
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{0: list<string>, 1: bool} the indexes that read orrery_jobs, and whether every read has a range bound
     */
    private function plan(Connection $connection, string $sql, array $bindings): array
    {
        $table = $connection->getTablePrefix().'orrery_jobs';

        return match ($connection->getDriverName()) {
            'mariadb' => self::markTestSkipped('The plan checks read the EXPLAIN output of MySQL; MariaDB has another one (EXPLAIN FORMAT=JSON and ANALYZE differ), so no plan check runs on MariaDB.'),
            'sqlite' => $this->sqlitePlan($connection, $sql, $bindings, $table),
            'pgsql' => $this->pgsqlPlan($connection, $sql, $bindings, $table),
            default => $this->mysqlPlan($connection, $sql, $bindings, $table),
        };
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{0: list<string>, 1: bool}
     */
    private function sqlitePlan(Connection $connection, string $sql, array $bindings, string $table): array
    {
        $used = [];
        $range = true;

        foreach ($connection->select('EXPLAIN QUERY PLAN '.$sql, $bindings) as $row) {
            $detail = (string) $row->detail;

            if (preg_match('/^(SEARCH|SCAN) '.preg_quote($table, '/').'\b/', $detail, $kind) !== 1) {
                continue;
            }

            $used[] = preg_match('/USING (?:COVERING )?INDEX (\S+)/', $detail, $match) === 1 ? $match[1] : '(none)';
            // SEARCH with a constraint on the index columns: a seek, not a scan.
            $range = $range && $kind[1] === 'SEARCH' && str_contains($detail, '(connection');
        }

        return [$used, $range];
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{0: list<string>, 1: bool}
     */
    private function mysqlPlan(Connection $connection, string $sql, array $bindings, string $table): array
    {
        $used = [];
        $range = true;

        foreach ($connection->select('EXPLAIN '.$sql, $bindings) as $row) {
            if ((string) ($row->table ?? '') === $table) {
                $used[] = is_string($row->key ?? null) ? $row->key : '(none)';
                $range = $range && ($row->type ?? null) === 'range';
            }
        }

        return [$used, $range];
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array{0: list<string>, 1: bool}
     */
    private function pgsqlPlan(Connection $connection, string $sql, array $bindings, string $table): array
    {
        $plan = $connection->transaction(static function (Connection $tx) use ($sql, $bindings): string {
            $tx->statement('SET LOCAL enable_seqscan = off');
            $tx->statement('SET LOCAL enable_bitmapscan = off');

            return (string) array_values((array) $tx->selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings))[0];
        });
        $used = [];
        $range = true;
        $walk = static function (array $node) use (&$walk, &$used, &$range, $table): void {
            if (($node['Relation Name'] ?? null) === $table) {
                $used[] = is_string($node['Index Name'] ?? null) ? $node['Index Name'] : '(none)';
                $range = $range && str_contains((string) ($node['Index Cond'] ?? ''), 'connection');
            }

            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };

        foreach ((array) json_decode($plan, true) as $root) {
            $walk($root['Plan']);
        }

        return [$used, $range];
    }

    /**
     * 10 queues × 3 statuses × 100 rows, then ANALYZE.
     */
    private function seedTuples(Connection $connection): void
    {
        $rows = [];
        $id = 0;
        $now = Clock::now();

        for ($queue = 0; $queue < 10; $queue++) {
            foreach (['completed', 'failed', 'pending'] as $status) {
                for ($i = 0; $i < 100; $i++) {
                    $id++;
                    $rows[] = [
                        'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                        'connection' => 'database',
                        'queue' => 'q'.$queue,
                        'name' => 'App\\Jobs\\Retained',
                        'type' => 'job',
                        'status' => $status,
                        'silenced' => false,
                        'monitored' => false,
                        'payload' => '{}',
                        'payload_bytes' => 2,
                        'pushed_at' => Clock::toDecimal($now - 60),
                        'finished_at' => $status === 'pending' ? null : Clock::toDecimal($now - 60),
                        'updated_at' => Clock::toDecimal($now - 60),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        $table = $connection->getTablePrefix().'orrery_jobs';
        $connection->statement(in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? "ANALYZE TABLE `{$table}`" : 'ANALYZE '.($connection->getDriverName() === 'sqlite' ? '' : '"'.$table.'"'));
    }
}
