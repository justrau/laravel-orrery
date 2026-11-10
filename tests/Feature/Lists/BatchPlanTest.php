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
 * The plans of the batch member reads:
 * the member counts of `GET /api/batches/{id}` and the member pages
 * of `GET /api/jobs?batch=<id>&status=<tab>`, on a 20,000-row fixture with
 * one batch of 1,600 members over every status, explained on the run's
 * engine by ExplainsListPlans.
 *
 * - Each count of an index-only tab reads the `batch` index range of its
 *   status and examines at most cap + 1 rows (plus the keyset edge), with
 *   the list engine's smallest cap (101). The completed and silenced tabs
 *   count by a residual scan of the same range (the silenced flag is not in
 *   the index), in chunks bounded by their LIMIT.
 * - Each member page reads the `batch` index in order without a sort, at
 *   most per_page + 1 rows (ListPlanTest checks the failed and pending
 *   pages with their cursors; here every tab's first page). The completed
 *   tab filters `silenced` after the batch source in residual chunks; on
 *   Postgres a chunk may sort its small range, and only the index access
 *   and the row bound are asserted there.
 *
 * FreshSchema: ANALYZE writes planner statistics (sqlite_stat1 on SQLite).
 */
#[FreshSchema]
final class BatchPlanTest extends TestCase
{
    use ListFixtures;
    use ExplainsListPlans;

    private const ROWS = 20_000;

    private const BATCH = 'batch-plan';

    private const PER_PAGE = 50;

    private const CAP = 101;

    /** The keyset's boundary rows that a bound may add. */
    private const EDGE = 2;

    /** The members of the batch per mirror status (none is silenced). */
    private const MEMBERS = ['pending' => 400, 'reserved' => 200, 'completed' => 400, 'failed' => 300, 'retrying' => 150, 'unknown' => 150];

    /** The members of the batch per member tab of the show. */
    private const TABS = ['pending' => 400, 'running' => 200, 'completed' => 400, 'silenced' => 0, 'failed' => 300, 'retrying' => 150, 'unknown' => 150];

    /**
     * Rejects: a member count that scans the batch's other statuses or the
     * whole table (an index on batch_id alone, a count without the status
     * equality, or one without its LIMIT), a count that reads past cap + 1
     * index entries, and a member page that the planner sorts or serves
     * from another index.
     */
    public function test_the_batch_member_reads_use_the_batch_index_within_the_bounds(): void
    {
        $this->seedFixture();
        Orrery::auth(static fn (): bool => true);
        $mirror = $this->mirror();
        $this->analyze($mirror);
        $this->app['config']->set('orrery.dashboard.list_count_cap', self::CAP);
        $this->app['db']->connection((string) config('queue.batching.database'))->table((string) config('queue.batching.table'))->insert([
            'id' => self::BATCH, 'name' => 'Plan', 'total_jobs' => 1600, 'pending_jobs' => 600, 'failed_jobs' => 300,
            'failed_job_ids' => '[]', 'options' => null, 'created_at' => 1_790_000_000, 'cancelled_at' => null, 'finished_at' => null,
        ]);
        $problems = [];

        [$statements, $response] = $this->statements('/orrery/api/batches/'.self::BATCH);
        $counts = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'capped_rows')));
        // The completed and silenced tabs filter `silenced` after the batch source: residual chunk reads.
        $chunks = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'order by') && str_contains($statement[0], 'orrery_jobs') && ! str_contains($statement[0], 'capped_rows')));

        foreach (self::TABS as $tab => $members) {
            self::assertSame(['count' => min($members, self::CAP), 'at_least' => $members > self::CAP], $response['members'][$tab] ?? null, $tab);
        }

        self::assertCount(5, $counts, 'one capped count per index-only tab');
        self::assertNotEmpty($chunks, 'the residual tabs scan');

        foreach ($counts as $n => [$sql, $bindings]) {
            foreach ($this->problems($mirror, $sql, $bindings, 'orrery_jobs', null, self::CAP + 1 + self::EDGE) as $problem) {
                $problems[] = "count {$n}: {$problem}";
            }

            if ($mirror->getDriverName() !== 'pgsql') {
                self::assertStringContainsString(IndexName::for($mirror, 'orrery_jobs', 'batch'), $sql, 'the count forces the batch index');
            }
        }

        foreach ($chunks as $n => [$sql, $bindings]) {
            $limit = preg_match('/limit (\d+)/', $sql, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;

            foreach ($this->problems($mirror, $sql, $bindings, 'orrery_jobs', IndexName::for($mirror, 'orrery_jobs', 'batch'), $limit + self::EDGE, $mirror->getDriverName() !== 'pgsql') as $problem) {
                $problems[] = "residual count read {$n} (limit {$limit}): {$problem}";
            }
        }

        foreach (['pending', 'running', 'completed', 'failed', 'retrying', 'unknown'] as $tab) {
            [$statements] = $this->statements('/orrery/api/jobs?'.http_build_query(['status' => $tab, 'batch' => self::BATCH, 'per_page' => self::PER_PAGE]));
            $reads = array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'order by') && str_contains($statement[0], 'orrery_jobs') && ! str_contains($statement[0], 'capped_rows'));

            if ($reads === []) {
                $problems[] = "{$tab}: no page read";
            }

            foreach ($reads as [$sql, $bindings]) {
                $limit = preg_match('/limit (\d+)/', $sql, $match) === 1 ? (int) $match[1] : PHP_INT_MAX;
                // As in ListPlanTest: Postgres has no index hint, and may sort the small range of a residual
                // chunk (the completed tab's silenced filter); there only an index access and the bound hold.
                $strict = $mirror->getDriverName() !== 'pgsql' || $limit <= self::PER_PAGE + 1;

                foreach ($this->problems($mirror, $sql, $bindings, 'orrery_jobs', IndexName::for($mirror, 'orrery_jobs', 'batch'), $limit + self::EDGE, $strict) as $problem) {
                    $problems[] = "{$tab}, page read (limit {$limit}): {$problem}";
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * 20,000 rows: the batch's 1,600 members, the other rows of every status
     * (mostly completed) without a batch or in other batches.
     */
    private function seedFixture(): void
    {
        $n = 0;

        foreach (self::MEMBERS as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $this->addRow($n, $status, $n, ['batch_id' => self::BATCH]);
            }
        }

        while ($n < self::ROWS) {
            $n++;
            $status = match (true) {
                $n % 100 < 3 => 'pending',
                $n % 100 < 4 => 'reserved',
                $n % 100 < 10 => 'failed',
                $n % 100 < 11 => 'retrying',
                $n % 100 < 12 => 'unknown',
                default => 'completed',
            };
            $this->addRow($n, $status, $n, $n % 10 === 0 ? ['batch_id' => 'batch-'.($n % 50)] : []);

            if ($n % 5000 === 0) {
                $this->flushRows();
            }
        }

        $this->flushRows();
    }

    /**
     * The statements of one request on the mirror, with bindings, and the
     * response's JSON.
     *
     * @return array{0: list<array{0: string, 1: array<int, mixed>}>, 1: array<string, mixed>}
     */
    private function statements(string $uri): array
    {
        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = [strtolower($event->sql), $event->bindings];
        });
        $response = $this->actingAs(new User)->getJson($uri)->assertOk();
        $this->app['events']->forget(QueryExecuted::class);

        return [$statements, (array) $response->json()];
    }
}
