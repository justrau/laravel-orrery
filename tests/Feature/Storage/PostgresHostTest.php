<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\Fixtures\Performance\TaggedBudgetJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The Postgres cases: the pooled refusal
 * on the hot path, the startup options in the endpoint identity, the
 * schema-qualified relations of a pooled session under a leftover
 * search_path, and the lock bound of a hot-path write.
 */
final class PostgresHostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->testEnvironment()->driver !== 'pgsql') {
            self::markTestSkipped('This test needs pgsql, and this run uses '.$this->testEnvironment()->driver.'.');
        }
    }

    /**
     * Rejects: sending the SET search_path, and a silent fallback: a pooled mirror with a search_path other than the server default is refused, the watcher records it, and the host's push is unchanged.
     */
    public function test_pooled_refuses_a_non_default_search_path(): void
    {
        $config = $this->app['config'];
        $config->set('orrery.sessions.pooled', true);
        // The harness's search_path is `public`; the server default is "$user", public.
        $config->set('database.connections.'.$this->packageTables().'.search_path', 'public');
        PackageSession::releaseAll();
        $before = (int) $this->mirror()->table('orrery_jobs')->count();

        Queue::push(new BasicJob);

        self::assertSame(1, (int) DB::connection(TestEnvironment::PRIMARY)->table('jobs')->count(), 'The host push is unchanged.');
        self::assertSame($before, (int) $this->mirror()->table('orrery_jobs')->count(), 'No package statement ran.');
        self::assertTrue(Watcher::instance()->breakerOpen(), 'The refusal is a dependency error: the watcher stays degraded.');
        self::assertNotSame([], Degradation::record()['errors'] ?? [], 'The health record names the refusal.');
        self::assertSame([], array_values(array_filter(PackageSession::sessionsForTesting(), fn (array $entry): bool => $entry['name'] === $this->packageTables())), 'No refused session is cached.');
    }

    /**
     * Rejects: a pooled session that binds a bool as an integer. A pooled
     * session uses emulated prepares, so PDO puts each binding into the SQL
     * text, and Postgres refuses `boolean = 1` (42883) and an integer for a
     * boolean column (42804). Laravel 12's PostgresConnection casts every
     * bool to an integer; only Laravel 13 sends 'true' and 'false' with
     * emulated prepares. This passes on Laravel 13 without the override, so
     * the Laravel 12 leg is the one that catches its removal.
     */
    public function test_pooled_session_binds_booleans_as_literals(): void
    {
        $this->usePooledSessions();
        $session = $this->app->make(PackageSession::class)->mirror();

        self::assertTrue((bool) $session->getPdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES), 'A pooled session emulates its prepares.');
        self::assertSame(['true', 'false', 1], $session->prepareBindings([true, false, 1]), 'A bool becomes a literal; an integer stays.');

        // Real package statements that bind bools: the push insert (silenced,
        // monitored, tags_truncated), the completion, and a read with a bool.
        Queue::push(new BasicJob);
        $this->work(1);

        self::assertSame([], Degradation::record()['errors'] ?? [], 'No package statement failed.');
        self::assertSame(1, (int) $session->table('orrery_jobs')->where('silenced', false)->where('status', 'completed')->count());
        self::assertSame(0, (int) $session->table('orrery_jobs')->where('monitored', true)->count());
    }

    /**
     * Rejects: stripping an explicit path and trusting the backend default,
     * and reading `SHOW search_path` instead of
     * `pg_settings.reset_val`.
     *
     * A role (alice in the spec; a name of its own per run, because roles
     * are shared by the whole server) with the schemas <role> and public,
     * each holding a jobs and a failed-job table. The server default
     * `"$user", public` resolves <role>.jobs for that role. On a pooled
     * connection of the role, `search_path = public` is refused, also when
     * another client left `SET search_path = public` on the backend (then
     * SHOW would agree with the configured path; reset_val does not).
     * Without the key, the host and the package use the same jobs relation,
     * asserted by regclass and by the row id that the reconciliation finds;
     * also behind the leftover SET. (Retry, forget and restore are
     * tested elsewhere.)
     */
    #[FreshSchema]
    public function test_pooled_search_path_must_be_the_server_default(): void
    {
        $admin = DB::connection(TestEnvironment::PRIMARY);
        $prefix = $admin->getTablePrefix();
        $role = 'alice_'.bin2hex(random_bytes(4));
        $database = (string) $admin->getDatabaseName();
        $wrap = static fn (string $name): string => '"'.$name.'"';
        $admin->statement(sprintf("create role %s login password 'alice_pw'", $wrap($role)));

        $this->beforeApplicationDestroyed(function () use ($admin, $role, $wrap): void {
            PackageSession::releaseAll();
            DB::purge('alice_pg');
            // The rollback of the fence migration visits every database queue connection.
            $this->app['config']->set('queue.connections.alice_queue', null);
            $this->app['config']->set('database.connections.alice_pg', null);
            $admin->statement(sprintf('drop schema if exists %s cascade', $wrap($role)));
            $admin->statement(sprintf('drop owned by %s', $wrap($role)));
            $admin->statement(sprintf('drop role if exists %s', $wrap($role)));
        });

        $admin->statement(sprintf('create schema %1$s authorization %1$s', $wrap($role)));

        foreach (['jobs', 'failed_jobs'] as $table) {
            $admin->statement(sprintf('create table %s.%s (like public.%s including all)', $wrap($role), $wrap($prefix.$table), $wrap($prefix.$table)));
        }

        foreach ([$role, 'public'] as $schema) {
            $admin->statement(sprintf('grant usage on schema %s to %s', $wrap($schema), $wrap($role)));
            $admin->statement(sprintf('grant all on all tables in schema %s to %s', $wrap($schema), $wrap($role)));
            $admin->statement(sprintf('grant all on all sequences in schema %s to %s', $wrap($schema), $wrap($role)));
        }

        $config = $this->app['config'];
        $this->usePooledSessions();
        $alice = array_replace((array) $config->get('database.connections.'.TestEnvironment::PRIMARY), ['username' => $role, 'password' => 'alice_pw', 'search_path' => 'public']);
        $config->set('database.connections.alice_pg', $alice);
        $config->set('queue.connections.alice_queue', ['driver' => 'database', 'connection' => 'alice_pg', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        PackageSession::releaseAll();
        DB::purge(TestEnvironment::PRIMARY);
        $sessions = $this->app->make(PackageSession::class);
        $leftover = function () use ($alice): void {
            // A pooled backend that another client used: SET search_path = public is still on it.
            PackageSession::usePdoFactoryForTesting(static function (string $sessionName, array $session) use ($alice): ?\PDO {
                if ($sessionName !== PackageSession::NAME_PREFIX.'alice_pg') {
                    return null;
                }

                $pdo = new \PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $alice['host'], $alice['port'], $alice['database']), (string) $alice['username'], (string) $alice['password']);
                $pdo->exec('set search_path = public');

                return $pdo;
            });
        };

        foreach (['a new backend' => null, 'a leftover SET' => $leftover] as $case => $prepare) {
            PackageSession::releaseAll();
            PackageSession::usePdoFactoryForTesting(null);

            if ($prepare !== null) {
                $prepare();
            }

            // The configured `public` is not the server default for this role.
            $config->set('database.connections.alice_pg.search_path', 'public');

            try {
                $sessions->for('alice_pg');
                self::fail("{$case}: a configured search_path that is not the server default is refused.");
            } catch (\Justrau\Orrery\Storage\PooledSessionRefused $refused) {
                self::assertStringContainsString('is not the server default', $refused->getMessage(), $case);
            }

            // Without the key: the same relation as the host's.
            $config->set('database.connections.alice_pg.search_path', null);
            DB::purge('alice_pg');
            PackageSession::releaseAll();
            $host = DB::connection('alice_pg');
            $session = $sessions->for('alice_pg');
            self::assertInstanceOf(\Justrau\Orrery\Storage\PostgresSession::class, $session);
            $session->resolveRelations();
            self::assertSame($role, $session->knownSchemaOf($prefix.'jobs'), "{$case}: the package resolves {$role}.jobs.");
            self::assertSame(
                (string) $host->scalar('select to_regclass(?)::oid::text', [$prefix.'jobs']),
                (string) $session->scalar('select to_regclass(?)::oid::text', [$role.'.'.$prefix.'jobs']),
                "{$case}: the host and the package name the same relation.",
            );

            $id = (int) Queue::connection('alice_queue')->push(new BasicJob);
            self::assertSame(1, (int) $host->table('jobs')->where('id', $id)->count(), "{$case}: the host wrote {$role}.jobs.");
            $uuid = (string) $this->mirror()->table('orrery_jobs')->where('connection', 'alice_queue')->where('job_id', $id)->value('uuid');
            self::assertNotSame('', $uuid, "{$case}: the package observed the push with the host's row id.");

            $this->app->make(\Justrau\Orrery\Maintenance\Reconciliation::class)->reconcileActive('alice_queue');
            self::assertNull($this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('absent_since'), "{$case}: the reconciliation finds row {$id} in {$role}.jobs.");
            $host->table('jobs')->where('id', $id)->delete();
            $this->app->make(\Justrau\Orrery\Maintenance\Reconciliation::class)->reconcileActive('alice_queue');
            self::assertNotNull($this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('absent_since'), "{$case}: the row is gone from the same relation.");
        }
    }

    /**
     * Rejects: an identity without the startup options: a changed server_options entry gives a new session and a new fingerprint, also on a Laravel whose connector does not apply them (12.69, 13.30: no PostgresConnector::addServerOptions(); 12.70+ and 13.34+ have it).
     */
    public function test_server_options_enter_the_identity(): void
    {
        $applied = method_exists(\Illuminate\Database\Connectors\PostgresConnector::class, 'addServerOptions');
        $config = $this->app['config'];
        $base = $config->get('database.connections.testbench');
        $config->set('database.connections.options_pg', array_replace($base, ['server_options' => ['application_name' => 'orrery_one']]));
        $sessions = $this->app->make(PackageSession::class);
        $first = $sessions->for('options_pg');
        $print = $sessions->fingerprint('options_pg');
        $this->assertApplicationName($applied, 'orrery_one', $first->scalar('show application_name'));

        $config->set('database.connections.options_pg.server_options', ['application_name' => 'orrery_two']);
        $second = $sessions->for('options_pg');

        self::assertNotSame($first, $second, 'A new session, never the old PDO.');
        self::assertNotSame($print, $sessions->fingerprint('options_pg'), 'A new fingerprint.');
        $this->assertApplicationName($applied, 'orrery_two', $second->scalar('show application_name'));
    }

    /**
     * The path that ran: Laravel applies server_options at connect, or it ignores them (and the identity still holds them).
     */
    private function assertApplicationName(bool $applied, string $option, mixed $actual): void
    {
        if ($applied) {
            self::assertSame($option, $actual, 'Laravel '.$this->app->version().' applies server_options.');
        } else {
            self::assertNotSame($option, $actual, 'Laravel '.$this->app->version().' does not apply server_options.');
        }
    }

    /**
     * Rejects: unqualified autocommit mirror statements, and trusting reset_val alone.
     *
     * The backend of the pooled session carries a leftover `SET search_path
     * = other` (a pooler hands such a backend to the next client), and the
     * schema `other` has a full copy of the package tables and a jobs table.
     * The untagged and tagged lifecycles act on the default schema's
     * relations, asserted by row counts on both schemas. (The retry, forget
     * and restore cases are tested elsewhere.)
     */
    #[FreshSchema]
    public function test_pooled_relations_ignore_a_leftover_search_path(): void
    {
        // The database of the package tables: testbench in same mode, testbench_mirror in separate mode.
        $host = DB::connection($this->packageTables());
        $prefix = $host->getTablePrefix();
        $tables = array_map(static fn (object $row): string => (string) $row->tablename, $host->select("select tablename from pg_tables where schemaname = 'public' and (tablename like ? or tablename = ?)", [$prefix.'orrery_%', $prefix.'jobs']));
        $host->statement('create schema other');

        foreach ($tables as $table) {
            $host->statement(sprintf('create table other.%1$s (like public.%1$s including all)', $host->getQueryGrammar()->wrap($table)));
        }

        $this->beforeApplicationDestroyed(static function () use ($host): void {
            PackageSession::releaseAll();
            $host->statement('drop schema if exists other cascade');
        });

        self::assertContains($prefix.'orrery_jobs', $tables, 'The other schema copies the package tables.');
        $this->usePooledSessions();
        $host = DB::connection($this->packageTables());
        DB::connection(TestEnvironment::PRIMARY)->statement('set search_path = public');

        // The leftover SET on the package session's backend.
        $session = $this->app->make(PackageSession::class)->mirror();
        $session->getPdo()->exec('set search_path = other');

        Queue::push(new BasicJob);
        Queue::push(new TaggedBudgetJob);
        $this->work(2);

        $count = static fn (string $schema, string $table): int => (int) $host->scalar(sprintf('select count(*) from %s.%s', $schema, $host->getQueryGrammar()->wrap($prefix.$table)));

        self::assertSame('other', (string) $session->scalar('show search_path'), 'The leftover setting is still on the backend.');
        self::assertSame(2, $count('public', 'orrery_jobs'), 'Both lifecycles wrote the default schema\'s mirror.');
        self::assertSame(2, (int) $host->table('orrery_jobs')->where('status', 'completed')->count());
        self::assertGreaterThan(0, $count('public', 'orrery_job_tags'));
        self::assertSame(0, $count('other', 'orrery_jobs'), 'Nothing reached the other schema.');
        self::assertSame(0, $count('other', 'orrery_job_tags'));
    }

    /**
     * Rejects: a pooled autocommit package statement without the lock and
     * statement timeouts: a host transaction that holds the row with FOR
     * UPDATE blocks the reservation update or the outcome update of a
     * pooled session for ever (found by a pooled harness run). The
     * update gives up within the lock bound and is counted as an error; the
     * host's own calls go on; no session setting stays on the backend. It
     * runs on the test bound (1 s): the hot-path test below keeps the
     * production 5 s (pooled too in the pooled harness run).
     */
    #[DataProvider('pooledWrites')]
    #[FreshSchema]
    public function test_pooled_autocommit_write_behind_a_held_lock_is_bounded(string $write): void
    {
        $config = $this->app['config'];
        $this->usePooledSessions();
        PackageSession::useLockWaitSecondsForTesting(1);

        Queue::push(new BasicJob);
        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        $queue = $this->app->make('queue')->connection('database');
        $job = $write === 'outcome' ? $queue->pop('default') : null;
        // Another client of the database of the package tables holds the row lock.
        $config->set('database.connections.holder_pg', $config->get('database.connections.'.$this->packageTables()));
        $holder = $this->app['db']->connection('holder_pg');
        $holder->beginTransaction();

        try {
            $holder->table('orrery_jobs')->where('uuid', $uuid)->lockForUpdate()->first();
            $started = microtime(true);

            if ($job === null) {
                $job = $queue->pop('default');
            } else {
                $options = new \Illuminate\Queue\WorkerOptions;
                $options->maxTries = 1;
                $this->app->make('queue.worker')->process('database', $job, $options);
            }

            $elapsed = microtime(true) - $started;
        } finally {
            $holder->rollBack();
        }

        self::assertNotNull($job, 'The host call went on.');
        self::assertLessThan(4.0, $elapsed, 'The package update gave up at the 1 s test lock timeout, not at the 10 s statement timeout or the production 5 s.');
        self::assertGreaterThan(0.8, $elapsed, 'The update waited for the lock.');
        self::assertSame($write === 'outcome' ? 'reserved' : 'pending', $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status'), 'The write was not recorded.');
        self::assertNotSame([], Degradation::record()['errors'] ?? [], 'The failed write is counted.');
        // Read through the PDO between statements: a pooled statement carries its own SET LOCAL bounds.
        self::assertSame('0', (string) $this->app->make(PackageSession::class)->mirror()->getPdo()->query('show lock_timeout')->fetchColumn(), 'No setting stays on the pooled backend.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function pooledWrites(): array
    {
        return ['the reservation update' => ['reservation'], 'the outcome update' => ['outcome']];
    }

    /**
     * Rejects: an unbounded wait inside the pop() hook: the reservation's update behind a held row lock gives up within about 5 s, the host's pop() still returns the job, and a health window records the error.
     *
     * The one Postgres test that waits for the production lock bound (PackageSession::LOCK_WAIT_SECONDS).
     */
    #[FreshSchema]
    public function test_hot_path_write_behind_a_held_lock_is_bounded(): void
    {
        Queue::push(new BasicJob);
        $uuid = (string) $this->mirror()->table('orrery_jobs')->value('uuid');
        // Another client of the database of the package tables holds the row lock.
        $this->app['config']->set('database.connections.holder_pg', $this->app['config']->get('database.connections.'.$this->packageTables()));
        $holder = $this->app['db']->connection('holder_pg');
        $holder->beginTransaction();

        try {
            $holder->table('orrery_jobs')->where('uuid', $uuid)->lockForUpdate()->first();
            $started = microtime(true);
            $job = $this->app->make('queue')->connection('database')->pop('default');
            $elapsed = microtime(true) - $started;
        } finally {
            $holder->rollBack();
        }

        self::assertNotNull($job, 'The host pop() returns the job.');
        self::assertSame(5, PackageSession::LOCK_WAIT_SECONDS);
        self::assertGreaterThan(4.0, $elapsed, 'The update waited for the lock.');
        self::assertLessThan(9.0, $elapsed, 'The update gave up at the 5 s lock timeout.');
        self::assertSame('pending', $this->mirror()->table('orrery_jobs')->where('uuid', $uuid)->value('status'), 'The reservation was not recorded.');
        self::assertNotSame([], Degradation::record()['errors'] ?? [], 'A health window records the error.');

        try {
            $job->delete();
        } catch (Throwable) {
            // The job row goes with the test's schema.
        }
    }

    /**
     * The connection of the package tables: testbench in same mode,
     * testbench_mirror in separate mode.
     */
    private function packageTables(): string
    {
        return $this->testEnvironment()->mirrorConnectionName();
    }

    /**
     * Pooled sessions without the harness's search_path on every connection
     * that the package opens: the queue's (testbench) and the package
     * tables' (testbench_mirror in separate mode). The server default then
     * decides, as a pooled session needs. orrery.database stays the run's.
     */
    private function usePooledSessions(): void
    {
        $config = $this->app['config'];
        $config->set('orrery.sessions.pooled', true);
        $names = array_values(array_unique([TestEnvironment::PRIMARY, $this->packageTables()]));

        foreach ($names as $name) {
            $config->set("database.connections.{$name}.search_path", null);
        }

        PackageSession::releaseAll();

        foreach ($names as $name) {
            DB::purge($name);
        }

        self::assertSame($this->testEnvironment()->packageConnection(), $config->get('orrery.database'));
    }
}
