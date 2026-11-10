<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Justrau\Orrery\Database\Schema\DeferredIndexes;
use Justrau\Orrery\Database\Schema\IndexBuild;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The deferred indexes of the stuck signals: a fresh
 * install has them, `orrery:install --indexes` builds them on an existing
 * mirror on a dedicated connection, DeferredIndexes reads the catalog at
 * most once per 60 s, and the per-engine locks and refusals.
 *
 * FreshSchema: every test drops or builds indexes.
 */
#[FreshSchema]
final class DeferredIndexesTest extends TestCase
{
    /**
     * Rejects: a create() without the deferred indexes, or with other columns.
     */
    public function test_a_fresh_install_has_the_deferred_indexes(): void
    {
        self::assertSame([], DeferredIndexes::read($this->mirror()));
        self::assertSame(array_values(PackageSchema::DEFERRED_INDEXES), array_values($this->deferredColumns()));
    }

    /**
     * Rejects: a build that does nothing, builds other columns, runs its DDL
     * on a package session (the package statements are bridged to
     * QueryExecuted, the dedicated build connection has no dispatcher), or
     * keeps the 60 s cache of this process.
     */
    public function test_install_indexes_builds_the_missing_indexes_on_a_dedicated_connection(): void
    {
        $this->dropDeferred();
        $indexes = $this->app->make(DeferredIndexes::class);
        self::assertSame(['st_attempts', 'st_gap', 'st_releases'], $indexes->missing($this->mirror()));

        $ddl = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$ddl): void {
            if (preg_match('/create index|add index/i', $query->sql) === 1) {
                $ddl[] = $query->connectionName;
            }
        });

        self::assertSame(0, $this->install(), Artisan::output());
        self::assertSame([], $indexes->missing($this->mirror()), 'the build forgets the cache of this process');
        self::assertSame(array_values(PackageSchema::DEFERRED_INDEXES), array_values($this->deferredColumns()));

        if (! $this->testEnvironment()->inMemory()) {
            self::assertSame([], $ddl, 'no index statement ran on a package session or an application connection');
        }

        self::assertSame(0, $this->install());
        self::assertStringContainsString('Every deferred orrery_jobs index exists', Artisan::output());
    }

    /**
     * Rejects: a missing() without a cache (it reads the catalog on every
     * call: the dropped index shows at once), and a cache that never expires.
     */
    public function test_missing_reads_the_catalog_at_most_once_per_60_seconds(): void
    {
        $indexes = $this->app->make(DeferredIndexes::class);
        $mirror = $this->app->make(PackageSession::class)->mirror();
        Clock::freezeMonotonicForTesting();

        self::assertSame([], $indexes->missing($mirror));
        $this->dropDeferred();
        self::assertSame([], $indexes->missing($mirror), 'within 60 s the cached read answers');

        Clock::advanceMonotonicForTesting(59.0);
        self::assertSame([], $indexes->missing($mirror));
        self::assertTrue($indexes->has($mirror, 'st_gap'));

        Clock::advanceMonotonicForTesting(1.5);
        self::assertSame(['st_attempts', 'st_gap', 'st_releases'], $indexes->missing($mirror), 'after 60 s the catalog is read again');
        self::assertFalse($indexes->has($mirror, 'st_gap'));
    }

    /**
     * Rejects: an `index_missing` recorded on every call (a degradation every
     * tick), or never.
     */
    public function test_index_missing_is_recorded_once_per_process(): void
    {
        $indexes = $this->app->make(DeferredIndexes::class);

        self::assertFalse($indexes->recordOnce([]));
        self::assertTrue($indexes->recordOnce(['st_gap']));
        $first = Degradation::record();
        self::assertNotNull($first);
        self::assertSame(DeferredIndexes::OPERATION, $first['last_error_operation']);

        self::assertFalse($indexes->recordOnce(['st_gap']));
        self::assertFalse($this->app->make(DeferredIndexes::class)->recordOnce(['st_attempts']), 'the singleton keeps the flag');
    }

    /**
     * Rejects: a build that runs under the package's statement timeout. The
     * package sessions get a 20 ms timeout (the control reads it from the
     * session); the build on its dedicated connection still succeeds. On
     * Postgres a 20,000-row CREATE INDEX does not fit 20 ms; MySQL's
     * max_execution_time does not apply to DDL, so there the
     * dedicated-connection proof is the DDL listener of
     * test_install_indexes_builds_the_missing_indexes_on_a_dedicated_connection.
     */
    public function test_the_build_ignores_the_package_statement_timeout(): void
    {
        $this->serverOnly();
        $this->seedRows(20_000);
        $this->dropDeferred();
        // 20 ms: the control read fits it on a loaded machine; a 20,000-row concurrent build on Postgres does not.
        $this->app['config']->set('orrery.sessions.statement_timeout_ms', 20);
        $mirror = $this->app->make(PackageSession::class)->mirror();

        // The control reads the session's own limit, not a timed query (a timed control failed once on MySQL).
        $limit = $mirror->getDriverName() === 'pgsql'
            ? (string) $mirror->scalar("select current_setting('statement_timeout')")
            : (string) $mirror->scalar('select @@max_execution_time');
        self::assertContains($limit, ['20', '20ms'], 'the control: the package session runs with the lowered timeout');

        self::assertSame(0, $this->install(), Artisan::output());
        self::assertSame([], DeferredIndexes::read($this->mirror()));
    }

    /**
     * Rejects: a build that keeps an index that an interrupted build left
     * INVALID (the planner never uses it, and IF NOT EXISTS skips it).
     */
    public function test_postgres_drops_and_rebuilds_an_invalid_index(): void
    {
        $this->engineOnly('pgsql');
        $mirror = $this->mirror();
        $name = IndexName::for($mirror, 'orrery_jobs', 'st_gap');
        $mirror->update('update pg_index set indisvalid = false where indexrelid = to_regclass(?)', ['"'.$name.'"']);

        self::assertSame(['st_gap'], DeferredIndexes::read($mirror));
        $code = $this->install();
        $output = Artisan::output();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('is invalid', $output);
        self::assertSame([], DeferredIndexes::read($mirror));
    }

    /**
     * Rejects: a failed concurrent build that leaves its INVALID index (the
     * next build and the readers would see an index that exists). The hook
     * makes the CREATE INDEX CONCURRENTLY time out.
     */
    public function test_postgres_failed_build_drops_its_invalid_index(): void
    {
        $this->engineOnly('pgsql');
        $this->seedRows(20_000);
        $this->dropDeferred();
        $build = $this->app->make(IndexBuild::class);
        $build->beforeStatementForTesting(static function (Connection $connection, string $suffix): void {
            if ($suffix === 'st_attempts') {
                $connection->statement('SET statement_timeout = 1');
            }
        });
        $this->app->instance(IndexBuild::class, $build);

        self::assertSame(1, $this->install());
        self::assertStringContainsString('Dropped the invalid index', Artisan::output());
        $name = IndexName::for($this->mirror(), 'orrery_jobs', 'st_attempts');
        self::assertNull($this->mirror()->scalar('select to_regclass(?)', ['"'.$name.'"']), 'no invalid index is left');
    }

    /**
     * Rejects: a Postgres build without the advisory lock (it would not
     * wait), or one that refuses instead of waiting. A child process holds
     * pg_advisory_lock for about 2 s.
     */
    public function test_postgres_second_build_waits_for_the_lock(): void
    {
        $this->engineOnly('pgsql');
        $this->dropDeferred();
        $config = $this->app['config']->get('database.connections.'.$this->testEnvironment()->mirrorConnectionName());
        $marker = tempnam(sys_get_temp_dir(), 'orrery-lock');
        unlink($marker);
        $script = sprintf(
            '$p = new PDO(%s, %s, %s); $p->query("select pg_advisory_lock(%d)"); touch(%s); usleep(2000000); $p->query("select pg_advisory_unlock(%d)");',
            var_export(sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']), true),
            var_export((string) $config['username'], true),
            var_export((string) $config['password'], true),
            IndexBuild::ADVISORY_KEY,
            var_export($marker, true),
            IndexBuild::ADVISORY_KEY,
        );
        $child = proc_open([PHP_BINARY, '-r', $script], [], $pipes);
        self::assertIsResource($child);

        try {
            $deadline = microtime(true) + 20;

            while (! file_exists($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }

            self::assertFileExists($marker, 'the child holds the lock');
            $started = microtime(true);
            self::assertSame(0, $this->install(), Artisan::output());
            self::assertGreaterThan(1.0, microtime(true) - $started, 'the build waited for the lock');
            self::assertSame([], DeferredIndexes::read($this->mirror()));
        } finally {
            proc_close($child);
            @unlink($marker);
        }
    }

    /**
     * Rejects: a MySQL build without GET_LOCK, or one that waits for it
     * (the second build exits 1 at once).
     */
    public function test_mysql_second_build_exits_1(): void
    {
        $this->engineOnly('mysql');
        $this->dropDeferred();
        $holder = $this->app['db']->connection($this->testEnvironment()->mirrorConnectionName());
        $lock = mb_strcut('orrery_indexes:'.$holder->getDatabaseName(), 0, 64, 'UTF-8');
        self::assertSame(1, (int) $holder->scalar('select GET_LOCK(?, 0)', [$lock]));

        try {
            self::assertSame(1, $this->install());
            self::assertStringContainsString('Another php artisan orrery:install --indexes runs', Artisan::output());
            self::assertSame(['st_attempts', 'st_gap', 'st_releases'], DeferredIndexes::read($this->mirror()));
        } finally {
            $holder->select('select RELEASE_LOCK(?)', [$lock]);
        }

        self::assertSame(0, $this->install(), Artisan::output());
    }

    /**
     * Rejects: a build through a pooled mirror without a `direct` endpoint
     * (session settings and advisory locks leak through a pooler), a
     * `--connection` to another database, and a refusal of a direct
     * connection to the same database.
     */
    public function test_a_pooled_postgres_mirror_needs_a_direct_connection(): void
    {
        $this->engineOnly('pgsql');
        $this->dropDeferred();
        $name = $this->testEnvironment()->mirrorConnectionName();
        $config = $this->app['config'];
        $mirror = $config->get('database.connections.'.$name);
        $config->set('orrery.sessions.pooled', true);

        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true]));
        self::assertStringContainsString('--connection=<a direct connection to the same database>', Artisan::output());

        $base = (string) $config->get('database.connections.testbench.database');
        $other = $mirror['database'] === $base ? $base.'_mirror' : $base;
        $config->set('database.connections.index_other', ['database' => $other] + $mirror);
        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_other']));
        self::assertStringContainsString('does not reach the Orrery mirror database', Artisan::output());

        $config->set('database.connections.index_laravel_pooled', ['pooled' => true] + $mirror);
        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_laravel_pooled']));
        self::assertStringContainsString('without a `direct` endpoint', Artisan::output());

        $config->set('database.connections.index_direct', $mirror);
        self::assertSame(0, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_direct']), Artisan::output());
        self::assertSame([], DeferredIndexes::read($this->mirror()));
    }

    /**
     * Rejects: a pooled mirror whose `direct` endpoint is not used.
     */
    public function test_a_pooled_postgres_mirror_builds_through_its_direct_endpoint(): void
    {
        $this->engineOnly('pgsql');
        $this->dropDeferred();
        $name = $this->testEnvironment()->mirrorConnectionName();
        $config = $this->app['config'];
        $mirror = $config->get('database.connections.'.$name);
        // Laravel's `direct` endpoint marks the connection as pooled; it names the build's own endpoint settings.
        $config->set('database.connections.'.$name, ['direct' => ['application_name' => 'orrery-index-direct']] + $mirror);
        $this->app['db']->purge($name);

        try {
            $build = $this->app->make(IndexBuild::class);
            $connection = $build->connectionForTesting(null);
            self::assertSame('orrery-index-direct', $connection->getConfig('application_name'));
            self::assertSame('orrery-index-direct', $connection->scalar('select current_setting(\'application_name\')'));
            $connection->disconnect();

            self::assertSame(0, $this->install(), Artisan::output());
            self::assertSame([], DeferredIndexes::read($this->mirror()));
        } finally {
            $config->set('database.connections.'.$name, $mirror);
            $this->app['db']->purge($name);
        }
    }

    /**
     * Rejects: a probe through a pooled endpoint without
     * emulated prepares (a transaction pooler loses server-side prepared
     * statements), and a probe that overrides an explicit choice.
     */
    public function test_the_probe_of_a_pooled_mirror_emulates_prepares(): void
    {
        $raw = ['driver' => 'pgsql', 'host' => 'pooler', 'port' => 6432, 'database' => 'app', 'options' => [\PDO::ATTR_PERSISTENT => true]];

        $pooled = IndexBuild::probeConfig($raw, true);
        self::assertTrue($pooled['options'][\PDO::ATTR_EMULATE_PREPARES]);
        self::assertArrayNotHasKey(\PDO::ATTR_PERSISTENT, $pooled['options']);
        self::assertArrayNotHasKey(\PDO::ATTR_EMULATE_PREPARES, IndexBuild::probeConfig($raw, false)['options']);
        self::assertFalse(IndexBuild::probeConfig(['options' => [\PDO::ATTR_EMULATE_PREPARES => false]] + $raw, true)['options'][\PDO::ATTR_EMULATE_PREPARES]);
    }

    /**
     * Rejects: a Postgres build that reads the indexes of
     * the first schema of the search path (an empty one) instead of the
     * schema of orrery_jobs, and then drops valid indexes as "invalid".
     */
    public function test_postgres_build_reads_the_schema_of_orrery_jobs(): void
    {
        $this->engineOnly('pgsql');
        $name = $this->testEnvironment()->mirrorConnectionName();
        $config = $this->app['config'];
        $original = $config->get('database.connections.'.$name);
        $this->mirror()->statement('CREATE SCHEMA IF NOT EXISTS orrery_empty');
        $this->beforeApplicationDestroyed(function (): void {
            $this->mirror()->statement('DROP SCHEMA IF EXISTS orrery_empty');
        });

        try {
            $config->set('database.connections.'.$name, ['search_path' => 'orrery_empty,'.($original['search_path'] ?? 'public')] + $original);
            $this->app['db']->purge($name);
            $code = $this->install();
            $output = Artisan::output();
        } finally {
            $config->set('database.connections.'.$name, $original);
            $this->app['db']->purge($name);
        }

        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('is invalid', $output);
        self::assertStringContainsString('Every deferred orrery_jobs index exists', $output);
        self::assertSame([], DeferredIndexes::read($this->mirror()), 'the valid indexes stay');
    }

    /**
     * Rejects: a build that answers success while an index is still missing
     * on the mirror (the final state is read again on the mirror's own
     * configuration).
     */
    public function test_the_build_fails_when_an_index_is_missing_afterwards(): void
    {
        $this->dropDeferred();
        $mirror = $this->mirror();
        $build = $this->app->make(IndexBuild::class);
        $build->beforeStatementForTesting(static function (Connection $connection, string $suffix) use ($mirror): void {
            if ($suffix === 'st_gap') {
                $mirror->getSchemaBuilder()->table('orrery_jobs', static function ($table) use ($mirror): void {
                    $table->dropIndex(IndexName::for($mirror, 'orrery_jobs', 'st_attempts'));
                });
            }
        });
        $this->app->instance(IndexBuild::class, $build);

        self::assertSame(1, $this->install());
        self::assertStringContainsString('still missing on the mirror: st_attempts', Artisan::output());
    }

    /**
     * Rejects: a MySQL identity by host and port (two
     * servers behind one address look the same), and a MariaDB identity
     * read of @@server_uuid, which MariaDB does not have.
     */
    public function test_the_identity_read_names_the_server_instance(): void
    {
        $mariadb = new \Illuminate\Database\MariaDbConnection(static fn () => throw new \LogicException('no connect'), 'db', '', ['driver' => 'mariadb']);
        self::assertStringContainsString('@@server_id', IndexBuild::identitySql($mariadb));
        self::assertStringNotContainsString('server_uuid', IndexBuild::identitySql($mariadb));

        if ($this->mirror()->getDriverName() === 'mysql') {
            self::assertStringContainsString('@@server_uuid', IndexBuild::identitySql($this->mirror()));
        }
    }

    /**
     * Rejects: a MySQL `--connection` to another database
     * of the same server accepted for the mirror.
     */
    public function test_mysql_refuses_a_connection_to_another_database(): void
    {
        $this->engineOnly('mysql');
        $this->dropDeferred();
        $config = $this->app['config'];
        $mirror = $config->get('database.connections.'.$this->testEnvironment()->mirrorConnectionName());
        $base = (string) $config->get('database.connections.testbench.database');
        $config->set('database.connections.index_other', ['database' => $mirror['database'] === $base ? $base.'_mirror' : $base] + $mirror);

        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_other']));
        self::assertStringContainsString('does not reach the Orrery mirror database', Artisan::output());
        self::assertSame(['st_attempts', 'st_gap', 'st_releases'], DeferredIndexes::read($this->mirror()));
    }

    /**
     * Rejects: a `--connection` that orrery.sessions.pooled
     * lists by name (it goes through a pooler) accepted as a direct
     * connection; and a list from the environment, a
     * comma-separated string, that the check does not parse.
     *
     * @param  list<string>|string  $listed
     */
    #[DataProvider('pooledLists')]
    public function test_a_connection_listed_as_pooled_is_refused(array|string $listed): void
    {
        $this->engineOnly('pgsql');
        $config = $this->app['config'];
        $config->set('database.connections.index_listed', $config->get('database.connections.'.$this->testEnvironment()->mirrorConnectionName()));
        $config->set('orrery.sessions.pooled', $listed);

        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_listed']));
        self::assertStringContainsString('is listed in orrery.sessions.pooled', Artisan::output());
    }

    /**
     * @return array<string, array{0: list<string>|string}>
     */
    public static function pooledLists(): array
    {
        return ['an array' => [['index_listed']], 'a string from the environment' => ['other_pool, index_listed']];
    }

    /**
     * Rejects: `--connection` naming the
     * mirror's own connection while orrery.sessions.pooled is true for every
     * connection: that is the pooled endpoint, not a direct one.
     */
    public function test_the_pooled_mirror_itself_is_refused_as_its_own_connection(): void
    {
        $this->engineOnly('pgsql');
        $this->app['config']->set('orrery.sessions.pooled', true);

        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => $this->testEnvironment()->mirrorConnectionName()]));
        self::assertStringContainsString('--connection=<a direct connection to the same database>', Artisan::output());
    }

    /**
     * Rejects: a --timeout that does not fit Postgres's statement_timeout in
     * milliseconds (a 32-bit integer).
     */
    public function test_the_timeout_option_is_bounded(): void
    {
        self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--timeout' => '2147484']));
        self::assertStringContainsString('--timeout is a whole number of seconds', Artisan::output());
    }

    /**
     * Rejects: a `--connection` of another SQLite file accepted for the mirror.
     */
    public function test_a_connection_to_another_sqlite_file_is_refused(): void
    {
        $this->engineOnly('sqlite');

        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('A SQLite file mirror only.');
        }

        $file = tempnam(sys_get_temp_dir(), 'orrery-other');
        $this->app['config']->set('database.connections.index_other', ['driver' => 'sqlite', 'database' => $file, 'prefix' => $this->mirror()->getTablePrefix()]);

        try {
            self::assertSame(1, Artisan::call('orrery:install', ['--indexes' => true, '--connection' => 'index_other']));
            self::assertStringContainsString('database differs', Artisan::output());
        } finally {
            @unlink($file);
        }
    }

    /**
     * `orrery:install --indexes`. On a pooled Postgres mirror without a
     * `direct` endpoint (ORRERY_TEST_PG_POOLED) the build needs
     * `--connection`: a direct copy of the mirror's connection.
     */
    private function install(): int
    {
        $arguments = ['--indexes' => true];
        $config = $this->app['config'];

        if ($this->mirror()->getDriverName() === 'pgsql' && $config->get('orrery.sessions.pooled')) {
            $config->set('database.connections.index_direct_copy', $config->get('database.connections.'.$this->testEnvironment()->mirrorConnectionName()));
            $arguments['--connection'] = 'index_direct_copy';
        }

        return Artisan::call('orrery:install', $arguments);
    }

    /**
     * @return array<string, list<string>>
     */
    private function deferredColumns(): array
    {
        $mirror = $this->mirror();
        $byName = [];

        foreach ($mirror->getSchemaBuilder()->getIndexes('orrery_jobs') as $index) {
            $byName[strtolower((string) $index['name'])] = $index['columns'];
        }

        $columns = [];

        foreach (array_keys(PackageSchema::DEFERRED_INDEXES) as $suffix) {
            $columns[$suffix] = $byName[strtolower(IndexName::for($mirror, 'orrery_jobs', $suffix))] ?? [];
        }

        return $columns;
    }

    private function dropDeferred(): void
    {
        $mirror = $this->mirror();

        $mirror->getSchemaBuilder()->table('orrery_jobs', static function ($table) use ($mirror): void {
            foreach (array_keys(PackageSchema::DEFERRED_INDEXES) as $suffix) {
                $table->dropIndex(IndexName::for($mirror, 'orrery_jobs', $suffix));
            }
        });
    }

    private function seedRows(int $rows): void
    {
        $mirror = $this->mirror();
        $batch = [];

        for ($id = 1; $id <= $rows; $id++) {
            $batch[] = [
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'connection' => 'database',
                'queue' => 'default',
                'name' => 'App\\Jobs\\Sync',
                'status' => $id % 3 === 0 ? 'failed' : 'completed',
                'payload' => '{}',
                'payload_bytes' => 2,
                'attempts' => $id % 5,
                'observed_gap' => $id % 2,
                'pushed_at' => Clock::toDecimal(1_790_000_000 + $id),
                'finished_at' => Clock::toDecimal(1_790_000_001 + $id),
                'updated_at' => Clock::toDecimal(1_790_000_001 + $id),
            ];

            if (count($batch) === 500) {
                $mirror->table('orrery_jobs')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $mirror->table('orrery_jobs')->insert($batch);
        }
    }

    private function serverOnly(): void
    {
        if (! $this->testEnvironment()->isServer()) {
            self::markTestSkipped('A MySQL or Postgres mirror only (SQLite has no statement timeout).');
        }
    }

    private function engineOnly(string $driver): void
    {
        $actual = $this->mirror()->getDriverName();

        if ($actual !== $driver) {
            self::markTestSkipped("A {$driver} mirror only.");
        }
    }
}
