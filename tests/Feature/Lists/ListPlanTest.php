<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lists;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The plans of the list engine (section 11 "List engine", D-11),
 * on a 50,000-row fixture with mixed silenced and monitored values, two
 * queues, a sparse tag (10,000 members, 5 failures) and a busy tag on the
 * other 40,000 rows.
 *
 * For every access path of the 4.3 table, first and second page, each
 * page read and each count of the request is explained on the run's engine:
 *
 * - SQLite: EXPLAIN QUERY PLAN: the declared index, and no `USE TEMP
 *   B-TREE` (no sort). SQLite asserts the plan shape only.
 * - MySQL: EXPLAIN FORMAT=JSON: the declared key, no filesort and no
 *   temporary table (a count materializes its LIMIT subquery); and the
 *   rows that the statement examines: the actual rows of each access of
 *   the table in EXPLAIN ANALYZE.
 * - Postgres: EXPLAIN (ANALYZE, FORMAT JSON): the declared index, no Sort
 *   node, and the actual rows of the index node (returned and removed by
 *   a filter). Postgres has no index hint: on the reads next to a range
 *   end (a page back, the has_newer seeks, the residual chunks and their
 *   probes) its planner may choose another index or sort a range that it
 *   estimates small (PG 18 also skip-scans cq_pushed); there only an index
 *   access and the examined-row bound are asserted. The deep runs (a page
 *   back and a has_newer seek from a cursor about 1,000 rows into the
 *   range) make that bound real: a sort there reads the 1,000 rows. On
 *   strict reads PG 18's `Index Searches` must be 1 (no skip scan).
 *
 * The bounds on MySQL and Postgres: a page read examines at most
 * per_page + 1 rows (plus EDGE for the keyset's boundary rows), a count at
 * most cap + 1 per range.
 *
 * FreshSchema: ANALYZE writes planner statistics (sqlite_stat1 on SQLite).
 */
#[FreshSchema]
final class ListPlanTest extends TestCase
{
    use ExplainsListPlans;
    use ListFixtures;

    private const ROWS = 50_000;

    private const PER_PAGE = 50;

    /** The keyset's boundary rows that a bound may add (MySQL reads the row at the cursor key again). */
    private const EDGE = 2;

    /**
     * The requests and the index of each page read (table, suffix): one
     * per access path.
     *
     * @return array<string, array{0: array<string, string>, 1: string, 2: string, 3?: int}>
     */
    private static function paths(): array
    {
        $cq = ['connection' => 'database', 'queue' => 'emails'];

        return [
            'pending' => [['status' => 'pending'], 'orrery_jobs', 'st_pushed'],
            'pending, queue' => [['status' => 'pending'] + $cq, 'orrery_jobs', 'cq_pushed'],
            'running' => [['status' => 'running'], 'orrery_jobs', 'st_reserved'],
            'running, queue' => [['status' => 'running'] + $cq, 'orrery_jobs', 'cq_reserved'],
            'completed' => [['status' => 'completed'], 'orrery_jobs', 'st_sil_finished'],
            'completed, queue' => [['status' => 'completed'] + $cq, 'orrery_jobs', 'retention'],
            'silenced' => [['status' => 'silenced'], 'orrery_jobs', 'st_sil_finished'],
            'silenced, queue' => [['status' => 'silenced'] + $cq, 'orrery_jobs', 'retention'],
            'failed' => [['status' => 'failed'], 'orrery_jobs', 'st_failed'],
            'failed, queue' => [['status' => 'failed'] + $cq, 'orrery_jobs', 'retention'],
            'retrying' => [['status' => 'retrying'], 'orrery_jobs', 'st_failed'],
            'retrying, queue' => [['status' => 'retrying'] + $cq, 'orrery_jobs', 'retention'],
            'unknown' => [['status' => 'unknown'], 'orrery_jobs', 'st_finished'],
            'unknown, queue' => [['status' => 'unknown'] + $cq, 'orrery_jobs', 'retention'],
            'all + tag' => [['status' => 'all', 'tag' => 'sparse'], 'orrery_job_tags', 'tag_pushed'],
            'failed + tag' => [['status' => 'failed', 'tag' => 'sparse'], 'orrery_failed_tags', 'tag_finished'],
            'failed + batch' => [['status' => 'failed', 'batch' => 'batch-x'], 'orrery_jobs', 'batch'],
            'pending + batch' => [['status' => 'pending', 'batch' => 'batch-x'], 'orrery_jobs', 'batch'],
            // The null group of a batch with 1,000 pending members, counted with a cap of 101.
            'pending + big batch' => [['status' => 'pending', 'batch' => 'batch-p'], 'orrery_jobs', 'batch', 101],
            // An ascending time range: the keyset and the from bound are both lower bounds.
            'completed, queue, asc, from' => [['status' => 'completed', 'order' => 'asc', 'from' => (string) (self::BASE + 1000), 'to' => (string) (self::BASE + 40000)] + $cq, 'orrery_jobs', 'retention'],
            'failed, asc, from' => [['status' => 'failed', 'order' => 'asc', 'from' => (string) (self::BASE + 1000)], 'orrery_jobs', 'st_failed'],
        ];
    }

    /**
     * Rejects: a list read that the planner serves with another index, a
     * sort or a temporary table (an in-memory sort of the range), a keyset
     * page that walks the range from its start (a row-value comparison that
     * MySQL cannot range-scan, or an OR that Postgres applies as a filter),
     * and a count that reads past cap + 1 index entries per range.
     *
     * Per path: the first page, the next page (an `after` cursor), the page
     * before it (a `before` cursor, read in the reverse index direction),
     * the has_newer seek (newer_than, one LIMIT 1 seek per range), and a
     * residual scan (a class filter; its chunk reads). Each read may examine
     * at most its LIMIT plus EDGE rows.
     */
    public function test_every_access_path_reads_its_index_in_order_within_the_bounds(): void
    {
        $this->seedFixture();
        Orrery::auth(static fn (): bool => true);
        $mirror = $this->mirror();
        $this->analyze($mirror);
        $config = $this->app['config'];
        $problems = [];
        $checked = 0;
        $kinds = [];

        foreach (self::paths() as $name => $path) {
            [$query, $table, $suffix] = $path;
            $cap = $path[3] ?? 10_000;
            $config->set('orrery.dashboard.list_count_cap', $cap);
            $config->set('orrery.dashboard.search_candidates', 50_000);
            $query += ['per_page' => (string) self::PER_PAGE];
            $first = $this->statementsOf($query);
            $runs = ['page 1' => $first];

            if ($first['next_cursor'] !== null) {
                $runs['page 2'] = $second = $this->statementsOf($query + ['cursor' => $first['next_cursor']]);

                if ($second['prev_cursor'] !== null) {
                    $runs['page back'] = $this->statementsOf($query + ['cursor' => $second['prev_cursor']]);
                }
            }

            if ($first['head'] !== null) {
                $runs['has_newer'] = $this->statementsOf($query + ['newer_than' => $first['head']]);
            }

            // A cursor about 1,000 rows into the range: a page back from there, and a has_newer seek from there,
            // must read their page or their one row in index order, not sort the 1,000 rows before it.
            $deep = $first['next_cursor'];

            for ($step = 0; $deep !== null && $step < 10; $step++) {
                $walk = $this->statementsOf(['per_page' => '100'] + $query + ['cursor' => $deep], false);

                if ($walk['next_cursor'] === null) {
                    break;
                }

                $deep = $walk['next_cursor'];
            }

            if ($deep !== null) {
                $page = $this->statementsOf($query + ['cursor' => $deep], false);

                if ($page['prev_cursor'] !== null) {
                    $runs['deep page back'] = $this->statementsOf($query + ['cursor' => $page['prev_cursor']]);
                }

                $runs['deep has_newer'] = $this->statementsOf($query + ['newer_than' => $deep]);
            }

            $config->set('orrery.dashboard.search_candidates', 1_000);
            $runs['residual'] = $this->statementsOf($query + ['class' => 'App\\Jobs\\Sync']);

            foreach ($runs as $run => $statements) {
                $reads = array_filter($statements['sql'], static fn (array $statement): bool => str_contains($statement[0], 'order by') && str_contains($statement[0], $table) && ! str_contains($statement[0], 'capped_rows'));
                $counts = array_filter($statements['sql'], static fn (array $statement): bool => str_contains($statement[0], 'capped_rows'));

                if ($reads === []) {
                    $problems[] = "{$name}, {$run}: no read of {$table}";
                }

                foreach ($reads as [$sql, $bindings]) {
                    $checked++;
                    $limit = preg_match('/limit (\d+)/', $sql, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;
                    $kinds[$limit === 1 ? 'seek' : ($limit > self::PER_PAGE + 1 ? 'chunk' : 'page')] = true;

                    // Postgres has no index hint: on the reads near a range end (back, the seeks, the residual
                    // chunks) it may pick another index or sort a small range; the bound still holds.
                    $strict = $mirror->getDriverName() !== 'pgsql' || in_array($run, ['page 1', 'page 2'], true);

                    foreach ($this->problems($mirror, $sql, $bindings, $table, IndexName::for($mirror, $table, $suffix), $limit + self::EDGE, $strict) as $problem) {
                        $problems[] = "{$name}, {$run}, read (limit {$limit}): {$problem}";
                    }
                }

                foreach ($counts as [$sql, $bindings]) {
                    $checked++;
                    $kinds['count'] = true;

                    foreach ($this->problems($mirror, $sql, $bindings, $table, null, $cap + 1 + self::EDGE) as $problem) {
                        $problems[] = "{$name}, {$run}, count: {$problem}";
                    }
                }
            }
        }

        self::assertSame([], $problems, "The plans of {$checked} statements:\n".implode("\n", $problems));
        self::assertSame(['chunk', 'count', 'page', 'seek'], self::sorted(array_keys($kinds)), 'every kind of read was checked');
        self::assertGreaterThan(100, $checked);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * The fixture: 50,000 rows over every status (87% completed, 7% failed,
     * 2% pending, 2% unknown, 1% running, 1% retrying), two queues, mixed
     * silenced and monitored; the tag `sparse` on 10,000 rows, 5 of them failed; the
     * batch `batch-x` with pending and failed members.
     */
    private function seedFixture(): void
    {
        $failedSparse = 0;

        for ($n = 1; $n <= self::ROWS; $n++) {
            // A mirror as it usually is: mostly completed rows, a few percent of each other status.
            $status = match (true) {
                $n % 100 < 2 => 'pending',
                $n % 100 < 3 => 'reserved',
                $n % 100 < 10 => 'failed',
                $n % 100 < 11 => 'retrying',
                $n % 100 < 13 => 'unknown',
                default => 'completed',
            };
            $values = [
                'queue' => $n % 3 === 0 ? 'emails' : 'default',
                'silenced' => $status === 'completed' ? $n % 5 === 0 : $n % 7 === 0,
                'monitored' => $n % 4 === 0,
            ];

            if ($n % 97 === 0) {
                $values['batch_id'] = 'batch-x';
            } elseif ($status === 'pending') {
                // 1,000 pending members of one batch: its null group.
                $values['batch_id'] = 'batch-p';
            }

            $sparse = $n % 5 === 1 && $n <= self::ROWS;

            if ($sparse && $status === 'failed') {
                if ($failedSparse >= 5) {
                    $status = 'completed';
                } else {
                    $failedSparse++;
                    $this->addTag($n, 'sparse', $n, true);
                }
            }

            $this->addRow($n, $status, $n, $values);

            if ($sparse) {
                $this->addTag($n, 'sparse', $n);
            } else {
                // The busy tag: the sparse one is a small part of the tag tables.
                $this->addTag($n, 'common', $n);

                if ($status === 'failed') {
                    $this->addTag($n, 'common', $n, true);
                }
            }

            if ($n % 5000 === 0) {
                $this->flushRows();
            }
        }

        $this->flushRows();
    }

    /**
     * The statements of one list request on the mirror, with bindings, and
     * the response's cursors.
     *
     * @param  array<string, string>  $query
     * @return array{sql: list<array{0: string, 1: array<int, mixed>}>, next_cursor: string|null, prev_cursor: string|null, head: string|null}
     */
    private function statementsOf(array $query, bool $record = true): array
    {
        if (! $record) {
            $response = $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->assertOk();
            $next = $response->json('next_cursor');
            $prev = $response->json('prev_cursor');

            return ['sql' => [], 'next_cursor' => is_string($next) ? $next : null, 'prev_cursor' => is_string($prev) ? $prev : null, 'head' => null];
        }

        $statements = [];
        $listener = static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = [strtolower($event->sql), $event->bindings];
        };
        $this->app['events']->listen(QueryExecuted::class, $listener);
        $response = $this->actingAs(new User)->getJson('/orrery/api/jobs?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->assertOk();
        $this->app['events']->forget(QueryExecuted::class);
        $string = static fn (mixed $value): ?string => is_string($value) ? $value : null;

        return ['sql' => $statements, 'next_cursor' => $string($response->json('next_cursor')), 'prev_cursor' => $string($response->json('prev_cursor')), 'head' => $string($response->json('head'))];
    }
}
