<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Tags;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Http\Tags;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The plans of the tags API reads on the run's
 * engine, with the per-engine tools of the list engine's plan test (D-11):
 *
 * - the monitored list (`ORDER BY tag LIMIT 101`): the tag key, no sort,
 *   at most 101 rows examined;
 * - the lookup of one tag: the tag key, at most 2 rows examined;
 * - the capped counts of a busy tag (3,000 rows, 1,500 failed) and a
 *   sparse one (200 rows, 5 failed): `tag_pushed` and `tag_finished` (the
 *   hinted index on SQLite and MySQL; any index on Postgres), at most
 *   COUNT_CAP + 1 rows examined each.
 *
 * SQLite asserts the plan shape only (EXPLAIN QUERY PLAN); MySQL asserts
 * the key, no filesort or temporary table for a non-count read, and the
 * rows of EXPLAIN ANALYZE; Postgres asserts the index, no Sort node for a
 * non-count read, and the actual rows of EXPLAIN (ANALYZE, FORMAT JSON).
 *
 * FreshSchema: ANALYZE writes planner statistics (sqlite_stat1 on SQLite).
 */
#[FreshSchema]
final class TagsPlanTest extends TestCase
{
    /** The keyset's boundary rows that a bound may add. */
    private const EDGE = 2;

    /** Monitored tags besides the two measured ones: more than the planners would sort in memory. */
    private const FILLER_TAGS = 2_000;

    /**
     * Rejects: a monitored list that sorts the whole monitored table or
     * reads it past the 101st row, a lookup that scans, and a count that
     * reads another index (the primary key in tag, job_uuid order, which
     * the planner then sorts), or reads past COUNT_CAP + 1 rows.
     */
    public function test_the_tag_reads_use_their_index_within_the_bounds(): void
    {
        $this->seedTags();
        Orrery::auth(static fn (): bool => true);
        $mirror = $this->mirror();
        $this->analyze($mirror);

        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = [strtolower($event->sql), $event->bindings];
        });
        $response = $this->actingAs(new User)->getJson('/orrery/api/tags?tag=busy')->assertOk();
        $this->app['events']->forget(QueryExecuted::class);

        self::assertSame(['count' => 1000, 'at_least' => true], $response->json('tags.0.count'), 'busy');
        self::assertSame(['count' => 1000, 'at_least' => true], $response->json('tags.0.failed'), 'busy');
        self::assertSame(['count' => 200, 'at_least' => false], $response->json('tags.1.count'), 'sparse');
        self::assertSame(['count' => 5, 'at_least' => false], $response->json('tags.1.failed'), 'sparse');
        self::assertTrue($response->json('truncated'));
        self::assertSame(['tag' => 'busy', 'monitored' => true], $response->json('lookup'));

        $prefix = $mirror->getTablePrefix();
        $monitored = $prefix.'orrery_monitored_tags';
        $primary = match ($mirror->getDriverName()) {
            'mysql', 'mariadb' => 'PRIMARY',
            // Postgres names a primary key <table>_pkey whatever name the schema gives it.
            'pgsql' => (string) $mirror->selectOne('select i.relname as name from pg_index x join pg_class i on i.oid = x.indexrelid where x.indrelid = ?::regclass and x.indisprimary', [$monitored])->name,
            default => null,
        };
        $problems = [];
        $checked = ['list' => 0, 'lookup' => 0, 'count' => 0];

        foreach ($statements as [$sql, $bindings]) {
            if (str_contains($sql, 'orrery_monitored_tags') && str_contains($sql, 'order by')) {
                $checked['list']++;
                $found = $this->problems($mirror, $sql, $bindings, $monitored, $primary, Tags::MAX_TAGS + 1 + self::EDGE, false);
            } elseif (str_contains($sql, 'orrery_monitored_tags') && in_array('busy', $bindings, true)) {
                $checked['lookup']++;
                $found = $this->problems($mirror, $sql, $bindings, $monitored, $primary, 2 + self::EDGE, false);
            } elseif (str_contains($sql, 'capped_rows') && array_intersect(['busy', 'sparse'], $bindings) !== []) {
                $checked['count']++;
                [$table, $suffix] = str_contains($sql, 'orrery_failed_tags') ? ['orrery_failed_tags', 'tag_finished'] : ['orrery_job_tags', 'tag_pushed'];
                // Postgres has no index hint: for a small tag it may read the primary key (tag, job_uuid) and sort
                // those rows; the bound on the rows still holds. SQLite and MySQL read the hinted index.
                $index = $mirror->getDriverName() === 'pgsql' ? null : IndexName::for($mirror, $table, $suffix);
                $found = $this->problems($mirror, $sql, $bindings, $prefix.$table, $index, Tags::COUNT_CAP + 1 + self::EDGE, true);
            } else {
                continue;
            }

            foreach ($found as $problem) {
                $problems[] = $problem.' in '.$sql;
            }
        }

        self::assertSame(['list' => 1, 'lookup' => 1, 'count' => 4], $checked);
        self::assertSame([], $problems, implode("\n", $problems));
    }

    private function seedTags(): void
    {
        $mirror = $this->mirror();
        $now = Clock::toDecimal(Clock::now());
        $monitored = [['tag' => 'busy', 'created_at' => $now], ['tag' => 'sparse', 'created_at' => $now]];

        for ($n = 1; $n <= self::FILLER_TAGS; $n++) {
            $monitored[] = ['tag' => sprintf('z-%05d', $n), 'created_at' => $now];
        }

        $jobTags = [];
        $failedTags = [];

        for ($n = 1; $n <= 3_000; $n++) {
            $jobTags[] = ['tag' => 'busy', 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'pushed_at' => Clock::toDecimal(1_790_000_000 + $n)];

            if ($n % 2 === 0) {
                $failedTags[] = ['tag' => 'busy', 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'finished_at' => Clock::toDecimal(1_790_000_000 + $n)];
            }

            if ($n % 15 === 0) {
                $jobTags[] = ['tag' => 'sparse', 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'pushed_at' => Clock::toDecimal(1_790_000_000 + $n)];
            }

            if ($n % 600 === 0) {
                $failedTags[] = ['tag' => 'sparse', 'job_uuid' => sprintf('00000000-0000-4000-8000-%012d', $n), 'finished_at' => Clock::toDecimal(1_790_000_000 + $n)];
            }
        }

        foreach (['orrery_monitored_tags' => $monitored, 'orrery_job_tags' => $jobTags, 'orrery_failed_tags' => $failedTags] as $table => $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                $mirror->table($table)->insert($chunk);
            }
        }
    }

    private function analyze(Connection $connection): void
    {
        $prefix = $connection->getTablePrefix();

        foreach (['orrery_monitored_tags', 'orrery_job_tags', 'orrery_failed_tags'] as $table) {
            match ($connection->getDriverName()) {
                'pgsql' => $connection->statement('ANALYZE "'.$prefix.$table.'"'),
                'sqlite' => $connection->statement('ANALYZE "'.$prefix.$table.'"'),
                default => $connection->statement('ANALYZE TABLE `'.$prefix.$table.'`'),
            };
        }
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function problems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound, bool $count): array
    {
        return match ($connection->getDriverName()) {
            'sqlite' => $this->sqliteProblems($connection, $sql, $bindings, $table, $index),
            'pgsql' => $this->pgsqlProblems($connection, $sql, $bindings, $table, $index, $bound, $count),
            default => $this->mysqlProblems($connection, $sql, $bindings, $table, $index, $bound, $count),
        };
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function sqliteProblems(Connection $connection, string $sql, array $bindings, string $table, ?string $index): array
    {
        $problems = [];
        $details = array_map(static fn (object $row): string => (string) $row->detail, $connection->select('EXPLAIN QUERY PLAN '.$sql, $bindings));
        $reads = array_filter($details, static fn (string $detail): bool => preg_match('/^(?:SCAN|SEARCH) '.preg_quote($table, '/').'\b/', $detail) === 1);

        foreach ($details as $detail) {
            if (str_contains($detail, 'USE TEMP B-TREE')) {
                $problems[] = "a sort ({$detail})";
            }
        }

        if ($reads === []) {
            $problems[] = "no read of {$table}: ".implode(' | ', $details);
        }

        foreach ($reads as $read) {
            $pattern = $index === null ? '/ USING (?:COVERING )?INDEX \S+/' : '/ USING (?:COVERING )?INDEX '.preg_quote($index, '/').'\b/';

            if (preg_match($pattern, $read) !== 1) {
                $problems[] = 'not '.($index ?? 'an index').": {$read}";
            }
        }

        return $problems;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function mysqlProblems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound, bool $count): array
    {
        $problems = [];
        $json = (string) array_values((array) $connection->selectOne('EXPLAIN FORMAT=JSON '.$sql, $bindings))[0];

        // A count materializes its LIMIT subquery (at most cap + 1 rows); the other reads must not sort.
        if (! $count && (str_contains($json, '"using_filesort": true') || str_contains($json, '"using_temporary_table": true'))) {
            $problems[] = 'a filesort or a temporary table';
        }

        $keys = [];
        $walk = static function (mixed $node) use (&$walk, &$keys, $table): void {
            if (! is_array($node)) {
                return;
            }

            if (($node['table_name'] ?? null) === $table) {
                $keys[] = ['key' => $node['key'] ?? null, 'access' => $node['access_type'] ?? null];

                return;
            }

            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk(json_decode($json, true));

        if ($keys === []) {
            $problems[] = "no read of {$table}";
        }

        foreach ($keys as $key) {
            if ($key['access'] === 'ALL' || $key['key'] === null) {
                $problems[] = "a table scan of {$table}";
            } elseif ($index !== null && $key['key'] !== $index) {
                $problems[] = "the key {$key['key']}, not {$index}";
            }
        }

        $tree = (string) array_values((array) $connection->selectOne('EXPLAIN ANALYZE '.$sql, $bindings))[0];
        $examined = 0;

        foreach (explode("\n", $tree) as $line) {
            if (preg_match('/ on '.preg_quote($table, '/').'\b/', $line) === 1 && preg_match_all('/rows=([\d.]+) loops=(\d+)/', $line, $matches) > 0) {
                $last = count($matches[1]) - 1;
                $examined += (int) round((float) $matches[1][$last] * (int) $matches[2][$last]);
            }
        }

        if ($examined > $bound) {
            $problems[] = "examined {$examined} rows, more than {$bound}";
        }

        return $problems;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function pgsqlProblems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound, bool $count): array
    {
        $plan = (string) array_values((array) $connection->selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql, $bindings))[0];
        $problems = [];
        $reads = [];
        $walk = static function (array $node) use (&$walk, &$reads, &$problems, $table, $count): void {
            if (! $count && in_array($node['Node Type'] ?? null, ['Sort', 'Incremental Sort'], true)) {
                $problems[] = 'a Sort node';
            }

            if (($node['Relation Name'] ?? null) === $table) {
                $bitmapIndex = null;

                foreach ($node['Plans'] ?? [] as $child) {
                    if (($child['Node Type'] ?? null) === 'Bitmap Index Scan') {
                        $bitmapIndex = $child['Index Name'] ?? null;
                    }
                }

                $reads[] = [
                    'index' => $node['Index Name'] ?? $bitmapIndex,
                    'type' => $node['Node Type'] ?? null,
                    'rows' => (int) ($node['Actual Rows'] ?? 0) * max(1, (int) ($node['Actual Loops'] ?? 1))
                        + (int) ($node['Rows Removed by Filter'] ?? 0) + (int) ($node['Rows Removed by Index Recheck'] ?? 0),
                ];
            }

            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };

        foreach ((array) json_decode($plan, true) as $root) {
            $walk($root['Plan']);
        }

        if ($reads === []) {
            $problems[] = "no read of {$table}";
        }

        foreach ($reads as $read) {
            if ($read['index'] === null) {
                $problems[] = "a {$read['type']} of {$table} without an index";
            } elseif ($index !== null && $read['index'] !== $index) {
                $problems[] = "the index {$read['index']}, not {$index}";
            }

            if ($read['rows'] > $bound) {
                $problems[] = "examined {$read['rows']} rows, more than {$bound}";
            }
        }

        return $problems;
    }
}
