<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\QueryException;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Http\Controllers\HealthController;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\HealthEvents;
use Justrau\Orrery\Runtime\ProcessContext;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Retry\FenceFixtures;
use Justrau\Orrery\Tests\Support\MirrorSession;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PDOException;

/**
 * GET /api/health: the reported degradation
 * windows that overlap the requested range, the tick liveness, the queue
 * connections without a fence table with the command that
 * fixes them, and the fixed notice.
 */
final class HealthControllerTest extends ControllerTest
{
    use FenceFixtures;

    private const NOTICE = 'Unreported gaps cannot be ruled out.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Rejects: dropping open windows from a range.
     */
    public function test_windows_overlapping_the_range_with_open_ends(): void
    {
        $this->window('before', 100, 150);
        $this->window('overlaps-start', 150, 250);
        $this->window('open-before', 120, null, ['last_error_operation' => 'mirror:push', 'last_error_at' => 130.5]);
        $this->window('inside', 300, 400, ['dropped' => 4]);
        $this->window('open-inside', 450.25, null);
        $this->window('after', 600, 700);

        $response = $this->actingAs(new User)->getJson('/orrery/api/health?from=200&to=500')->assertOk();

        self::assertSame(['open-before', 'overlaps-start', 'inside', 'open-inside'], array_column((array) $response->json('windows'), 'process'));
        self::assertSame(self::NOTICE, $response->json('notice'));

        $open = $response->json('windows.0');
        self::assertSame([
            'context' => 'worker',
            'process' => 'open-before',
            'started_at' => 120.0,
            'ended_at' => null,
            'end_label' => 'end unknown',
            'dropped' => 0,
            'unmatched_observations' => 0,
            'unverified' => 0,
            'rollback_unverified' => 0,
            'last_error_operation' => 'mirror:push',
            'last_error_at' => 130.5,
        ], $open);

        $closed = $response->json('windows.2');
        self::assertSame([300.0, 400.0, null, 4], [$closed['started_at'], $closed['ended_at'], $closed['end_label'], $closed['dropped']]);
    }

    /**
     * Rejects: date strings, or no default range.
     */
    public function test_range_parameters_are_epoch_seconds_with_defaults(): void
    {
        $now = 1_800_000_000.0;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));
        self::assertSame($now, Clock::now());

        // The default range is the last 86,400 seconds before the request.
        $this->window('ended-a-day-ago', $now - 90_000, $now - 86_401);
        $this->window('ended-inside-the-day', $now - 90_000, $now - 86_399);
        $this->window('starts-later', $now + 10, null);

        $default = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['ended-inside-the-day'], array_column((array) $default->json('windows'), 'process'));

        // Decimal epoch seconds are exact bounds.
        $this->window('ends-at-1000.75', 900, 1_000.75);
        $this->window('ends-at-1000.25', 900, 1_000.25);
        $decimal = $this->actingAs(new User)->getJson('/orrery/api/health?from=1000.5&to=1100')->assertOk();
        self::assertSame(['ends-at-1000.75'], array_column((array) $decimal->json('windows'), 'process'));

        // Only `from`: the range ends at the request.
        $from = $this->actingAs(new User)->getJson('/orrery/api/health?from='.($now - 100_000))->assertOk();
        self::assertSame(['ended-a-day-ago', 'ended-inside-the-day'], array_column((array) $from->json('windows'), 'process'));

        foreach (['from=2026-09-26', 'to=yesterday', 'from=12abc', 'from=-5', 'from=500&to=400'] as $query) {
            $this->actingAs(new User)->getJson('/orrery/api/health?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('error', 'invalid_range');
        }
    }

    /**
     * Rejects: a health answer without the request time: the SPA then
     * keeps a browser clock that runs fast whenever the failure-group read
     * fails, and a fresh tick reads "Tick is late".
     * Still passes: an `as_of` from another clock than Clock::now() that
     * happens to equal it under the test clock.
     */
    public function test_health_answers_its_request_time(): void
    {
        $now = 1_800_000_000.25;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));

        self::assertSame($now, $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('as_of'));
    }

    /**
     * Rejects: a default `from` of `to` - 86,400 s, which moves the range away from the request time.
     */
    public function test_only_to_keeps_the_default_start_at_the_request_time(): void
    {
        $now = 1_800_000_000.0;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));

        $this->window('ended-two-days-ago', $now - 200_000, $now - 180_000);
        $this->window('ended-an-hour-ago', $now - 7_200, $now - 3_600);

        // `to` before the default start (the request time - 86,400 s): the range is empty and reversed.
        $this->actingAs(new User)->getJson('/orrery/api/health?to='.($now - 100_000))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_range');

        // `to` inside the default range: the start is still 86,400 s before the request.
        $inside = $this->actingAs(new User)->getJson('/orrery/api/health?to='.($now - 1_800))->assertOk();
        self::assertSame(['ended-an-hour-ago'], array_column((array) $inside->json('windows'), 'process'));
    }

    /**
     * Rejects: a response without `observer_tick_at` or the fence report.
     */
    public function test_tick_liveness_and_missing_fence_tables(): void
    {
        // The primary database has its fence table, as orrery:install --fence and migrate give it.
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));
        $before = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['observer_tick_at' => null, 'state' => 'none'], $before->json('tick'));
        self::assertSame([], $before->json('missing_fence_tables'));

        $this->mirror()->table('orrery_settings')->insert(['key' => 'observer_tick_at', 'value' => Clock::toDecimal(1_750_000_000.125)]);
        $this->defineBareConnection();
        $this->useQueue('health-bare', 'testbench_bare');
        $this->useQueue('health-same', TestEnvironment::PRIMARY);

        $after = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['observer_tick_at' => 1_750_000_000.125, 'state' => 'ok'], $after->json('tick'));
        self::assertSame(['health-bare'], $after->json('missing_fence_tables'));
    }

    /**
     * Gate fix 4: queue connections whose queue objects share one jobs table
     * with different retry_after values. A job that a worker of the longer
     * one holds becomes ready for a worker of the shorter one after its
     * shorter retry_after, so it can run twice. The hint names the
     * connections, the table and each retry_after; the backlog math stays.
     *
     * Rejects: no hint for the hazard, a hint for aliases with one
     * retry_after or for connections on different tables, and values that
     * do not follow the connections' order. It still passes a hint that
     * compares the table names only, not the database connections, when two
     * databases have a `jobs` table with the same base connection name.
     */
    public function test_health_names_a_shared_jobs_table_with_different_retry_after(): void
    {
        $queue = static fn (string $table, int $retryAfter): array => ['driver' => 'database', 'connection' => TestEnvironment::PRIMARY, 'table' => $table, 'queue' => 'default', 'retry_after' => $retryAfter, 'after_commit' => false];
        $config = $this->app['config'];
        $connections = (array) $config->get('queue.connections');
        // The harness's `database` connection: the jobs table with retry_after 90.
        self::assertSame(['jobs', 90], [$connections['database']['table'], $connections['database']['retry_after']]);
        $connections['shared-long'] = $queue('jobs', 900);
        $connections['shared-same'] = $queue('jobs', 90);
        $connections['other-table'] = $queue('other_jobs', 30);
        $connections['other-table-alias'] = $queue('other_jobs', 30);
        $config->set('queue.connections', $connections);

        $response = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();

        self::assertSame([
            ['connections' => ['database', 'shared-long', 'shared-same'], 'table' => 'jobs', 'values' => [90, 900, 90]],
        ], $response->json('shared_jobs_table_retry_after'));

        unset($connections['shared-long']);
        $config->set('queue.connections', $connections);

        self::assertSame([], $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('shared_jobs_table_retry_after'));
    }

    /**
     * Rejects: a health response that shows neither which package sessions
     * are pooled nor a name in orrery.sessions.pooled that names no Postgres
     * connection (a mistyped name is ignored in silence). It
     * would still pass a field that misses a connection pooled by Laravel's
     * own `direct` key; the second connection asserts it.
     */
    public function test_health_names_the_pooled_sessions_and_unknown_pooled_names(): void
    {
        $pg = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'x', 'username' => 'x', 'password' => '', 'prefix' => ''];
        $config = $this->app['config'];
        $config->set('database.connections.pg_listed', $pg);
        $config->set('database.connections.pg_direct', $pg + ['direct' => ['host' => '127.0.0.1']]);
        $config->set('database.connections.pg_plain', $pg);
        // The harness's own pooled connections (ORRERY_TEST_PG_POOLED) stay pooled.
        $harness = \Justrau\Orrery\Storage\PackageSession::pooledSetting($config->get('orrery.sessions.pooled', false));
        $config->set('orrery.sessions.pooled', $harness === true ? true : [...$harness, 'pg_listed', 'pg_lsited', 'pg_listed']);

        $json = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('pooled_sessions');
        $own = static fn (array $names): array => array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'pg_')));

        self::assertSame($harness === true ? ['pg_direct', 'pg_listed', 'pg_plain'] : ['pg_direct', 'pg_listed'], $own($json['pooled']));
        self::assertSame($harness === true ? [] : ['pg_lsited'], $json['unknown']);
    }

    /**
     * Review fix (low): the adapter comes from the container and the
     * configuration, not from the mirror, so a skipped mirror read (an open
     * breaker) leaves the read-only state known; only the unprovable count
     * is unknown.
     *
     * Rejects: the whole `failed_records` field null while the breaker is
     * open (the SPA then shows Retry and Forget again), and an unprovable
     * count of 0 for a skipped read. It still passes an adapter whose
     * proof of id reuse is read again on each request.
     */
    public function test_read_only_adapter_survives_a_skipped_mirror_read(): void
    {
        $this->app->instance('queue.failer', new FileFailedJobProvider(sys_get_temp_dir().'/orrery-health-no-failures'));
        $watcher = $this->app->make(Watcher::class);
        $watcher->run('test:lost', static function (): never {
            throw new QueryException(MirrorSession::name(), 'select 1', [], new class extends PDOException
            {
                public function __construct()
                {
                    parent::__construct('SQLSTATE[08006]: connection failure');
                    $this->code = '08006';
                    $this->errorInfo = ['08006', 7, 'connection failure'];
                }
            });
        });
        self::assertTrue($watcher->breakerOpen());

        $records = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('failed_records');

        self::assertIsArray($records);
        self::assertSame(['read_only', 'provider_read_only', null], [$records['adapter'], $records['reason'], $records['unprovable_failures']]);
        self::assertStringContainsString(FileFailedJobProvider::class, (string) $records['message']);
    }

    /**
     * Rejects: a missing fence table without the command that writes its
     * migration,
     * and a hint while every table is present.
     */
    public function test_health_names_the_fence_command(): void
    {
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));
        $present = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame([], $present->json('missing_fence_tables'));
        self::assertNull($present->json('fence_hint'));

        $this->defineBareConnection();
        $this->useQueue('health-bare', 'testbench_bare');

        $missing = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(['health-bare'], $missing->json('missing_fence_tables'));
        self::assertSame(HealthController::FENCE_HINT, $missing->json('fence_hint'));
        self::assertStringContainsString('php artisan orrery:install --fence', (string) $missing->json('fence_hint'));
    }

    /**
     * Rejects: a report that mixes two kinds of names (review nit): the
     * failed records of database-uuids are on a database without the fence
     * table, so every queue connection on another database (a retry in mode
     * before) is refused, and the report names those queue connections, not
     * the failed-record database connection.
     */
    public function test_fence_report_names_queue_connections_for_the_failed_record_database(): void
    {
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));
        $this->defineBareConnection();
        $this->useQueue('health-same', TestEnvironment::PRIMARY);
        $this->app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testbench_bare', 'table' => 'failed_jobs']);
        $queues = array_keys(array_filter((array) $this->app['config']->get('queue.connections'), static fn (mixed $connection): bool => is_array($connection) && ($connection['driver'] ?? null) === 'database'));
        sort($queues);

        $missing = (array) $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk()->json('missing_fence_tables');
        sort($missing);

        self::assertNotContains('testbench_bare', $missing, 'a database connection name is not a queue connection');
        self::assertSame($queues, $missing, 'every queue connection in mode before');
    }

    /**
     * Defines testbench_bare: a queue database without the fence table, as
     * an application database before orrery:install --fence. On a SQLite
     * file it is a new file of its own, never <file>.mirror, and it is
     * opened once in the harness's journal mode, as the application opens
     * its database. An empty file that nothing opened has no WAL mode yet,
     * so its fence is unknown (D18), not missing. On a server it is
     * <base>_mirror (in separate mode under a prefix of its own,
     * FenceFixtures::bareOtherDatabase()), and in memory a second in-memory
     * database.
     */
    private function defineBareConnection(): void
    {
        $environment = $this->testEnvironment();

        if ($environment->inMemory()) {
            $this->copyConnection('testbench_bare', ['database' => TestEnvironment::MEMORY]);

            return;
        }

        if ($environment->isServer()) {
            $this->copyConnection('testbench_bare', $this->bareOtherDatabase());

            return;
        }

        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(function () use ($file): void {
            PackageSession::releaseAll();
            $this->app['db']->purge('testbench_bare');
            SqliteFiles::remove($file);
        });

        $this->copyConnection('testbench_bare', ['database' => $file]);
        $bare = $this->app['db']->connection('testbench_bare');
        self::assertSame([], $bare->getSchemaBuilder()->getTableListing(), 'The bare database has no tables.');
        self::assertSame(
            strtolower((string) $this->app['db']->connection(TestEnvironment::PRIMARY)->selectOne('PRAGMA journal_mode')->journal_mode),
            strtolower((string) $bare->selectOne('PRAGMA journal_mode')->journal_mode),
            'The bare database has the journal mode of the primary file.',
        );
    }

    /**
     * Rejects: a response without a bound, because open windows are never pruned.
     */
    public function test_windows_are_bounded_to_the_newest_with_a_truncated_flag(): void
    {
        $limit = HealthController::MAX_WINDOWS;
        $rows = [];

        // Open windows that every range overlaps: one more than the limit.
        for ($i = 0; $i <= $limit; $i++) {
            $rows[] = $this->windowRow('open-'.$i, 1_000.0 + $i, null);
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->mirror()->table(HealthEvents::TABLE)->insert($chunk);
        }

        $response = $this->actingAs(new User)->getJson('/orrery/api/health?from=0&to=5000')->assertOk();
        $windows = (array) $response->json('windows');

        self::assertTrue($response->json('truncated'));
        self::assertCount($limit, $windows);
        self::assertSame('open-1', $windows[0]['process'], 'the oldest window is left out');
        self::assertSame('open-'.$limit, $windows[$limit - 1]['process'], 'the newest windows stay, oldest first');

        $narrow = $this->actingAs(new User)->getJson('/orrery/api/health?from=0&to=1001')->assertOk();
        self::assertFalse($narrow->json('truncated'));
        self::assertSame(['open-0', 'open-1'], array_column((array) $narrow->json('windows'), 'process'));
    }

    /**
     * Rejects: one fence probe per queue connection name on every request.
     */
    public function test_fence_report_checks_each_database_once_and_caches_a_present_table(): void
    {
        $this->createQueueTables($this->app['db']->connection(TestEnvironment::PRIMARY));
        $this->useQueue('health-a', TestEnvironment::PRIMARY);
        $this->useQueue('health-b', TestEnvironment::PRIMARY);
        $this->useQueue('health-c', TestEnvironment::PRIMARY);

        $probes = 0;
        $inMemory = $this->testEnvironment()->inMemory();
        $count = static function (string $sql, array $bindings) use (&$probes): void {
            if (str_contains($sql.' '.json_encode($bindings), Fence::TABLE)) {
                $probes++;
            }
        };

        if ($inMemory) {
            // In-memory SQLite: every package session is the application connection.
            $this->app['db']->listen(static fn ($query) => $count($query->sql, $query->bindings));
        } else {
            PackageSession::recordForTesting(static function (string $kind, string $sql, ?string $session, $connection, array $bindings) use ($count): void {
                // One entry per statement: an `executed` entry ends the same statement.
                if ($kind === PackageSession::STATEMENT) {
                    $count($sql, $bindings);
                }
            });
        }

        try {
            $first = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
            self::assertSame([], $first->json('missing_fence_tables'));
            self::assertSame(1, $probes, 'the four queue connections share one database: one probe');

            $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
            self::assertSame(1, $probes, 'a fence answer is kept for '.Fence::AVAILABILITY_SECONDS.' s');
        } finally {
            PackageSession::recordForTesting(null);
        }
    }

    /**
     * Rejects: listing only windows with an error, and a rollback count
     * folded into unverified or left out of the window.
     */
    public function test_ownership_fallback_without_an_outage_is_listed(): void
    {
        // The real flush path of this process: unverified observations, rollback counts and no database error.
        Degradation::unverified();
        Degradation::unverified();
        Degradation::rollbackUnverified(3);
        Degradation::flush();

        $open = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        $windows = (array) $open->json('windows');
        self::assertCount(1, $windows);
        self::assertSame(ProcessContext::current()->identity(), $windows[0]['process']);
        self::assertSame(
            [2, 3, 0, 0, null, null, null, 'end unknown'],
            [$windows[0]['unverified'], $windows[0]['rollback_unverified'], $windows[0]['dropped'], $windows[0]['unmatched_observations'], $windows[0]['last_error_operation'], $windows[0]['last_error_at'], $windows[0]['ended_at'], $windows[0]['end_label']],
        );

        // The window closes at the next successful mirror operation, and stays listed.
        Degradation::recovered();
        Degradation::flush();
        $closed = $this->actingAs(new User)->getJson('/orrery/api/health')->assertOk();
        self::assertSame(2, $closed->json('windows.0.unverified'));
        self::assertSame(3, $closed->json('windows.0.rollback_unverified'));
        self::assertIsFloat($closed->json('windows.0.ended_at'));
        self::assertNull($closed->json('windows.0.end_label'));
    }

    /**
     * @return array<string, mixed> one orrery_health_events row
     */
    private function windowRow(string $process, float $startedAt, ?float $endedAt): array
    {
        return [
            'context' => 'worker',
            'process' => $process,
            'started_at' => Clock::toDecimal($startedAt),
            'ended_at' => $endedAt === null ? null : Clock::toDecimal($endedAt),
            'dropped' => 0,
            'unmatched_observations' => 0,
            'unverified' => 0,
            'last_error_operation' => null,
            'last_error_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function window(string $process, float $startedAt, ?float $endedAt, array $extra = []): void
    {
        HealthEvents::write('worker', $process, array_merge([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'dropped' => 0,
            'unmatched_observations' => 0,
            'unverified' => 0,
            'last_error_operation' => null,
            'last_error_at' => null,
        ], $extra));
    }
}
