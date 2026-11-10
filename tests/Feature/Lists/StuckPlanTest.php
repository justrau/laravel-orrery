<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Lists;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Orrery;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;

/**
 * The plans of the stuck reads,
 * on a 50,000-row fixture: mostly finished rows, many
 * of them with large attempts, releases and gaps (so a read that does not
 * lead with the status examines them), and 3% pending or running rows with
 * a mix of gaps, attempts, releases, limits and reservation ages.
 *
 * For each signal, and its list of one queue, the first and the second
 * page and a `before` cursor read (the reverse index direction): each page
 * read and each count is explained on the run's engine
 * (ExplainsListPlans). The bounds on MySQL and Postgres: a read of an
 * index-only list (the gap and release lists, the expiry list of one
 * queue) examines at most per_page + 1 rows, a read of a list with a
 * residual predicate at most JobList::CHUNK rows, plus EDGE; a count at
 * most cap + 1 per range.
 *
 * FreshSchema: ANALYZE writes planner statistics (sqlite_stat1 on SQLite).
 */
#[FreshSchema]
final class StuckPlanTest extends TestCase
{
    use ExplainsListPlans;
    use ListFixtures;

    private const ROWS = 50_000;

    private const PER_PAGE = 50;

    /** The keyset's boundary rows that a bound may add (MySQL reads the row at the cursor key again). */
    private const EDGE = 2;

    /**
     * The requests, the index of each page read, and whether the list is
     * index-only.
     *
     * @return array<string, array{0: array<string, string>, 1: string, 2: bool}>
     */
    private static function paths(): array
    {
        return [
            'reservation expired' => [['signal' => 'reservation_expired'], 'st_reserved', false],
            'reservation expired, queue' => [['signal' => 'reservation_expired', 'connection' => 'database', 'queue' => 'emails'], 'cq_reserved', true],
            'outcome not observed' => [['signal' => 'outcome_not_observed'], 'st_gap', true],
            'outcome not observed, queue' => [['signal' => 'outcome_not_observed', 'connection' => 'database', 'queue' => 'emails'], 'st_gap', false],
            'repeated releases' => [['signal' => 'repeated_releases'], 'st_releases', true],
            'repeated releases, queue' => [['signal' => 'repeated_releases', 'connection' => 'database', 'queue' => 'emails'], 'st_releases', false],
            'attempts not capped' => [['signal' => 'attempts_not_capped'], 'st_attempts', false],
        ];
    }

    /**
     * Rejects: a stuck read that the planner serves with another index (a
     * status-only index, or an index without the status first, which reads
     * the finished rows with large counters), a sort or a temporary table,
     * a keyset page that walks its range from the start, a page read that
     * examines more than its chunk, and a count past cap + 1 per range.
     */
    public function test_every_stuck_read_uses_its_index_in_order_within_the_bounds(): void
    {
        $this->app['config']->set('queue.connections.database.retry_after', 90);
        $this->seedFixture();
        Orrery::auth(static fn (): bool => true);
        $mirror = $this->mirror();
        $this->analyze($mirror);
        $problems = [];
        $checked = 0;
        $listed = 0;
        $backwards = 0;

        foreach (self::paths() as $name => [$query, $suffix, $indexOnly]) {
            $cursor = null;
            $bound = ($indexOnly ? self::PER_PAGE + 1 : JobList::CHUNK) + self::EDGE;

            // Page 2 follows page 1's next_cursor; then a `before` cursor (page 2's prev_cursor) reads in the reverse index direction.
            foreach (['page 1', 'page 2', 'before page 2'] as $page) {
                $statements = $this->statementsOf($query + ['per_page' => (string) self::PER_PAGE] + ($cursor === null ? [] : ['cursor' => $cursor]));
                $cursor = $page === 'page 1' ? $statements['next_cursor'] : $statements['prev_cursor'];
                $backwards += $page === 'before page 2' ? 1 : 0;
                $listed += $statements['rows'];
                $reads = array_filter($statements['sql'], static fn (array $statement): bool => str_contains($statement[0], 'order by') && ! str_contains($statement[0], 'capped_rows') && self::readsJobs($statement[0]) && ! str_contains($statement[0], ' in ('));
                $counts = array_filter($statements['sql'], static fn (array $statement): bool => str_contains($statement[0], 'capped_rows'));

                if ($reads === []) {
                    $problems[] = "{$name}, {$page}: no page read";
                }

                if ($indexOnly !== ($counts !== []) && $page === 'page 1') {
                    $problems[] = "{$name}, {$page}: ".($indexOnly ? 'no capped count' : 'a capped count on a residual list');
                }

                foreach ($reads as [$sql, $bindings]) {
                    $checked++;

                    foreach ($this->problems($mirror, $sql, $bindings, 'orrery_jobs', IndexName::for($mirror, 'orrery_jobs', $suffix), $bound) as $problem) {
                        $problems[] = "{$name}, {$page}, page read: {$problem}";
                    }
                }

                foreach ($counts as [$sql, $bindings]) {
                    $checked++;

                    foreach ($this->problems($mirror, $sql, $bindings, 'orrery_jobs', null, 10_000 + 1 + self::EDGE) as $problem) {
                        $problems[] = "{$name}, {$page}, count: {$problem}";
                    }
                }

                if ($cursor === null || $page === 'before page 2') {
                    break;
                }
            }
        }

        self::assertSame([], $problems, "The plans of {$checked} statements:\n".implode("\n", $problems));
        self::assertGreaterThan(12, $checked);
        self::assertGreaterThan(300, $listed, 'the lists hold rows');
        self::assertGreaterThanOrEqual(4, $backwards, 'a before cursor read of most paths');
    }

    /**
     * 50,000 rows: 87% completed, 7% failed, 3% unknown, 2% pending, 1%
     * running, two queues. Every 4th row has large counters (attempts 20
     * to 44, releases up to its attempts, a gap of 1 to 3), whatever its
     * status. Running rows were reserved 0 to 599 seconds ago.
     */
    private function seedFixture(): void
    {
        $now = Clock::now();

        for ($n = 1; $n <= self::ROWS; $n++) {
            $status = match (true) {
                $n % 100 < 2 => 'pending',
                $n % 100 < 3 => 'reserved',
                $n % 100 < 10 => 'failed',
                $n % 100 < 13 => 'unknown',
                default => 'completed',
            };
            $busy = $n % 4 === 0;
            $attempts = $busy ? 20 + $n % 25 : 1 + $n % 3;
            $values = [
                'queue' => $n % 3 === 0 ? 'emails' : 'default',
                'attempts' => $attempts,
                'releases' => $busy && $n % 8 === 0 ? $attempts : intdiv($attempts, 3),
                'observed_gap' => $busy ? 1 + $n % 3 : 0,
                'last_outcome_attempt' => $attempts,
                'max_tries' => $n % 5 === 0 ? 0 : ($n % 5 === 1 ? 50 : null),
            ];

            if ($status === 'reserved') {
                $values['reserved_at'] = Clock::toDecimal($now - ($n % 600));
            }

            $this->addRow($n, $status, $n, $values);

            if ($n % 5000 === 0) {
                $this->flushRows();
            }
        }

        $this->flushRows();
    }

    /**
     * Whether a statement reads orrery_jobs (not the index catalog): its
     * FROM names the table, with the prefix, and on a pooled Postgres
     * session with its schema ("public"."orrery_jobs").
     */
    private static function readsJobs(string $sql): bool
    {
        return preg_match('/from (?:["`]?\\w+["`]?\\.)?["`]?\\w*orrery_jobs["`]? /', $sql) === 1;
    }

    /**
     * The statements of one stuck list request on the mirror, with
     * bindings, the response's next cursor and its row count.
     *
     * @param  array<string, string>  $query
     * @return array{sql: list<array{0: string, 1: array<int, mixed>}>, next_cursor: string|null, prev_cursor: string|null, rows: int}
     */
    private function statementsOf(array $query): array
    {
        $statements = [];
        $listener = static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = [strtolower($event->sql), $event->bindings];
        };
        $this->app['events']->listen(QueryExecuted::class, $listener);
        $response = $this->actingAs(new User)->getJson('/orrery/api/jobs/stuck?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))->assertOk();
        $this->app['events']->forget(QueryExecuted::class);

        $next = $response->json('next_cursor');
        $prev = $response->json('prev_cursor');

        return ['sql' => $statements, 'next_cursor' => is_string($next) ? $next : null, 'prev_cursor' => is_string($prev) ? $prev : null, 'rows' => count((array) $response->json('jobs'))];
    }
}
