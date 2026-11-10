<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Justrau\Orrery\Database\Models\MonitoredTag;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * GET /api/counts: the totals of the Jobs list
 * tabs (pending, running, completed, silenced, failed, retrying, unknown) as
 * `{count, at_least}`, capped by orrery.dashboard.count_cap, and the number
 * of monitored tags. A failed read gives `count: null`, never 0.
 */
final class CountsControllerTest extends ControllerTest
{
    /**
     * Rejects: an uncapped count, 0 on error, and a pending count that still
     * counts the reserved rows (`pending` is status pending only, and
     * `running` counts the reserved rows).
     */
    public function test_counts_cap_and_report_unknown(): void
    {
        $this->app['config']->set('orrery.dashboard.count_cap', 5);

        for ($i = 0; $i < 7; $i++) {
            $this->row('pending');
        }

        for ($i = 0; $i < 3; $i++) {
            $this->row('reserved', ['reserved_at' => Clock::toDecimal(Clock::now())]);
        }

        $this->row('retrying', ['failed_at' => Clock::toDecimal(Clock::now()), 'finished_at' => Clock::toDecimal(Clock::now())]);
        $this->row('unknown', ['finished_at' => Clock::toDecimal(Clock::now())]);
        $this->row('completed', ['finished_at' => Clock::toDecimal(Clock::now()), 'silenced' => true]);

        $this->row('failed');
        $limits = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$limits): void {
            if (str_contains($event->sql, 'orrery_jobs')) {
                $limits[] = preg_match('/limit (\d+)/i', $event->sql, $match) === 1 ? (int) $match[1] : null;
            }
        });

        $this->actingAs(new User)->getJson('/orrery/api/counts')
            ->assertOk()
            ->assertExactJson([
                'pending' => ['count' => 5, 'at_least' => true],
                'running' => ['count' => 3, 'at_least' => false],
                'completed' => ['count' => 0, 'at_least' => false],
                'silenced' => ['count' => 1, 'at_least' => false],
                'failed' => ['count' => 1, 'at_least' => false],
                'retrying' => ['count' => 1, 'at_least' => false],
                'unknown' => ['count' => 1, 'at_least' => false],
                'monitored_tags' => 0,
            ]);

        self::assertSame([6, 6, 6, 6, 6, 6, 6], $limits, 'each count reads at most cap + 1 rows');

        Exceptions::fake();
        Watcher::failOperationsForTesting(['http:counts_pending', 'http:counts_monitored_tags']);

        $this->actingAs(new User)->getJson('/orrery/api/counts')
            ->assertOk()
            ->assertExactJson([
                'pending' => ['count' => null, 'at_least' => false],
                'running' => ['count' => 3, 'at_least' => false],
                'completed' => ['count' => 0, 'at_least' => false],
                'silenced' => ['count' => 1, 'at_least' => false],
                'failed' => ['count' => 1, 'at_least' => false],
                'retrying' => ['count' => 1, 'at_least' => false],
                'unknown' => ['count' => 1, 'at_least' => false],
                'monitored_tags' => null,
            ]);

        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'http:counts_pending');
    }

    /**
     * A count of exactly the cap is exact; a count above it is "at least".
     *
     * Rejects: at_least on a count that equals the cap.
     */
    /**
     * Rejects: counts that go on after a statement timeout, so one request holds a PHP worker for one timeout per
     * count: after the first timeout the other counts and the monitored tags are unknown without a read.
     */
    public function test_counts_stop_after_a_statement_timeout(): void
    {
        $statements = 0;
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            if (! str_contains($event->sql, 'orrery_jobs') && ! str_contains($event->sql, 'orrery_monitored_tags')) {
                return;
            }

            if (++$statements === 1) {
                $timeout = new \PDOException('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout');
                $timeout->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];

                throw new QueryException('testbench', $event->sql, [], $timeout);
            }
        });

        $body = $this->actingAs(new User)->getJson('/orrery/api/counts')->assertOk()->json();

        self::assertSame(1, $statements, 'No statement after the timeout.');

        foreach (['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown'] as $list) {
            self::assertSame(['count' => null, 'at_least' => false], $body[$list], $list);
        }

        self::assertNull($body['monitored_tags']);
    }

    public function test_a_count_at_the_cap_is_exact(): void
    {
        $this->app['config']->set('orrery.dashboard.count_cap', 3);

        for ($i = 0; $i < 3; $i++) {
            $this->row('completed', ['finished_at' => Clock::toDecimal(Clock::now())]);
        }

        // Silenced rows are not in the completed list.
        $this->row('completed', ['finished_at' => Clock::toDecimal(Clock::now()), 'silenced' => true]);

        $this->actingAs(new User)->getJson('/orrery/api/counts')
            ->assertOk()
            ->assertJsonPath('completed', ['count' => 3, 'at_least' => false]);
    }

    /**
     * Rejects: a per-tag count, or a statement that selects a payload or
     * reads more rows than the cap allows.
     */
    public function test_counts_read_no_payload_and_count_the_monitored_tags(): void
    {
        $this->app['config']->set('orrery.dashboard.count_cap', 10);
        MonitoredTag::query()->create(['tag' => 'orders', 'created_at' => microtime(true)]);
        MonitoredTag::query()->create(['tag' => 'shipments', 'created_at' => microtime(true)]);
        $this->row('failed');

        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$statements): void {
            $statements[] = [$event->sql, $event->bindings];
        });

        $this->actingAs(new User)->getJson('/orrery/api/counts')
            ->assertOk()
            ->assertJsonPath('monitored_tags', 2)
            ->assertJsonPath('failed', ['count' => 1, 'at_least' => false]);

        $jobs = array_values(array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'orrery_jobs')));
        self::assertCount(7, $jobs, 'one statement per tab');

        foreach ($jobs as [$sql, $bindings]) {
            self::assertStringNotContainsString('payload', strtolower($sql));
            self::assertStringNotContainsString('tags', strtolower($sql));
            self::assertMatchesRegularExpression('/limit (11|\?)/i', $sql, 'the subquery reads at most cap + 1 rows');

            if (! str_contains(strtolower($sql), 'limit 11')) {
                self::assertContains(11, $bindings);
            }
        }

        self::assertCount(0, array_filter($statements, static fn (array $statement): bool => str_contains($statement[0], 'orrery_job_tags') || str_contains($statement[0], 'orrery_failed_tags')), 'no count per tag');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function row(string $status, array $values = []): void
    {
        $now = Clock::toDecimal(Clock::now());
        $this->mirror()->table('orrery_jobs')->insert(array_merge([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'status' => $status,
            'payload' => '{}',
            'payload_bytes' => 2,
            'pushed_at' => $now,
            'updated_at' => $now,
        ], $status === 'failed' ? ['failed_at' => $now, 'finished_at' => $now] : [], $values));
    }
}
