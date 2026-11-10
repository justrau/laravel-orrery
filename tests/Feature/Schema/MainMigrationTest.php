<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Exceptions\WatchedOperationFailed;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * The main migration (the frozen wrapper of PackageSchema::install() and
 * uninstall()) run through Laravel's own migrate commands, with an
 * orrery.database that names a connection other than the migrate
 * connection: its own migrations table (REPO, OTHER_REPO) and the package
 * tables (MIRROR) are on different connection objects. On a server engine
 * they are copies of the run's database with their own table prefix; on
 * SQLite they are files of their own. It runs DDL (#[FreshSchema]).
 * WithConsoleEvents: as under `php artisan`, each command dispatches
 * CommandStarting, which records the application's default.
 */
#[FreshSchema]
final class MainMigrationTest extends TestCase
{
    // Artisan dispatches CommandStarting outside unit tests only; install() reads the default it records.
    use WithConsoleEvents;

    private const REPO = 'main_migration_repo';

    private const OTHER_REPO = 'main_migration_repo_b';

    private const MIRROR = 'main_migration_mirror';

    /** @var list<string> */
    private array $files = [];

    /** @var array<string, mixed> */
    private array $saved = [];

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        try {
            $this->dropOwnTables();
        } finally {
            $config = $this->app['config'];

            foreach ($this->saved as $key => $value) {
                $config->set($key, $value);
            }

            PackageSession::releaseAll();

            foreach ([self::REPO, self::OTHER_REPO, self::MIRROR, self::MIRROR.'::direct', 'main_migration_refused', 'main_migration_app'] as $name) {
                $this->app['db']->purge($name);
            }

            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }

            foreach ($this->temporary as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Rejects: a main migration without getConnection(), whose `migrate --pretend` runs the
     * DDL of create() and the marker for real on the package connection, and whose
     * `migrate:rollback --pretend` drops every package table for real (the migrator pretends only
     * on its own connection). Also a pretend run that shows none of the package's statements.
     * Still passes: a migration that pretends correctly only when orrery.database names the
     * migrate connection itself (the same connection object).
     */
    public function test_pretend_runs_change_nothing_on_another_package_connection(): void
    {
        $this->assertPretendChangesNothing($this->useOwnConnections());
    }

    /**
     * Rejects: an install() that takes the plain-name connection of orrery.database
     * while the migrator pretends and opens its transaction on `<name>::direct` (Laravel's
     * `direct` key, a Postgres connection behind a pooler): `migrate --pretend` then creates
     * the package tables for real.
     * Still passes: an install() that uses the migrator's connection only for a `direct` key (no
     * other PDO type reaches the migrator).
     */
    public function test_pretend_runs_change_nothing_on_a_direct_package_connection(): void
    {
        $mirror = $this->useOwnConnections(direct: true);

        $this->assertPretendChangesNothing($mirror);
    }

    /**
     * Rejects (review, item 3): a SchemaLoaded listener that compares the plain mirror name with
     * the loaded connection's name, so a dump loaded through `<name>::direct` keeps its empty
     * marker table.
     * Still passes, before Laravel 13.31: that listener, because Laravel names a direct
     * connection by its plain name there (laravel/framework#61435 added the suffix).
     */
    public function test_a_schema_loaded_through_a_direct_connection_gets_its_marker(): void
    {
        $mirror = $this->useOwnConnections(direct: true);
        PackageSchema::create($mirror->getSchemaBuilder());

        /** @var Connection $direct */
        $direct = $this->app['db']->connection(self::MIRROR.'::direct');
        self::assertSame(self::MIRROR.'::direct', $direct->getNameWithReadWriteType());
        self::assertSame($this->directConnectionsHaveTheSuffix() ? self::MIRROR.'::direct' : self::MIRROR, $direct->getName());
        PackageSchema::afterSchemaLoad($direct);

        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
    }

    /**
     * Rejects (review, item 1): an install() that decides mirrorRefusal() while the migrator has
     * made the package connection the default, so a queue connection with an empty `connection`
     * key resolves to the package connection's own SQLite file and `migrate` (and
     * `migrate --pretend`) refuses a dedicated SQLite package file.
     * Still passes: an install() that never refuses a SQLite file (the refused case is
     * test_migrate_refuses_a_refused_package_connection).
     */
    public function test_migrate_accepts_a_dedicated_sqlite_package_file_beside_a_default_queue(): void
    {
        $mirror = $this->useOwnConnections(sqlite: true);
        $this->save('queue.connections');
        $this->app['config']->set('queue.connections.main_migration_default', ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        PackageSession::releaseAll();
        self::assertNull($this->app->make(PackageSession::class)->mirrorRefusal());

        $this->migrate(self::REPO, ['--pretend' => true]);
        self::assertFalse(PackageSchema::hasPackageTables($mirror));

        $this->migrate(self::REPO);
        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
    }

    /**
     * Rejects (re-check, item 1): an install() that decides the refusal against the default that
     * `migrate --database=<package connection>` made (Migrator::setConnection() swaps it before
     * the migration's getConnection()), so a queue connection with an empty `connection` key
     * resolves to the package connection's own SQLite file and the command form of docs/upgrading.md is
     * refused.
     */
    public function test_migrate_on_the_dedicated_sqlite_package_file_itself_is_accepted(): void
    {
        $mirror = $this->useOwnConnections(sqlite: true);
        $this->save('queue.connections');
        $this->app['config']->set('queue.connections.main_migration_default', ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        PackageSession::releaseAll();
        self::assertNull($this->app->make(PackageSession::class)->mirrorRefusal());

        $this->migrate(self::MIRROR, ['--pretend' => true]);
        self::assertFalse(PackageSchema::hasPackageTables($mirror));

        $this->migrate(self::MIRROR);
        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
    }

    /**
     * Rejects (re-check, item 1): an install() that decides the refusal against the
     * `--database` connection of the command, so `migrate --database=<a third connection>`
     * creates the package tables in the application's own SQLite file when orrery.database names
     * a second connection on that file (refused at runtime), because the queue's empty
     * `connection` key then resolves to the third connection.
     * Still passes: an install() that refuses every orrery.database on a SQLite file that some
     * connection uses (test_migrate_on_the_dedicated_sqlite_package_file_itself_is_accepted
     * rejects that).
     */
    public function test_migrate_with_a_third_connection_refuses_a_mirror_on_the_default_file(): void
    {
        $this->useOwnConnections(sqlite: true);
        $file = $this->sqliteFile();
        $config = $this->app['config'];

        foreach (['queue.connections', 'database.default', 'database.connections.main_migration_app', 'database.connections.main_migration_refused'] as $key) {
            $this->save($key);
        }

        $config->set('database.connections.main_migration_app', $this->sqliteConfig($file));
        $config->set('database.connections.main_migration_refused', $this->sqliteConfig($file));
        $config->set('database.default', 'main_migration_app');
        $config->set('queue.connections.main_migration_default', ['driver' => 'database', 'connection' => null, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('orrery.database', 'main_migration_refused');
        PackageSession::releaseAll();

        $refusal = $this->app->make(PackageSession::class)->mirrorRefusal();
        self::assertNotNull($refusal);

        try {
            $this->migrate(self::REPO);
            self::fail('A package connection on the application\'s default file got its tables.');
        } catch (Throwable $refused) {
            self::assertStringContainsString($refusal, $refused->getMessage());
        }

        /** @var Connection $shared */
        $shared = $this->app['db']->connection('main_migration_refused');
        self::assertFalse(PackageSchema::hasPackageTables($shared));
    }

    /**
     * Rejects (review, items 4 and 6): a loaded dump of a newer release (its migrations rows name
     * an upgrade migration above VERSION) that gets the marker of this release, an install() that
     * marks it instead of refusing with the downgrade hint, and a SchemaLoaded listener whose
     * error stops the host's migrate instead of being reported.
     * Still passes: a refusal that also fires for a newer upgrade migration recorded on another
     * connection (none is read there).
     */
    public function test_a_loaded_dump_of_a_newer_release_is_refused_and_the_listener_only_reports(): void
    {
        Exceptions::fake();
        $mirror = $this->useOwnConnections(sqlite: true);
        PackageSchema::create($mirror->getSchemaBuilder());
        $newer = PackageSchema::currentVersion() + 1;
        $mirror->getSchemaBuilder()->create('migrations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $mirror->table('migrations')->insert(['migration' => "2027_01_01_000000_upgrade_orrery_schema_to_v{$newer}", 'batch' => 1]);

        PackageSchema::afterSchemaLoad($mirror);
        self::assertNull(PackageSchema::installedVersion($mirror), 'No marker for a newer schema.');
        Exceptions::assertReported(static fn (WatchedOperationFailed $reported): bool => $reported->operation === 'schema:after_load');

        try {
            $this->migrate(self::REPO);
            self::fail('A dump of a newer release was marked.');
        } catch (Throwable $refused) {
            self::assertStringContainsString(PackageSchema::downgradeHint($newer, PackageSchema::currentVersion()), $refused->getMessage());
        }

        self::assertNull(PackageSchema::installedVersion($mirror));
    }

    /**
     * The pretend runs of migrate and migrate:rollback against $mirror, then the real ones.
     */
    private function assertPretendChangesNothing(Connection $mirror): void
    {
        $output = $this->migrate(self::REPO, ['--pretend' => true]);
        self::assertFalse(PackageSchema::hasPackageTables($mirror), 'migrate --pretend created no package table.');
        self::assertStringContainsStringIgnoringCase('create table', $output, 'The pretend run shows the package statements.');
        self::assertStringContainsString('orrery_jobs', $output);

        $this->migrate(self::REPO);
        self::assertTrue(PackageSchema::hasPackageTables($mirror));
        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
        $mirror->table('orrery_settings')->insert(['key' => 'main_migration_probe', 'value' => 'kept']);

        $output = $this->command('migrate:rollback', ['--database' => self::REPO, '--path' => self::path(), '--realpath' => true, '--pretend' => true]);
        self::assertStringContainsStringIgnoringCase('drop table', $output, 'The pretend rollback shows the drops.');
        self::assertTrue(PackageSchema::hasPackageTables($mirror), 'migrate:rollback --pretend dropped no package table.');
        self::assertSame('kept', $mirror->table('orrery_settings')->where('key', 'main_migration_probe')->value('value'));
        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));

        $this->command('migrate:rollback', ['--database' => self::REPO, '--path' => self::path(), '--realpath' => true]);
        self::assertFalse(PackageSchema::hasPackageTables($mirror), 'The real rollback drops the package tables.');
    }

    /**
     * Rejects: an up() that runs create() again when the package tables and their marker
     * already exist, so a second `migrate` from another migrations table (migrate:fresh with a
     * separate package connection, a migrate per tenant database) fails with "table already
     * exists"; and an up() that drops or empties the existing tables instead of keeping them.
     * Still passes: an up() that skips any schema with the current marker even when a table of
     * it is missing (the marker decides).
     */
    public function test_a_second_migrate_from_another_migrations_table_keeps_the_installed_schema(): void
    {
        $mirror = $this->useOwnConnections();

        $this->migrate(self::REPO);
        $mirror->table('orrery_settings')->insert(['key' => 'main_migration_probe', 'value' => 'kept']);

        $this->migrate(self::OTHER_REPO);

        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
        self::assertSame('kept', $mirror->table('orrery_settings')->where('key', 'main_migration_probe')->value('value'));
    }

    /**
     * Rejects: an up() that marks package tables without a marker table (a schema from
     * before Orrery 0.1, or a create() that stopped half-way) as current, or runs create() over
     * them and fails on "table already exists"; the refusal names the pre-v0.1 steps. And a
     * marker above this release that up() overwrites instead of refusing as a downgrade.
     */
    public function test_up_refuses_an_unknown_or_newer_schema(): void
    {
        $mirror = $this->useOwnConnections();
        $schema = $mirror->getSchemaBuilder();
        PackageSchema::create($schema);
        $schema->drop(PackageSchema::MARKER);

        try {
            $this->migrate(self::REPO);
            self::fail('Package tables without a marker table were accepted.');
        } catch (Throwable $refused) {
            self::assertStringContainsString(PackageSchema::UPGRADE_STEPS, $refused->getMessage());
        }

        self::assertFalse($schema->hasTable(PackageSchema::MARKER), 'The refusal wrote no marker.');

        PackageSchema::create($schema, [PackageSchema::MARKER]);
        $newer = PackageSchema::currentVersion() + 1;
        $mirror->table(PackageSchema::MARKER)->insert(['id' => 1, 'version' => $newer]);

        try {
            $this->migrate(self::REPO);
            self::fail('A newer schema was accepted.');
        } catch (Throwable $refused) {
            self::assertStringContainsString(PackageSchema::downgradeHint($newer, PackageSchema::currentVersion()), $refused->getMessage());
        }

        self::assertSame($newer, PackageSchema::installedVersion($mirror));
    }

    /**
     * Rejects: an up() that refuses or recreates a complete schema whose marker table holds
     * no row (the state of a database loaded from a schema dump) instead of writing the marker.
     */
    public function test_up_writes_the_marker_of_a_schema_whose_marker_table_is_empty(): void
    {
        $mirror = $this->useOwnConnections();
        PackageSchema::create($mirror->getSchemaBuilder());
        $mirror->table('orrery_settings')->insert(['key' => 'main_migration_probe', 'value' => 'kept']);
        self::assertNull(PackageSchema::installedVersion($mirror));

        $this->migrate(self::REPO);

        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
        self::assertSame('kept', $mirror->table('orrery_settings')->where('key', 'main_migration_probe')->value('value'));
    }

    /**
     * Rejects: a `migrate` that creates the package tables inside the application's own
     * SQLite file of a queue connection (an orrery.database that the package refuses at runtime)
     * and exits 0, instead of stopping with the refusal.
     * Still passes: a refusal with another message that also creates nothing (the message is
     * mirrorRefusal()'s own here).
     */
    public function test_migrate_refuses_a_refused_package_connection(): void
    {
        $this->useOwnConnections();
        $file = $this->sqliteFile();
        $config = $this->app['config'];
        $this->save('queue.connections');
        $config->set('database.connections.main_migration_refused', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
        $config->set('queue.connections.main_migration_queue', ['driver' => 'database', 'connection' => 'main_migration_refused', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('orrery.database', 'main_migration_refused');
        PackageSession::releaseAll();

        $refusal = $this->app->make(PackageSession::class)->mirrorRefusal();
        self::assertNotNull($refusal);

        try {
            $this->migrate(self::REPO);
            self::fail('A refused package connection got its tables.');
        } catch (Throwable $refused) {
            self::assertStringContainsString($refusal, $refused->getMessage());
        }

        /** @var Connection $refusedConnection */
        $refusedConnection = $this->app['db']->connection('main_migration_refused');
        self::assertFalse(PackageSchema::hasPackageTables($refusedConnection), 'Nothing was created in the application\'s file.');
    }

    /**
     * Rejects: a database loaded from `php artisan schema:dump` (structure and the
     * migrations rows only) that keeps an empty marker table, so /api/health and orrery:install
     * call a complete schema one from before Orrery 0.1 and give the steps that drop every
     * package table. The SchemaLoaded listener writes the marker.
     * Still passes: a listener that writes the marker of a dump that lacks a table of this
     * release (no step above the baseline exists yet to add one).
     */
    public function test_a_database_loaded_from_a_schema_dump_gets_its_marker(): void
    {
        if ((new ExecutableFinder)->find('sqlite3') === null) {
            self::markTestSkipped('schema:dump of a SQLite file needs the sqlite3 command.');
        }

        $config = $this->app['config'];
        $this->save('orrery.database');
        $this->save('database.connections.'.self::MIRROR);
        $config->set('database.connections.'.self::MIRROR, $this->sqliteConfig($this->sqliteFile()));
        $config->set('orrery.database', self::MIRROR);
        PackageSession::releaseAll();

        $this->migrate(self::MIRROR);
        $dump = (string) tempnam(sys_get_temp_dir(), 'orrery-dump-');
        $this->temporary[] = $dump;
        $this->command('schema:dump', ['--database' => self::MIRROR, '--path' => $dump]);
        self::assertStringContainsString('orrery_schema', (string) file_get_contents($dump));

        // A new environment: an empty database file, migrated from the dump.
        $config->set('database.connections.'.self::MIRROR, $this->sqliteConfig($this->sqliteFile()));
        $this->app['db']->purge(self::MIRROR);
        PackageSession::releaseAll();
        /** @var Connection $mirror */
        $mirror = $this->app['db']->connection(self::MIRROR);
        self::assertFalse(PackageSchema::hasPackageTables($mirror));

        $this->migrate(self::MIRROR, ['--schema-path' => $dump]);

        self::assertTrue(PackageSchema::hasPackageTables($mirror), 'The dump was loaded.');
        self::assertSame(PackageSchema::currentVersion(), PackageSchema::installedVersion($mirror));
    }

    /**
     * Laravel 13.31 and later name a direct connection `<name>::direct`
     * (laravel/framework#61435); 13.30 names it by its plain name.
     */
    private function directConnectionsHaveTheSuffix(): bool
    {
        return version_compare($this->app->version(), '13.31.0', '>=');
    }

    /**
     * Configures REPO, OTHER_REPO and MIRROR (orrery.database) and returns the MIRROR connection.
     */
    private function useOwnConnections(bool $direct = false, bool $sqlite = false): Connection
    {
        $config = $this->app['config'];

        foreach (['orrery.database', 'database.connections.'.self::REPO, 'database.connections.'.self::OTHER_REPO, 'database.connections.'.self::MIRROR] as $key) {
            $this->save($key);
        }

        $server = ! $sqlite && in_array($this->testEnvironment()->driver, ['mysql', 'pgsql'], true);

        if ($direct && ! $server) {
            self::markTestSkipped('A `direct` key needs a server engine: run it with bin/test pgsql (or mysql).');
        }

        $primary = (array) $config->get('database.connections.'.TestEnvironment::PRIMARY);

        foreach ([self::REPO => 'mmr_', self::OTHER_REPO => 'mmo_', self::MIRROR => 'mmm_'] as $name => $prefix) {
            $config->set('database.connections.'.$name, $server ? array_replace($primary, ['prefix' => $prefix]) : $this->sqliteConfig($this->sqliteFile()));
        }

        if ($direct) {
            // Laravel's `direct` key: the same server, so the test reads what the migrator wrote.
            $config->set('database.connections.'.self::MIRROR.'.direct', ['host' => $primary['host'] ?? '127.0.0.1', 'port' => $primary['port'] ?? null]);
        }

        $config->set('orrery.database', self::MIRROR);
        PackageSession::releaseAll();
        self::assertFalse($this->app->make(PackageSession::class)->mirrorIsSibling());

        /** @var Connection $mirror */
        $mirror = $this->app['db']->connection(self::MIRROR);
        self::assertFalse(PackageSchema::hasPackageTables($mirror));

        // Laravel 12 has no `direct` connection key, and so no hasDirectConnection().
        if ($direct && (! method_exists($mirror, 'hasDirectConnection') || ! $mirror->hasDirectConnection())) {
            self::markTestSkipped('This Laravel version has no `direct` connection key.');
        }

        return $mirror;
    }

    /**
     * Drops what the test left on the copies of a server database (their prefixed tables).
     */
    private function dropOwnTables(): void
    {
        $config = (array) $this->app['config']->get('database.connections.'.self::MIRROR);

        if (! in_array($config['driver'] ?? null, ['mysql', 'pgsql'], true) || ! array_key_exists('database.connections.'.self::MIRROR, $this->saved)) {
            return;
        }

        $this->app['db']->purge(self::MIRROR);
        /** @var Connection $mirror */
        $mirror = $this->app['db']->connection(self::MIRROR);
        PackageSchema::drop($mirror->getSchemaBuilder());

        foreach ([self::REPO, self::OTHER_REPO] as $name) {
            $this->app['db']->purge($name);
            $this->app['db']->connection($name)->getSchemaBuilder()->dropIfExists('migrations');
        }
    }

    /**
     * Runs `migrate` of the package migrations on $repository and returns its output.
     *
     * @param  array<string, mixed>  $options
     */
    private function migrate(string $repository, array $options = []): string
    {
        return $this->command('migrate', ['--database' => $repository, '--path' => self::path(), '--realpath' => true] + $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function command(string $command, array $options): string
    {
        $status = Artisan::call($command, $options);
        $output = Artisan::output();
        self::assertSame(0, $status, "{$command} failed: {$output}");

        return $output;
    }

    private static function path(): string
    {
        return (string) realpath(__DIR__.'/../../../database/migrations');
    }

    private function save(string $key): void
    {
        if (! array_key_exists($key, $this->saved)) {
            $this->saved[$key] = $this->app['config']->get($key);
        }
    }

    private function sqliteFile(): string
    {
        $file = SqliteFiles::create();
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteConfig(string $file): array
    {
        return ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false];
    }
}
