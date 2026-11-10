<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lists;

use Illuminate\Database\Connection;

/**
 * The plan checks of the list reads,
 * per engine, for one statement with its bindings:
 *
 * - SQLite: EXPLAIN QUERY PLAN: the declared index, no `USE TEMP B-TREE`
 *   (no sort) and no table scan. SQLite checks the plan shape only.
 * - MySQL: EXPLAIN FORMAT=JSON: the declared key, no filesort and no
 *   temporary table for a page read (a count materializes its LIMIT
 *   subquery), no table scan; and the rows that the statement examines:
 *   the actual rows of each access of the table in EXPLAIN ANALYZE.
 * - Postgres: EXPLAIN (ANALYZE, FORMAT JSON): the declared index, no Sort
 *   node in a page read, and the actual rows of the index node (returned
 *   and removed by a filter).
 *
 * - Postgres 18 also: an index scan with more than one `Index Searches` is
 *   a skip scan, a problem for a page read.
 *
 * $index null is a count: any index, a sort allowed. $bound is the most
 * rows that the statement may examine (MySQL and Postgres). $strict false
 * (Postgres) allows another index, a sort and a skip scan, and keeps the
 * bound: for a read whose plan the planner may choose freely.
 */
trait ExplainsListPlans
{
    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function problems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound, bool $strict = true): array
    {
        return match ($connection->getDriverName()) {
            'sqlite' => $this->sqliteProblems($connection, $sql, $bindings, $connection->getTablePrefix().$table, $index),
            'pgsql' => $this->pgsqlProblems($connection, $sql, $bindings, $connection->getTablePrefix().$table, $index, $bound, $strict),
            default => $this->mysqlProblems($connection, $sql, $bindings, $connection->getTablePrefix().$table, $index, $bound),
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

        foreach ($details as $detail) {
            if (str_contains($detail, 'USE TEMP B-TREE')) {
                $problems[] = "a sort ({$detail})";
            }

            if (preg_match('/^SCAN '.preg_quote($table, '/').'(?! USING)/', $detail) === 1) {
                $problems[] = "a table scan ({$detail})";
            }
        }

        if ($index !== null) {
            $uses = array_filter($details, static fn (string $detail): bool => preg_match('/'.preg_quote($table, '/').' USING (?:COVERING )?INDEX '.preg_quote($index, '/').'\b/', $detail) === 1);

            if ($uses === []) {
                $problems[] = "not the index {$index}: ".implode(' | ', $details);
            }
        }

        return $problems;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<string>
     */
    private function mysqlProblems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound): array
    {
        $problems = [];
        $json = (string) array_values((array) $connection->selectOne('EXPLAIN FORMAT=JSON '.$sql, $bindings))[0];

        // A count materializes its LIMIT subquery (a temporary table of at most cap + 1 rows); only a page read must not.
        if ($index !== null && (str_contains($json, '"using_filesort": true') || str_contains($json, '"using_temporary_table": true'))) {
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

        // The rows that the statement examined: the actual rows of each access of the table in EXPLAIN ANALYZE.
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
    private function pgsqlProblems(Connection $connection, string $sql, array $bindings, string $table, ?string $index, int $bound, bool $strict): array
    {
        $plan = (string) array_values((array) $connection->selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql, $bindings))[0];
        $problems = [];
        $reads = [];
        // A page read must not sort; a count may sort the at most cap + 1 rows that it read.
        $page = $index !== null && $strict;
        $walk = static function (array $node) use (&$walk, &$reads, &$problems, $table, $page): void {
            if ($page && (($node['Node Type'] ?? null) === 'Sort' || ($node['Node Type'] ?? null) === 'Incremental Sort')) {
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
                    // PG 18: more than one search of a plain index scan is a skip scan.
                    'searches' => isset($node['Index Searches']) ? (int) $node['Index Searches'] : null,
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
            } elseif ($strict && $index !== null && $read['index'] !== $index) {
                $problems[] = "the index {$read['index']}, not {$index}";
            }

            if ($strict && $index !== null && $read['searches'] !== null && $read['searches'] > 1) {
                $problems[] = "{$read['searches']} index searches (a skip scan)";
            }

            if ($read['rows'] > $bound) {
                $problems[] = "examined {$read['rows']} rows, more than {$bound}";
            }
        }

        return $problems;
    }
}
