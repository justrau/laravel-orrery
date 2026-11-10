<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Failures;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Failures\FailureScope;
use Justrau\Orrery\Failures\GroupKey;
use Justrau\Orrery\Failures\KeyedFailures;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The plans of the per-key reads: the
 * figures seek on `failure` (no queue scope) or
 * `failure_scope` (a queue scope), never on st_failed, which would walk
 * every failed row of the window. SQLite: EXPLAIN QUERY PLAN; MySQL:
 * EXPLAIN; Postgres: EXPLAIN (FORMAT JSON), with sequential and bitmap
 * scans turned off for the statement, so the test checks the choice
 * between the indexes, not a table scan of a small test table.
 *
 * FreshSchema: ANALYZE writes planner statistics (on SQLite the table
 * sqlite_stat1).
 */
#[FreshSchema]
final class FailurePlanTest extends TestCase
{
    private const NOW = 1_789_999_200.0;

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function reads(): array
    {
        return [
            'figures, all queues' => [null],
            'figures, one queue' => ['database:emails'],
        ];
    }

    /**
     * Rejects: a per-key read that the planner serves from st_failed (the
     * queue-scoped reads before the `failure_scope` index), or from no
     * index.
     */
    #[DataProvider('reads')]
    public function test_per_key_reads_seek_on_a_failure_index(?string $queue): void
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $this->seedFailures($connection);
        $keys = [self::key(0), self::key(1)];
        $scope = FailureScope::from($queue, self::NOW - 3600, self::NOW);
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements, $connection): void {
            if ($query->connection !== $connection) {
                return;
            }

            $statements[] = [$query->sql, $query->bindings];
        });

        KeyedFailures::stats($scope, $keys, true);

        $keyed = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'failure_key')));
        self::assertCount(1, $keyed, 'one keyed statement');
        self::assertSame([], $keyed[0][1], 'the per-key statements carry literals, not bindings');

        $wanted = [IndexName::for($connection, 'orrery_jobs', $queue === null ? 'failure' : 'failure_scope'), IndexName::for($connection, 'orrery_jobs', 'failure')];
        $used = $this->indexes($connection, $keyed[0][0]);

        self::assertNotSame([], $used, 'the plan reads orrery_jobs');
        self::assertNotContains(IndexName::for($connection, 'orrery_jobs', 'st_failed'), $used);

        foreach ($used as $index) {
            self::assertContains($index, $wanted, 'every read of orrery_jobs seeks on a failure index: '.json_encode($used));
        }

        if ($queue !== null) {
            self::assertContains($wanted[0], $used, 'a queue-scoped read seeks on failure_scope');
        }
    }

    /**
     * The index of each read of orrery_jobs in the plan of $sql; null
     * (written as '(none)') for a read without an index.
     *
     * @return list<string>
     */
    private function indexes(Connection $connection, string $sql): array
    {
        $table = $connection->getTablePrefix().'orrery_jobs';

        return match ($connection->getDriverName()) {
            'sqlite' => $this->sqlitePlan($connection, $sql, $table),
            'pgsql' => $this->pgsqlPlan($connection, $sql, $table),
            default => $this->mysqlPlan($connection, $sql, $table),
        };
    }

    /**
     * @return list<string>
     */
    private function sqlitePlan(Connection $connection, string $sql, string $table): array
    {
        $used = [];

        foreach ($connection->select('EXPLAIN QUERY PLAN '.$sql) as $row) {
            $detail = (string) $row->detail;

            if (preg_match('/^(SEARCH|SCAN) '.preg_quote($table, '/').'\b/', $detail) !== 1) {
                continue;
            }

            $used[] = preg_match('/USING (?:COVERING )?INDEX (\S+)/', $detail, $match) === 1 ? $match[1] : '(none)';
        }

        return $used;
    }

    /**
     * @return list<string>
     */
    private function mysqlPlan(Connection $connection, string $sql, string $table): array
    {
        $used = [];

        foreach ($connection->select('EXPLAIN '.$sql) as $row) {
            if ((string) ($row->table ?? '') === $table) {
                $used[] = is_string($row->key ?? null) ? $row->key : '(none)';
            }
        }

        return $used;
    }

    /**
     * @return list<string>
     */
    private function pgsqlPlan(Connection $connection, string $sql, string $table): array
    {
        $plan = $connection->transaction(static function (Connection $tx) use ($sql): string {
            $tx->statement('SET LOCAL enable_seqscan = off');
            $tx->statement('SET LOCAL enable_bitmapscan = off');

            return (string) array_values((array) $tx->selectOne('EXPLAIN (FORMAT JSON) '.$sql))[0];
        });
        $used = [];
        $walk = static function (array $node) use (&$walk, &$used, $table): void {
            if (($node['Relation Name'] ?? null) === $table) {
                $used[] = is_string($node['Index Name'] ?? null) ? $node['Index Name'] : '(none)';
            }

            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };

        foreach ((array) json_decode($plan, true) as $root) {
            $walk($root['Plan']);
        }

        return $used;
    }

    /**
     * 4 keys on 2 queues, 150 failures each, and 2,000 completed rows, then ANALYZE.
     */
    private function seedFailures(Connection $connection): void
    {
        $rows = [];
        $id = 0;

        foreach (range(0, 3) as $key) {
            foreach (['default', 'emails'] as $queue) {
                for ($i = 0; $i < 150; $i++) {
                    $rows[] = $this->row(++$id, 'failed', $queue, self::NOW - 3000 + $i * 10, 'Message '.$key);
                }
            }
        }

        for ($i = 0; $i < 2000; $i++) {
            $rows[] = $this->row(++$id, 'completed', 'default', self::NOW - 3000 + $i, null);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->mirror()->table('orrery_jobs')->insert($chunk);
        }

        $table = $connection->getTablePrefix().'orrery_jobs';
        $connection->statement($connection->getDriverName() === 'mysql' ? "ANALYZE TABLE `{$table}`" : 'ANALYZE '.($connection->getDriverName() === 'sqlite' ? '' : '"'.$table.'"'));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $status, string $queue, float $at, ?string $message): array
    {
        return [
            'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'connection' => 'database',
            'queue' => $queue,
            'name' => 'App\\Jobs\\Sync',
            'type' => 'job',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'occurrence_id' => $id,
            'exception_class' => $message === null ? null : 'RuntimeException',
            'exception_message' => $message,
            'failure_key' => $message === null ? null : GroupKey::of('RuntimeException', $message, 'App\\Jobs\\Sync'),
            'attempts' => 1,
            'pushed_at' => Clock::toDecimal($at - 5),
            'finished_at' => Clock::toDecimal($at),
            'failed_at' => $status === 'failed' ? Clock::toDecimal($at) : null,
            'updated_at' => Clock::toDecimal($at),
        ];
    }

    private static function key(int $key): string
    {
        return GroupKey::of('RuntimeException', 'Message '.$key, 'App\\Jobs\\Sync');
    }
}
