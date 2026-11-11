<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\OperatorContext;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageSessionReleased;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Storage\PooledRelationUnresolved;
use Justrau\Orrery\Storage\PooledSessionRefused;
use Justrau\Orrery\Storage\PostgresSession;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\KillsServerSessions;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use LogicException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The package session: one session per database
 * connection, its settings per engine, the pooled Postgres mode, the
 * endpoint identity and the leased access modes of a host SQLite file.
 *
 * The tests set orrery.sessions.* with config()->set(): the keys enter
 * config/orrery.php. Each test binds PackageSession as a singleton
 * itself, and its application's end releases every session.
 */
final class PackageSessionTest extends TestCase
{
    /** The sessions' own statements fire no event: no harness bridge. */
    protected bool $packageStatementEvents = false;

    use KillsServerSessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(PackageSession::class);
        $this->beforeApplicationDestroyed(static function (): void {
            PackageSession::resetForTesting();
        });

        // The harness's SQLite files are the queue's, so HostFiles makes them
        // host files, and the first layer admits no automatic access there (a later one adds the
        // D18 admission and the sibling mirror). The tests that are not about
        // host files treat them as files of the package.
        HostFiles::useForTesting(static fn (string $connection): ?bool => in_array($connection, [TestEnvironment::PRIMARY, TestEnvironment::MIRROR], true) ? false : null);
    }

    /**
     * The probe of the combined begin: PDO's pgsql driver reads
     * the transaction state from libpq, so a BEGIN that one exec() sends is
     * a real transaction for PDO and for Laravel's commit() and rollBack().
     *
     * Rejects: an exec('BEGIN') that PDO does not see.
     */
    #[FreshSchema]
    public function test_postgres_combined_begin_opens_a_real_transaction(): void
    {
        $this->skipUnlessDriver('pgsql');
        $name = $this->pooledConnection();
        $session = $this->packageSession()->for($name);

        self::assertInstanceOf(PostgresSession::class, $session);
        self::assertTrue($session->isPooled());
        $this->createScratchTable($this->app['db']->connection($name));

        $session->beginTransaction();
        self::assertTrue($session->getPdo()->inTransaction(), 'PDO sees the transaction of the combined begin.');
        self::assertSame('5s', $session->scalar('show lock_timeout'), 'The combined begin set the local lock timeout.');
        self::assertSame('read committed', $session->scalar('show transaction_isolation'));
        $session->table('session_scratch')->insert(['id' => 1, 'value' => 'committed']);
        $session->commit();

        self::assertFalse($session->getPdo()->inTransaction());
        self::assertSame(['committed'], $this->app['db']->connection($name)->table('session_scratch')->pluck('value')->all());
        self::assertSame('0', (string) $session->getPdo()->query('show lock_timeout')->fetchColumn(), 'SET LOCAL ended with the transaction.');

        $session->beginTransaction();
        $session->table('session_scratch')->insert(['id' => 2, 'value' => 'rolled back']);
        $session->rollBack();

        self::assertFalse($session->getPdo()->inTransaction());

        $session->beginTransaction();
        self::assertTrue($session->getPdo()->inTransaction(), 'A package transaction after a rollback works.');
        $session->table('session_scratch')->insert(['id' => 3, 'value' => 'second']);
        $session->commit();

        self::assertSame(['committed', 'second'], $this->app['db']->connection($name)->table('session_scratch')->orderBy('id')->pluck('value')->all());
    }

    /**
     * Rejects: any session-level SET on a pooled session, the connector's included.
     */
    #[FreshSchema]
    public function test_pooled_postgres_leaves_no_session_setting(): void
    {
        $this->skipUnlessDriver('pgsql');
        $name = $this->pooledConnection('pooled_pg', [
            'search_path' => '"$user", public',
            'timezone' => 'Europe/Vilnius',
            'synchronous_commit' => 'off',
            'isolation_level' => 'serializable',
        ]);
        $application = $this->app['db']->connection($name);
        $applicationPath = $application->scalar('show search_path');
        self::assertSame('Europe/Vilnius', $application->scalar('show timezone'), 'The control: the application connection applies its settings.');
        $this->createScratchTable($application);

        $sessions = $this->packageSession();
        $session = $sessions->for($name);
        self::assertSame([], $this->sessionSettings($session), 'After connect.');

        $session->transaction(static function (Connection $tx): void {
            $tx->table('session_scratch')->insert(['id' => 1, 'value' => 'in a package transaction']);
        });
        self::assertSame([], $this->sessionSettings($session), 'After a package transaction.');

        self::assertSame(1, $sessions->bounded($session, static fn (Connection $bounded): int => $bounded->table('session_scratch')->count()));
        self::assertSame([], $this->sessionSettings($session), 'After a bounded read.');

        $session->reconnect();
        self::assertSame([], $this->sessionSettings($session), 'After a reconnect.');
        self::assertSame($applicationPath, $application->scalar('show search_path'), "The application connection's own search_path is unchanged.");

        // The control: a direct session of the same configuration shows its settings.
        $this->app['config']->set('orrery.sessions.pooled', false);
        $direct = $sessions->for($name);
        self::assertNotSame($session, $direct);
        self::assertContains('lock_timeout', array_column($this->sessionSettings($direct), 'name'), 'The query finds session settings where they exist.');
    }

    /**
     * Rejects: sending a configured search_path that is not the server default on a pooled session, and a silent fallback.
     */
    public function test_pooled_session_refuses_namespace_settings(): void
    {
        $this->skipUnlessDriver('pgsql');
        $sessions = $this->packageSession();

        $this->pooledConnection('tenant_pg', ['search_path' => 'tenant']);

        try {
            $sessions->for('tenant_pg');
            self::fail('A search_path other than the server default is refused.');
        } catch (PooledSessionRefused $refused) {
            self::assertSame('tenant_pg', $refused->connection);
            self::assertSame('Orrery cannot use the pooled connection [tenant_pg]: its search_path tenant is not the server default "$user", public; use a direct connection.', $refused->getMessage());
        }

        $this->pooledConnection('public_pg', ['search_path' => 'public']);
        $this->expectRefusal(static fn () => $sessions->for('public_pg'), 'its search_path public is not the server default');

        $this->pooledConnection('option_pg', ['server_options' => ['Search_Path' => 'public']]);
        $this->expectRefusal(static fn () => $sessions->for('option_pg'), 'its server_options set Search_Path');

        $this->pooledConnection('role_pg', ['server_options' => ['role' => 'postgres']]);
        $this->expectRefusal(static fn () => $sessions->for('role_pg'), 'its server_options set role');

        $this->pooledConnection('default_pg', ['search_path' => ['$user', 'public']]);
        self::assertSame(1, (int) $sessions->for('default_pg')->scalar('select 1'), 'The server default, as a list, is accepted.');

        self::assertSame([], array_values(array_filter(
            PackageSession::sessionsForTesting(),
            static fn (array $entry): bool => $entry['name'] !== 'default_pg',
        )), 'No refused session is cached.');
    }

    /**
     * Rejects: hasTable() through current_schema(), and a pooled statement that leaves a package table unqualified.
     */
    #[FreshSchema]
    public function test_pooled_existence_checks_use_the_map(): void
    {
        $this->skipUnlessDriver('pgsql');
        $name = $this->pooledConnection();
        $application = $this->app['db']->connection($name);
        // The physical names carry the run's table prefix (ORRERY_TEST_PREFIX).
        $p = $application->getTablePrefix();
        [$probe, $twin, $late] = [$p.'orrery_c4a_probe', $p.'orrery_c4a_twin', $p.'orrery_c4a_late'];
        $application->statement('create schema c4a_other');
        $application->statement("create table public.{$probe} (id integer primary key, value varchar(64))");
        $application->statement("insert into public.{$probe} values (1, 'default schema')");
        $application->statement("create table public.{$twin} (id integer primary key, value varchar(64))");
        $application->statement("insert into public.{$twin} values (1, 'default twin')");
        $application->statement("create table c4a_other.{$twin} (id integer primary key, value varchar(64))");
        $application->statement("insert into c4a_other.{$twin} values (1, 'other twin')");
        $this->beforeApplicationDestroyed(static function () use ($application, $probe, $twin, $late): void {
            PackageSession::releaseAll();
            $application->statement('drop schema c4a_other cascade');
            $application->statement("drop table if exists public.{$probe}, public.{$twin}, public.{$late}");
        });

        $session = $this->packageSession()->for($name);
        self::assertInstanceOf(PostgresSession::class, $session);

        // Another client left a search path on the pooled backend.
        $session->getPdo()->exec('SET search_path = c4a_other');
        self::assertSame('c4a_other', $session->scalar('select current_schema()'), 'The control: the backend carries the leftover path.');
        self::assertFalse((bool) $session->scalar('select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = ? and n.oid = c.relnamespace)', [$probe]), "The control: Laravel's current_schema() check misses the table.");

        $schema = $session->getSchemaBuilder();
        self::assertTrue($schema->hasTable('orrery_c4a_probe'), 'hasTable() answers for the qualified relation.');
        self::assertFalse($schema->hasTable('orrery_c4a_missing'));
        self::assertSame(['id', 'value'], $schema->getColumnListing('orrery_c4a_probe'));
        self::assertSame('default schema', $session->table('orrery_c4a_probe as p')->where('p.id', 1)->value('p.value'), 'An alias stays an alias.');
        self::assertSame('default twin', $session->table('orrery_c4a_twin')->where('orrery_c4a_twin.id', 1)->value('value'), 'The default schema wins over the leftover path.');
        self::assertSame(sprintf('select * from "public"."%s" as "%st" where "%st"."id" = ?', $twin, $p, $p), $session->table('orrery_c4a_twin as t')->where('t.id', 1)->toSql());
        self::assertSame(
            sprintf('select * from (select * from "public"."%s") as "%ssub"', $twin, $p),
            $session->query()->fromSub($session->table('orrery_c4a_twin'), 'sub')->toSql(),
            'A subquery alias is not a relation, so it stays unqualified.',
        );

        // A table created after the session resolved its map.
        $application->statement("create table public.{$late} (id integer primary key)");
        self::assertTrue($schema->hasTable('orrery_c4a_late'), 'A table missing from the map resolves once more.');
        self::assertSame(0, $session->table('orrery_c4a_late')->count());
        self::assertSame('c4a_other', $session->scalar('select current_schema()'), 'The resolution left no setting behind.');

        // The fence check of the operations resolves through the same map.
        $fences = $p.'orrery_fences';
        $createdFences = ! (bool) $application->scalar('select to_regclass(?) is not null', ['public.'.$fences]);

        if ($createdFences) {
            $application->statement("create table public.{$fences} (operation_id char(32), slot varchar(8), primary key (operation_id, slot))");
        }

        try {
            self::assertTrue($this->app->make(Fence::class)->available($name), 'Fence::available() finds the table behind the leftover path.');
        } finally {
            if ($createdFences) {
                $application->statement("drop table public.{$fences}");
            }
        }

        try {
            $schema->create('orrery_c4a_new', static function (Blueprint $table): void {
                $table->integer('id');
            });
            self::fail('A pooled session runs no DDL.');
        } catch (LogicException $refused) {
            self::assertStringContainsString('runs no DDL', $refused->getMessage());
            self::assertFalse((bool) $application->scalar('select to_regclass(?) is not null', [$p.'orrery_c4a_new']));
        }

        try {
            $session->table('orrery_c4a_missing')->count();
            self::fail('A package relation that does not resolve is an error of the statement.');
        } catch (PooledRelationUnresolved $unresolved) {
            self::assertSame($p.'orrery_c4a_missing', $unresolved->relation);
        }
    }

    /**
     * Rejects: relying on the server default isolation.
     */
    #[FreshSchema]
    public function test_postgres_sessions_read_committed(): void
    {
        $this->skipUnlessDriver('pgsql');
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $database = (string) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database');
        $quoted = '"'.str_replace('"', '""', $database).'"';
        $primary->statement("alter database {$quoted} set default_transaction_isolation = 'repeatable read'");

        try {
            $this->defineConnection('plain_pg', TestEnvironment::PRIMARY);
            $plain = $this->app['db']->connection('plain_pg');
            self::assertSame('repeatable read', $plain->transaction(static fn (Connection $tx): mixed => $tx->scalar('show transaction_isolation')), 'The control: a new connection gets the database default.');

            foreach ([false, true] as $pooled) {
                PackageSession::releaseAll();
                $name = $this->pooledConnection($pooled ? 'pooled_pg' : 'direct_pg');
                $this->app['config']->set('orrery.sessions.pooled', $pooled ? [$name] : false);
                $session = $this->packageSession()->for($name);
                $mode = $pooled ? 'pooled' : 'direct';

                $session->beginTransaction();
                self::assertSame('read committed', $session->scalar('show transaction_isolation'), "A {$mode} package transaction reads committed.");
                $session->commit();

                if ($pooled) {
                    self::assertSame([], $this->sessionSettings($session), 'A pooled session leaves no session setting.');
                }
            }
        } finally {
            PackageSession::releaseAll();
            $primary->statement("alter database {$quoted} reset default_transaction_isolation");
        }
    }

    /**
     * Rejects: an identity from `database` and `port` alone.
     */
    public function test_endpoint_identity_follows_connect_via(): void
    {
        $this->skipUnlessDriver('pgsql');
        // A direct session (the pooled harness run sets orrery.sessions.pooled).
        $this->app['config']->set('orrery.sessions.pooled', false);
        $sessions = $this->packageSession();
        $this->defineConnection('via_pg', TestEnvironment::PRIMARY);
        $first = $sessions->for('via_pg');
        $firstPrint = $sessions->fingerprint('via_pg');
        $mirror = $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.database').'_mirror';

        $this->app['config']->set('database.connections.via_pg.connect_via_database', $mirror);
        $second = $sessions->for('via_pg');

        self::assertNotSame($first, $second, 'connect_via_database gives a new session.');
        self::assertNotSame($firstPrint, $sessions->fingerprint('via_pg'));
        self::assertSame($mirror, $second->scalar('select current_database()'), 'The session connects through connect_via_database.');
        $secondPrint = $sessions->fingerprint('via_pg');

        $this->app['config']->set('database.connections.via_pg.connect_via_port', 1);
        $third = $sessions->for('via_pg');
        $thirdPrint = $sessions->fingerprint('via_pg');

        self::assertNotSame($second, $third, 'connect_via_port gives a new session.');
        self::assertNotSame($secondPrint, $thirdPrint);

        // The effective port is connect_via_port, so `port` alone no longer changes the endpoint
        // (a changed configuration still gives a new session).
        $this->app['config']->set('database.connections.via_pg.port', 2);
        self::assertSame($thirdPrint, $sessions->fingerprint('via_pg'));
    }

    /**
     * Rejects: a session cache keyed by the endpoint alone, so a rotated
     * password (or any other changed setting of the connection) never
     * reaches a package session: its reconnector keeps the first
     * configuration.
     */
    public function test_a_changed_password_gives_a_new_session(): void
    {
        $sessions = $this->packageSession();
        $file = $this->sqliteFile("insert into session_scratch values (1, 'the file')");
        $this->app['config']->set('database.connections.rotated', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'password' => 'first']);

        $old = $sessions->for('rotated');
        $print = $sessions->fingerprint('rotated');
        self::assertSame($old, $sessions->for('rotated'), 'an unchanged configuration keeps its session');

        $this->app['config']->set('database.connections.rotated.password', 'second');
        $new = $sessions->for('rotated');

        self::assertNotSame($old, $new, 'the rotated password kept the old session');
        self::assertNull($old->getRawPdo(), 'The old session is closed.');
        self::assertSame($print, $sessions->fingerprint('rotated'), 'the endpoint identity holds no password');
        self::assertSame('the file', $new->table('session_scratch')->value('value'));
    }

    /**
     * Rejects: a session cache keyed by the name alone.
     */
    public function test_session_is_rebuilt_when_its_config_changes(): void
    {
        $sessions = $this->packageSession();
        [$first, $second] = [$this->sqliteFile("insert into session_scratch values (1, 'first file')"), $this->sqliteFile("insert into session_scratch values (1, 'second file')")];
        $this->app['config']->set('database.connections.retarget', ['driver' => 'sqlite', 'url' => null, 'database' => $first, 'prefix' => '']);

        $old = $sessions->for('retarget');
        $oldPdo = $old->getPdo();
        self::assertSame('first file', $old->table('session_scratch')->value('value'));

        $this->app['config']->set('database.connections.retarget.database', $second);
        $new = $sessions->for('retarget');

        self::assertNotSame($old, $new);
        self::assertNotSame($oldPdo, $new->getPdo(), 'The next for() gives a new PDO.');
        self::assertSame('second file', $new->table('session_scratch')->value('value'));
        self::assertNull($old->getRawPdo(), 'The old session is closed.');
        // Only the new session of `retarget` stays. On a SQLite file the harness has
        // also opened the session of the sibling mirror file, so count by name.
        self::assertSame([$new], array_column(array_filter(PackageSession::sessionsForTesting(), static fn (array $entry): bool => $entry['name'] === 'retarget'), 'connection'));

        if (! $this->testEnvironment()->isServer()) {
            return;
        }

        $driver = $this->testEnvironment()->driver;
        $this->defineConnection('moving', TestEnvironment::PRIMARY);
        $before = $sessions->for('moving');
        $beforePdo = $before->getPdo();

        if ($driver === 'pgsql') {
            // The same server, reached through another host name.
            $this->app['config']->set('database.connections.moving.host', 'localhost');
        } else {
            // MySQL's `localhost` means a socket, so this case moves the database instead.
            $this->app['config']->set('database.connections.moving.database', $this->app['config']->get('database.connections.moving.database').'_mirror');
        }

        $after = $sessions->for('moving');

        self::assertNotSame($before, $after);
        self::assertNotSame($beforePdo, $after->getPdo());
        self::assertSame(1, (int) $after->scalar('select 1'));
    }

    /**
     * Rejects: a session that keeps the `read` array, so it reads a stale replica.
     */
    public function test_session_reads_the_writer(): void
    {
        $writer = $this->sqliteFile("insert into session_scratch values (1, 'committed on the writer')");
        $replica = $this->sqliteFile();
        $this->app['config']->set('database.connections.split', [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $writer,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'read' => ['database' => $replica],
            'write' => ['database' => $writer],
            'sticky' => true,
        ]);

        self::assertSame(0, $this->app['db']->connection('split')->table('session_scratch')->count(), 'The control: the application reads the stale replica.');
        self::assertSame(1, $this->packageSession()->for('split')->table('session_scratch')->count(), 'The package session reads the writer.');

        $config = $this->packageSession()->writerConfig('split');
        self::assertSame($writer, $config['database']);
        self::assertArrayNotHasKey('read', $config);
        self::assertArrayNotHasKey('write', $config);
        self::assertArrayNotHasKey('sticky', $config);
    }

    /**
     * Rejects: copying the application's `isolation_level` into the package session.
     */
    public function test_inherited_read_uncommitted_is_dropped(): void
    {
        $this->skipUnlessDriver('mysql');
        $this->defineConnection('dirty_reads', TestEnvironment::PRIMARY, ['isolation_level' => 'READ UNCOMMITTED']);
        $application = $this->app['db']->connection('dirty_reads');

        self::assertSame('READ-UNCOMMITTED', $application->scalar('select @@session.transaction_isolation'), 'The control: the application reads uncommitted rows.');

        $session = $this->packageSession()->for('dirty_reads');

        self::assertSame($session->scalar('select @@global.transaction_isolation'), $session->scalar('select @@session.transaction_isolation'), 'The package session keeps the server default.');
        self::assertNotSame('READ-UNCOMMITTED', $session->scalar('select @@session.transaction_isolation'));
    }

    /**
     * Rejects: a MySQL session that inherits autocommit off, so a package insert stays in an implicit transaction.
     */
    #[FreshSchema]
    public function test_mysql_package_sessions_run_in_autocommit_whatever_the_application_sets(): void
    {
        $this->skipUnlessDriver('mysql');
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $this->createScratchTable($primary);
        $options = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.options', []);
        $this->defineConnection('no_autocommit_option', TestEnvironment::PRIMARY, ['options' => [PDO::ATTR_AUTOCOMMIT => false] + $options]);
        $this->defineConnection('no_autocommit_init', TestEnvironment::PRIMARY, ['options' => [PackageSession::mysqlInitCommandAttribute() => 'SET autocommit = 0'] + $options]);
        $this->defineConnection('no_autocommit_factory', TestEnvironment::PRIMARY);
        // orrery.sessions.pooled applies to Postgres only.
        $this->app['config']->set('orrery.sessions.pooled', ['no_autocommit_option', 'no_autocommit_init', 'no_autocommit_factory']);
        PackageSession::usePdoFactoryForTesting(function (string $name, array $config): ?PDO {
            return $name === PackageSession::NAME_PREFIX.'no_autocommit_factory'
                ? $this->rawPdo($config, [PDO::ATTR_AUTOCOMMIT => false])
                : null;
        });

        $sessions = $this->packageSession();
        $independent = $this->rawPdo((array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY));
        $id = 0;

        foreach (['no_autocommit_option', 'no_autocommit_init', 'no_autocommit_factory'] as $source) {
            $application = $source === 'no_autocommit_factory' ? null : $this->app['db']->connection($source);

            if ($application !== null) {
                self::assertSame(0, (int) $application->scalar('select @@autocommit'), "The control: {$source} runs with autocommit off.");
            }

            $session = $sessions->for($source);

            foreach (['first connect', 'after a reconnect'] as $round) {
                if ($round === 'after a reconnect') {
                    $this->endServerSession($session, $primary);
                }

                $id++;
                $session->table('session_scratch')->insert(['id' => $id, 'value' => $source]);

                self::assertSame(1, (int) $independent->query("select count(*) from {$primary->getTablePrefix()}session_scratch where id = {$id}")->fetchColumn(), "The package insert on {$source} ({$round}) is visible at once from an independent PDO.");
                self::assertSame(1, (int) $session->scalar('select @@autocommit'));
                self::assertSame(5, (int) $session->scalar('select @@session.lock_wait_timeout'));
            }

            if ($application !== null) {
                self::assertSame(0, (int) $application->scalar('select @@autocommit'), "The application connection {$source} keeps autocommit off.");
            }
        }

        self::assertFalse($this->app['config']->get('database.connections.no_autocommit_option.options')[PDO::ATTR_AUTOCOMMIT]);
        self::assertSame('SET autocommit = 0', $this->app['config']->get('database.connections.no_autocommit_init.options')[PackageSession::mysqlInitCommandAttribute()]);
    }

    /**
     * The direct-session variant of the worker test. The table-lock part
     * runs on the test bound (1 s); the metadata-lock part keeps the
     * production value, and it is the one MySQL test that waits for it.
     *
     * Rejects: a bound on row locks only, so a metadata lock waits for MySQL's default year.
     */
    #[FreshSchema]
    public function test_metadata_lock_wait_is_bounded(): void
    {
        $this->skipUnlessDriver('mysql');
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $this->createScratchTable($primary);
        $primary->table('session_scratch')->insert(['id' => 1, 'value' => 'pending']);
        $hostTimeouts = $this->mysqlTimeouts($primary);
        $sessions = $this->packageSession();
        PackageSession::useLockWaitSecondsForTesting(1);
        $short = $sessions->for(TestEnvironment::PRIMARY);
        $short->table('session_scratch')->count();

        // A table lock that another session holds.
        $table = $primary->getTablePrefix().'session_scratch';
        $writer = $this->holdMysql(["LOCK TABLES {$table} WRITE"]);

        try {
            $this->assertGivesUpWithinTheBound(static fn () => $short->table('session_scratch')->where('id', 1)->update(['value' => 'reserved']), 1);
        } finally {
            $writer->stop();
        }

        PackageSession::useLockWaitSecondsForTesting(null);
        $session = $sessions->for(TestEnvironment::PRIMARY);
        $session->table('session_scratch')->count();

        // A reader holds the table, and an ALTER waits behind it: every later statement queues behind the ALTER.
        $reader = $this->holdMysql(['BEGIN', "SELECT * FROM {$table}"]);
        $alter = $this->holdMysql(['SET SESSION lock_wait_timeout = 30', "ALTER TABLE {$table} ADD COLUMN c4a_extra INT NULL"], waitsForLock: true);

        try {
            $this->waitForMetadataLockWaiter($primary);
            $this->assertGivesUpWithinTheBound(static fn () => $session->table('session_scratch')->where('id', 1)->update(['value' => 'reserved']), PackageSession::LOCK_WAIT_SECONDS);
        } finally {
            $reader->stop();
            $alter->stop();
        }

        self::assertSame('pending', $primary->table('session_scratch')->where('id', 1)->value('value'), 'The row is intact.');
        self::assertSame($hostTimeouts, $this->mysqlTimeouts($primary), "The host's own timeout variables are unchanged.");

        $this->endServerSession($session, $primary);
        $session->scalar('select 1');
        self::assertSame(['lock_wait_timeout' => 5, 'innodb_lock_wait_timeout' => 5], $this->mysqlTimeouts($session), 'After a reconnect.');

        $this->defineConnection('factory_mysql', TestEnvironment::PRIMARY);
        PackageSession::usePdoFactoryForTesting(fn (string $name, array $config): ?PDO => $name === PackageSession::NAME_PREFIX.'factory_mysql' ? $this->rawPdo($config) : null);
        self::assertSame(['lock_wait_timeout' => 5, 'innodb_lock_wait_timeout' => 5], $this->mysqlTimeouts($sessions->for('factory_mysql')), 'On the factory-PDO path.');
    }

    /**
     * The test bounds of the slow lock and connect tests: a new session takes
     * the shortened lock wait and connect timeout on every path (direct, the
     * MySQL factory PDO, the pooled begin and the pooled autocommit bounds),
     * and null gives the production values back.
     *
     * Rejects: a seam that reaches only some session paths (the relation
     * resolution of a pooled session included), a settings key without
     * either seam, so a cached session keeps its old bounds, and a seam that
     * stays in effect after null.
     */
    public function test_test_bounds_reach_every_new_session(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        $driver = $this->testEnvironment()->driver;
        $this->app['config']->set('orrery.sessions.pooled', false);
        $sessions = $this->packageSession();
        $this->defineConnection('factory_bounds', TestEnvironment::PRIMARY);
        PackageSession::usePdoFactoryForTesting(fn (string $name, array $config): ?PDO => $driver === 'mysql' && $name === PackageSession::NAME_PREFIX.'factory_bounds' ? $this->rawPdo($config) : null);
        $pooled = $driver === 'pgsql' ? $this->pooledConnection('pooled_bounds') : null;
        $bounds = function (int $lock, int $connect) use ($sessions, $driver, $pooled): void {
            $direct = $sessions->for(TestEnvironment::PRIMARY);
            self::assertSame($connect, $direct->getConfig('options')[PDO::ATTR_TIMEOUT] ?? null, 'The connect timeout of the direct session.');

            if ($driver === 'mysql') {
                self::assertSame(['lock_wait_timeout' => $lock, 'innodb_lock_wait_timeout' => $lock], $this->mysqlTimeouts($direct), 'The direct session.');
                self::assertSame(['lock_wait_timeout' => $lock, 'innodb_lock_wait_timeout' => $lock], $this->mysqlTimeouts($sessions->for('factory_bounds')), 'The factory PDO.');

                return;
            }

            self::assertSame("{$lock}s", (string) $direct->scalar('show lock_timeout'), 'The direct session.');
            $session = $sessions->for((string) $pooled);
            self::assertInstanceOf(PostgresSession::class, $session);
            self::assertTrue($session->isPooled());
            self::assertSame($connect, $session->getConfig('options')[PDO::ATTR_TIMEOUT] ?? null, 'The connect timeout of the pooled session.');
            self::assertStringContainsString("SET LOCAL lock_timeout = '{$lock}s'", $session->autocommitBounds(), 'The pooled autocommit bounds.');
            $sent = [];
            PackageSession::recordForTesting(static function (string $kind, string $sql) use (&$sent): void {
                $sent[] = $sql;
            });

            try {
                $session->resolveRelations();
            } finally {
                PackageSession::recordForTesting(null);
            }

            self::assertNotSame([], array_filter($sent, static fn (string $sql): bool => str_contains($sql, "SET LOCAL ROLE NONE; SET LOCAL lock_timeout = '{$lock}s'")), 'The pooled relation resolution.');
            $session->beginTransaction();

            try {
                self::assertSame("{$lock}s", (string) $session->scalar('show lock_timeout'), 'The pooled begin.');
            } finally {
                $session->rollBack();
            }
        };

        $bounds(PackageSession::LOCK_WAIT_SECONDS, PackageSession::CONNECT_TIMEOUT_SECONDS);
        $before = $sessions->for(TestEnvironment::PRIMARY);

        PackageSession::useLockWaitSecondsForTesting(2);
        $locked = $sessions->for(TestEnvironment::PRIMARY);
        self::assertNotSame($before, $locked, 'A changed lock bound gives a new session, never the cached one.');

        PackageSession::useConnectTimeoutSecondsForTesting(3);
        self::assertNotSame($locked, $sessions->for(TestEnvironment::PRIMARY), 'A changed connect timeout gives a new session, never the cached one.');
        $bounds(2, 3);

        PackageSession::useLockWaitSecondsForTesting(null);
        PackageSession::useConnectTimeoutSecondsForTesting(null);
        $bounds(PackageSession::LOCK_WAIT_SECONDS, PackageSession::CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Rejects: no statement timeout on a bounded read, and one request-wide transaction.
     */
    public function test_bounded_read_times_out(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        $driver = $this->testEnvironment()->driver;
        $this->app['config']->set('orrery.sessions.statement_timeout_ms', 200);
        $sleep = $driver === 'mysql'
            // MySQL interrupts a lone SLEEP() and returns 1 without an error; a scan over rows gives error 3024.
            ? 'select sleep(2) from (select 1 union all select 2) as rows_to_scan'
            : 'select pg_sleep(2)';

        foreach ($driver === 'pgsql' ? [false, true] : [false] as $pooled) {
            PackageSession::releaseAll();
            $name = $pooled ? $this->pooledConnection('pooled_bounded') : TestEnvironment::PRIMARY;
            $sessions = $this->packageSession();
            $session = $sessions->for($name);
            $started = microtime(true);

            try {
                $sessions->bounded($session, static fn (Connection $bounded): mixed => $bounded->select($sleep));
                self::fail('The bounded read ends with a timeout.');
            } catch (QueryException $timeout) {
                self::assertMatchesRegularExpression('/statement timeout|maximum statement execution time/i', $timeout->getMessage());
            }

            self::assertLessThan(1.5, microtime(true) - $started, 'The read stopped at its limit.');
            self::assertSame(1, (int) $sessions->bounded($session, static fn (Connection $bounded): mixed => $bounded->scalar('select 1')), 'A second bounded part of the same request still answers.');
            self::assertSame(0, $session->transactionLevel());
        }
    }

    /**
     * Rejects: Laravel's default reconnector, which gives a PDO without the session settings or reconnects the host's own connection.
     */
    public function test_settings_survive_a_reconnect(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        // A direct session (the pooled harness run sets orrery.sessions.pooled).
        $this->app['config']->set('orrery.sessions.pooled', false);
        $driver = $this->testEnvironment()->driver;
        $host = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $hostPdo = $host->getPdo();
        $session = $this->packageSession()->for(TestEnvironment::PRIMARY);
        $expected = $driver === 'mysql'
            ? ['lock_wait_timeout' => 5, 'innodb_lock_wait_timeout' => 5, 'max_execution_time' => 10000, 'autocommit' => 1]
            : ['lock_timeout' => '5s', 'statement_timeout' => '10s', 'idle_in_transaction_session_timeout' => '30s'];

        self::assertSame($expected, $this->limits($session), 'After connect.');
        $before = $this->serverSessionId($session);

        $this->endServerSession($session, $host);

        self::assertSame($expected, $this->limits($session), 'The next statement reconnected, and the limits are set again.');
        self::assertNotSame($before, $this->serverSessionId($session), 'The session reconnected.');
        self::assertSame($hostPdo, $host->getPdo(), 'The host connection of the same name keeps its PDO.');
    }

    /**
     * Rejects: a kept event dispatcher, so package statements reach Telescope, Pulse or a test's query count.
     */
    #[FreshSchema]
    public function test_package_statements_fire_no_query_events(): void
    {
        $this->skipOnInMemory();
        $this->createScratchTable($this->app['db']->connection(TestEnvironment::PRIMARY));
        $events = [];
        foreach ([QueryExecuted::class, TransactionBeginning::class, TransactionCommitted::class] as $event) {
            $this->app['events']->listen($event, static function (object $fired) use (&$events): void {
                $events[] = $fired::class;
            });
        }

        $session = $this->packageSession()->for(TestEnvironment::PRIMARY);
        $recorded = PackageStatements::record(function () use ($session): void {
            $session->table('session_scratch')->insert(['id' => 1, 'value' => 'autocommit']);
            $session->transaction(static function (Connection $tx): void {
                $tx->table('session_scratch')->where('id', 1)->update(['value' => 'in a transaction']);
            });
            $this->packageSession()->exec($session, 'select 1');
        });

        self::assertSame([], $events, 'Package statements fire no events.');
        self::assertSame([PackageSession::STATEMENT, PackageSession::BEGIN, PackageSession::STATEMENT, PackageSession::COMMIT, PackageSession::EXEC], PackageStatements::kinds($recorded), 'The recorder sees every round trip.');
        self::assertSame([PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY], array_values(array_unique(array_column($recorded, 'session'))));

        $this->app['db']->connection(TestEnvironment::PRIMARY)->table('session_scratch')->count();
        self::assertSame([QueryExecuted::class], $events, "The control: the application's own statement fires its event.");
    }

    /**
     * Rejects: a recorder that sees only run(): a package transaction on the
     * mirror gives its begin, its statement and its commit, and a raw
     * PackageSession::exec() is one round trip, on every engine. (The
     * mutation check of the task adds an exec() to the push path and sees
     * HotPathBudgetTest fail.)
     */
    public function test_recorder_sees_transaction_boundaries_and_raw_exec(): void
    {
        $sessions = $this->packageSession();
        $mirror = $sessions->mirror();
        $inMemory = $sessions->isInMemoryConnection(null);

        $recorded = PackageStatements::record(function () use ($mirror, $sessions): void {
            $this->app->make(PackageTransaction::class)->run($mirror, static function (Connection $tx): void {
                $tx->table('orrery_settings')->insert(['key' => 'recorder', 'value' => '1']);
            });
            $sessions->exec($mirror, 'SELECT 1');
        });

        // In-memory SQLite: the session is the application's connection, whose
        // statements reach QueryExecuted instead; PackageTransaction reports its own boundaries.
        $expected = $inMemory
            ? [PackageSession::BEGIN, PackageSession::COMMIT, PackageSession::EXEC]
            : [PackageSession::BEGIN, PackageSession::STATEMENT, PackageSession::COMMIT, PackageSession::EXEC];

        self::assertSame($expected, PackageStatements::kinds($recorded));
        self::assertSame('SELECT 1', end($recorded)['sql']);
        self::assertSame([$sessions->mirrorName()], array_values(array_unique(array_column($recorded, 'session'))));
    }

    /**
     * Rejects: a recorder that sees only run(), a pooled begin that counts as more than one round trip, a plain
     * pooled transaction without the configured statement timeout, and a build of a pooled session
     * whose first read has no timeout (a pooler without a backend holds it unbounded). Would still pass: a
     * transaction whose statement timeout is a later SET LOCAL of its own round trip (the kinds count it).
     */
    #[FreshSchema]
    public function test_recorder_counts_the_combined_begin_once(): void
    {
        $this->skipUnlessDriver('pgsql');
        $name = $this->pooledConnection();
        $this->createScratchTable($this->app['db']->connection($name));
        $sessions = $this->packageSession();
        $build = PackageStatements::record(static fn () => $sessions->for($name)->getPdo());
        self::assertMatchesRegularExpression("/^SET LOCAL lock_timeout = '\\d+s'; SET LOCAL statement_timeout = 10000; select reset_val/", $build[0]['sql'], 'The first read of the build has no timeout.');
        $session = $sessions->for($name);

        $recorded = PackageStatements::record(static function () use ($session, $sessions): void {
            $session->transaction(static function (Connection $tx): void {
                $tx->table('session_scratch')->insert(['id' => 1, 'value' => 'pooled']);
            });
            $sessions->bounded($session, static fn (Connection $bounded): int => $bounded->table('session_scratch')->count());

            try {
                $session->transaction(static function (Connection $tx): never {
                    $tx->table('session_scratch')->insert(['id' => 2, 'value' => 'rolled back']);

                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
                // The rollback is the round trip under test.
            }
        });

        self::assertSame(
            [PackageSession::BEGIN, PackageSession::STATEMENT, PackageSession::COMMIT, PackageSession::BEGIN, PackageSession::STATEMENT, PackageSession::COMMIT, PackageSession::BEGIN, PackageSession::STATEMENT, PackageSession::ROLLBACK],
            PackageStatements::kinds($recorded),
        );
        self::assertSame($session->beginStatement(), $recorded[0]['sql']);
        self::assertStringEndsWith('; SET LOCAL statement_timeout = 10000', $recorded[0]['sql'], 'A plain pooled transaction has no statement timeout.');
        self::assertStringEndsWith('; SET LOCAL statement_timeout = 10000', $recorded[3]['sql'], 'A bounded unit adds its statement timeout to the same begin.');
    }

    /**
     * Rejects: one cached PDO switched between modes, and an automatic caller inheriting a writable lease.
     *
     * A seam: HostFiles answers that the file is a host file whose reads are allowed (other tests repeat the test with the real pieces).
     */
    public function test_session_access_modes_are_leased(): void
    {
        $file = $this->sqliteFile("insert into session_scratch values (1, 'seeded')");
        $this->app['config']->set('database.connections.host_file', ['driver' => 'sqlite', 'url' => null, 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]);
        $reads = true;
        HostFiles::useForTesting(
            static fn (string $connection): ?bool => $connection === 'host_file' ? true : null,
            static function (string $connection) use (&$reads): ?bool {
                return $connection === 'host_file' ? $reads : null;
            },
        );
        $sessions = $this->packageSession();
        $print = $sessions->fingerprint('host_file');
        $count = static fn (Connection $session): int => $session->table('session_scratch')->count();

        // An automatic read, then an operator resolve, then an automatic read.
        $automatic = $sessions->for('host_file');
        self::assertSame(1, $count($automatic));
        $this->assertReadOnly($automatic);

        $held = null;
        $sessions->operator(function (OperatorContext $context) use ($sessions, &$held): void {
            $operator = $sessions->for('host_file', $context);
            $held = $operator;
            $operator->table('session_scratch')->insert(['id' => 2, 'value' => 'operator write']);
            self::assertSame(['automatic' => 1, 'operator' => 1], $this->liveModes(), 'At most one PDO per mode is live.');
            $this->assertReadOnly($sessions->for('host_file'), 'An automatic caller inside an operator action still reads only.');
        });

        self::assertSame(['automatic' => 1], $this->liveModes(), 'The outermost operator call released its session.');

        // A caller that kept the operator Connection cannot write after the lease ended.
        self::assertInstanceOf(Connection::class, $held);

        try {
            $held->table('session_scratch')->insert(['id' => 99, 'value' => 'after the lease']);
            self::fail('A released operator session never reconnects.');
        } catch (PackageSessionReleased $released) {
            self::assertSame(PackageSession::NAME_PREFIX.'host_file', $released->session);
        }

        self::assertSame(['automatic' => 1], $this->liveModes(), 'The refused write opened no session.');
        self::assertSame(0, (int) (new PDO('sqlite:'.$file))->query('select count(*) from session_scratch where id = 99')->fetchColumn());
        self::assertSame($automatic, $sessions->for('host_file'));
        self::assertSame(2, $count($automatic), 'The automatic session reads the authorised write.');
        $this->assertReadOnly($automatic);

        // Operator first, then automatic.
        PackageSession::releaseAll();
        $sessions->operator(static function (OperatorContext $context) use ($sessions): void {
            $sessions->for('host_file', $context)->table('session_scratch')->insert(['id' => 3, 'value' => 'operator first']);
        });
        self::assertSame([], $this->liveModes());
        $this->assertReadOnly($sessions->for('host_file'));
        self::assertSame(3, $count($sessions->for('host_file')));

        // Nested operator contexts share one lease, released by the outermost.
        $sessions->operator(function (OperatorContext $outer) use ($sessions): void {
            $sessions->operator(function (OperatorContext $inner) use ($outer, $sessions): void {
                self::assertSame($outer, $inner);
                self::assertSame(2, $inner->depth());
                $sessions->for('host_file', $inner)->table('session_scratch')->insert(['id' => 4, 'value' => 'nested']);
            });

            self::assertSame(1, $this->liveModes()['operator'] ?? 0, 'The inner call keeps the lease.');
            $sessions->for('host_file', $outer)->table('session_scratch')->insert(['id' => 5, 'value' => 'after the inner call']);
        });
        self::assertArrayNotHasKey('operator', $this->liveModes());

        // An operator exception ends the lease, and its context never opens a session again.
        $leaked = null;

        try {
            $sessions->operator(static function (OperatorContext $context) use ($sessions, &$leaked): never {
                $leaked = $context;
                $sessions->for('host_file', $context)->table('session_scratch')->insert(['id' => 6, 'value' => 'before the failure']);

                throw new RuntimeException('The operator action failed.');
            });
        } catch (RuntimeException) {
            // The failure under test.
        }

        self::assertArrayNotHasKey('operator', $this->liveModes());
        self::assertInstanceOf(OperatorContext::class, $leaked);
        self::assertFalse($leaked->isActive());

        try {
            $sessions->for('host_file', $leaked);
            self::fail('An ended context opens no session.');
        } catch (LogicException) {
            self::assertArrayNotHasKey('operator', $this->liveModes());
        }

        // A reconnect keeps each session's own mode.
        $automatic = $sessions->for('host_file');
        $automatic->reconnect();
        $this->assertReadOnly($automatic, 'After a reconnect.');
        $sessions->operator(static function (OperatorContext $context) use ($sessions): void {
            $operator = $sessions->for('host_file', $context);
            $operator->reconnect();
            $operator->table('session_scratch')->insert(['id' => 7, 'value' => 'after an operator reconnect']);
        });

        // An Octane-like scoped flush gives a new PackageSession instance, without a writable lease.
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(PackageSession::class);
        $fresh = $this->packageSession();
        self::assertNotSame($sessions, $fresh);
        $this->assertReadOnly($fresh->for('host_file'), 'After a scoped flush.');
        self::assertSame(['automatic' => 1], $this->liveModes());

        // A host file that allows no automatic reads: a typed refusal, and the operator still works.
        PackageSession::releaseAll();
        $reads = false;

        try {
            $fresh->for('host_file');
            self::fail('No automatic session opens on the file.');
        } catch (HostFileAccessNotAllowed $refused) {
            self::assertSame('host_file', $refused->connection);
        }

        $fresh->operator(static function (OperatorContext $context) use ($fresh): void {
            $fresh->for('host_file', $context)->table('session_scratch')->insert(['id' => 8, 'value' => 'operator without automatic reads']);
        });
        self::assertSame([], $this->liveModes());

        self::assertSame(8, (int) (new PDO('sqlite:'.$file))->query('select count(*) from session_scratch')->fetchColumn(), 'Every authorised write succeeded.');
        self::assertSame($print, $fresh->fingerprint('host_file'), 'The access modes never change the fingerprint.');
    }

    /**
     * Rejects: a session cache that the teardown cannot release, so the PDOs stay open after the test.
     */
    public function test_release_all_closes_every_session_pdo(): void
    {
        $this->skipOnInMemory();
        $sessions = $this->packageSession();
        $session = $sessions->for(TestEnvironment::PRIMARY);
        $session->scalar('select 1');
        $id = $this->testEnvironment()->isServer() ? $this->serverSessionId($session) : null;

        PackageSession::releaseAll();

        self::assertSame([], PackageSession::sessionsForTesting());
        self::assertNull($session->getRawPdo());

        if ($id !== null) {
            $this->assertServerSessionEnds($id);
        }

        self::assertNotSame($session, $sessions->for(TestEnvironment::PRIMARY), 'The next for() builds a new session.');
    }

    /**
     * Rejects: a disconnect that keeps the Connection object, so the next for() inherits its transaction level.
     */
    #[FreshSchema]
    public function test_disconnect_discards_the_session(): void
    {
        $this->skipOnInMemory();
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $this->createScratchTable($primary);
        $sessions = $this->packageSession();
        $session = $sessions->for(TestEnvironment::PRIMARY);

        $session->beginTransaction();
        $session->table('session_scratch')->insert(['id' => 1, 'value' => 'uncommitted']);
        $sessions->disconnect($session);

        $next = $sessions->for(TestEnvironment::PRIMARY);

        self::assertNotSame($session, $next);
        self::assertSame(0, $next->transactionLevel());
        self::assertSame(0, $next->table('session_scratch')->count(), 'The server discarded the open transaction.');
    }

    /**
     * Rejects: cloning an in-memory database into an empty one.
     */
    public function test_in_memory_sqlite_uses_the_configured_connection(): void
    {
        if (! $this->testEnvironment()->inMemory()) {
            self::markTestSkipped('This test covers in-memory SQLite only. Other databases get their own sessions.');
        }

        $sessions = $this->packageSession();

        self::assertSame($this->app['db']->connection(TestEnvironment::PRIMARY), $sessions->for(TestEnvironment::PRIMARY));
        self::assertSame($this->mirror(), $sessions->mirror());
        self::assertTrue($sessions->mirror()->getSchemaBuilder()->hasTable('orrery_settings'));

        PackageSession::releaseAll();

        self::assertTrue($sessions->mirror()->getSchemaBuilder()->hasTable('orrery_settings'), 'releaseAll() never closes an in-memory database.');
    }

    /**
     * Rejects: role clones, so that a name gives more than one session.
     */
    public function test_one_session_per_connection_name(): void
    {
        $this->skipOnInMemory();
        $sessions = $this->packageSession();
        $queue = (string) $this->app['config']->get('queue.default');

        $session = $sessions->for(TestEnvironment::PRIMARY);

        self::assertSame($session, $sessions->for(null), 'null is database.default.');
        self::assertSame($session, $sessions->forQueue($queue));
        self::assertSame($session, $sessions->for(TestEnvironment::PRIMARY.'::write'), 'A PDO alias gives the session of its base name.');
        self::assertSame(PackageSession::NAME_PREFIX.TestEnvironment::PRIMARY, $session->getName());
        $ownMirror = $this->testEnvironment()->separate() || $this->testEnvironment()->siblingDatabase() !== null;
        self::assertSame($ownMirror ? 2 : 1, count(array_unique(array_map('spl_object_id', [$session, $sessions->mirror()]))), 'The mirror shares the session unless it is a separate database or the sibling SQLite file.');
    }

    /**
     * Rejects: a session that keeps PDO::ATTR_PERSISTENT, so it shares the application's physical connection and its transaction.
     */
    #[FreshSchema]
    public function test_a_persistent_application_connection_never_shares_its_session(): void
    {
        $this->skipUnlessDriver('mysql', 'pgsql');
        $options = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.options', []);
        $this->defineConnection('persistent_app', TestEnvironment::PRIMARY, ['options' => [PDO::ATTR_PERSISTENT => true] + $options]);
        $application = $this->app['db']->connection('persistent_app');
        $this->createScratchTable($this->app['db']->connection(TestEnvironment::PRIMARY));
        self::assertTrue((bool) $application->getPdo()->getAttribute(PDO::ATTR_PERSISTENT), 'The control: the application PDO is persistent.');

        $session = $this->packageSession()->for('persistent_app');

        self::assertFalse((bool) $session->getPdo()->getAttribute(PDO::ATTR_PERSISTENT));
        self::assertNotSame($this->serverSessionId($application), $this->serverSessionId($session), 'The package session has its own server session.');

        $application->beginTransaction();

        try {
            $application->table('session_scratch')->insert(['id' => 1, 'value' => 'uncommitted application row']);
            self::assertSame(0, $session->table('session_scratch')->count(), "The package session never sees the application's uncommitted row.");
        } finally {
            $application->rollBack();
        }
    }

    /**
     * Rejects: session settings on a connection that Laravel itself marks as pooled (by design: the configuration names only orrery.sessions.pooled).
     */
    public function test_laravel_pooled_connections_get_pooled_sessions(): void
    {
        $this->skipUnlessDriver('pgsql');
        $primary = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        unset($primary['search_path']);
        $this->app['config']->set('database.connections.laravel_pooled', ['pooled' => true, 'direct' => ['host' => $primary['host'], 'port' => $primary['port']]] + $primary);
        $this->app['config']->set('orrery.sessions.pooled', false);

        $session = $this->packageSession()->for('laravel_pooled');

        self::assertInstanceOf(PostgresSession::class, $session);
        self::assertTrue($session->isPooled());
        self::assertTrue((bool) $session->getConfig('options')[PDO::ATTR_EMULATE_PREPARES], 'The session keeps the emulated prepares that Laravel gives the host.');
        self::assertSame([], $this->sessionSettings($session));
        self::assertArrayNotHasKey('direct', (array) $session->getConfig());
        self::assertArrayNotHasKey('pooled', (array) $session->getConfig());
    }

    /**
     * Rejects: connecting again on every for() after a pooled refusal.
     */
    public function test_a_pooled_refusal_is_not_connected_again(): void
    {
        $this->skipUnlessDriver('pgsql');
        $this->pooledConnection('tenant_pg', ['search_path' => 'tenant']);
        $connects = 0;
        PackageSession::usePdoFactoryForTesting(static function (string $name) use (&$connects): ?PDO {
            if ($name === PackageSession::NAME_PREFIX.'tenant_pg') {
                $connects++;
            }

            return null;
        });

        foreach (range(1, 3) as $attempt) {
            $this->expectRefusal(fn () => $this->packageSession()->for('tenant_pg'), 'is not the server default');
        }

        self::assertSame(1, $connects, 'One connect for three refusals.');

        // A changed configuration is a new endpoint, so it is asked again.
        $this->app['config']->set('database.connections.tenant_pg.search_path', 'other_tenant');
        $this->expectRefusal(fn () => $this->packageSession()->for('tenant_pg'), 'its search_path other_tenant');
        self::assertSame(2, $connects);
    }

    /**
     * Rejects: an application init command whose trailing comment swallows the package settings.
     */
    public function test_mysql_init_command_with_a_comment_keeps_the_package_settings(): void
    {
        $this->skipUnlessDriver('mysql', 'mariadb');
        $options = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.options', []);
        $this->defineConnection('commented_init', TestEnvironment::PRIMARY, ['options' => [PackageSession::mysqlInitCommandAttribute() => 'SET @c4a_marker = 7 -- the application note'] + $options]);

        $session = $this->packageSession()->for('commented_init');

        self::assertSame(7, (int) $session->scalar('select @c4a_marker'), "The application's init command still runs.");
        self::assertSame(5, (int) $session->scalar('select @@session.lock_wait_timeout'));
        self::assertSame(5, (int) $session->scalar('select @@session.innodb_lock_wait_timeout'));
        self::assertSame(1, (int) $session->scalar('select @@autocommit'));
    }

    /**
     * The application turns multi-statements off (PDO's MySQL attribute),
     * with and without an init command of its own, and with one that sets
     * an isolation level.
     *
     * Rejects: package settings that ride on the application's init command
     * (with multi-statements off, the joined command fails at connect); a
     * package that drops the application's init command, or that runs its
     * settings before it (the isolation stays the application's).
     */
    public function test_mysql_package_settings_work_with_multi_statements_off(): void
    {
        $this->skipUnlessDriver('mysql', 'mariadb');
        $options = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY.'.options', []);
        $multi = defined('Pdo\Mysql::ATTR_MULTI_STATEMENTS')
            ? (int) constant('Pdo\Mysql::ATTR_MULTI_STATEMENTS')
            : (int) constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS');
        $init = PackageSession::mysqlInitCommandAttribute();
        $this->defineConnection('single_plain', TestEnvironment::PRIMARY, ['options' => [$multi => false] + $options]);
        $this->defineConnection('single_init', TestEnvironment::PRIMARY, ['options' => [$multi => false, $init => 'SET @l36_marker = 7'] + $options]);
        $this->defineConnection('single_isolation', TestEnvironment::PRIMARY, ['options' => [$multi => false, $init => 'SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED'] + $options]);

        self::assertSame(7, (int) $this->app['db']->connection('single_init')->scalar('select @l36_marker'), "The control: the application's own connection runs its init command.");
        self::assertSame('READ-UNCOMMITTED', $this->app['db']->connection('single_isolation')->scalar('select @@session.transaction_isolation'));

        foreach (['single_plain', 'single_init', 'single_isolation'] as $name) {
            $session = $this->packageSession()->for($name);

            foreach (['first connect', 'after a reconnect'] as $round) {
                if ($round === 'after a reconnect') {
                    $session->reconnect();
                }

                self::assertSame(1, (int) $session->scalar('select @@autocommit'), "{$name}, {$round}: autocommit.");
                self::assertSame(5, (int) $session->scalar('select @@session.lock_wait_timeout'), "{$name}, {$round}: lock_wait_timeout.");
                self::assertSame(5, (int) $session->scalar('select @@session.innodb_lock_wait_timeout'), "{$name}, {$round}: innodb_lock_wait_timeout.");
                // MariaDB's max_statement_time is in seconds.
                $timeout = $this->testEnvironment()->driver === 'mariadb'
                    ? (int) round((float) $session->scalar('select @@session.max_statement_time') * 1000)
                    : (int) $session->scalar('select @@session.max_execution_time');
                self::assertSame(PackageSession::DEFAULT_STATEMENT_TIMEOUT_MS, $timeout, "{$name}, {$round}: the statement timeout.");
            }
        }

        self::assertSame(7, (int) $this->packageSession()->for('single_init')->scalar('select @l36_marker'), "The application's init command still runs on the package session.");
        self::assertSame('REPEATABLE-READ', $this->packageSession()->for('single_isolation')->scalar('select @@session.transaction_isolation'), "The package's isolation comes after the application's init command.");
        self::assertSame('SET @l36_marker = 7', $this->app['config']->get('database.connections.single_init.options')[$init], "The application's configuration is unchanged.");

        // The server default is REPEATABLE READ too, so the statements decide: the isolation
        // statement follows only an application init command that names an isolation level.
        $sent = [];
        PackageSession::usePdoFactoryForTesting(function (string $name, array $config) use (&$sent): PDO {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']);
            $pdo = new class($dsn, (string) $config['username'], (string) $config['password']) extends PDO
            {
                /** @var list<string> */
                public array $sent = [];

                public function exec(string $statement): int|false
                {
                    $this->sent[] = $statement;

                    return parent::exec($statement);
                }
            };
            $sent[$name] = $pdo;

            return $pdo;
        });
        PackageSession::releaseAll();

        foreach (['single_init', 'single_isolation'] as $name) {
            $this->packageSession()->for($name)->select('select 1');
        }

        $isolation = static fn (string $name): bool => in_array(PackageSession::MYSQL_ISOLATION_STATEMENT, $sent[PackageSession::NAME_PREFIX.$name]->sent ?? [], true);
        self::assertFalse($isolation('single_init'), 'no isolation statement without an isolation in the init command');
        self::assertTrue($isolation('single_isolation'));
    }

    private function packageSession(): PackageSession
    {
        return $this->app->make(PackageSession::class);
    }

    private function skipUnlessDriver(string ...$drivers): void
    {
        $driver = $this->testEnvironment()->driver;

        if (! in_array($driver, $drivers, true)) {
            self::markTestSkipped(sprintf('This test needs %s, and this run uses %s.', implode(' or ', $drivers), $driver));
        }
    }

    private function skipOnInMemory(): void
    {
        if ($this->testEnvironment()->inMemory()) {
            self::markTestSkipped('In-memory SQLite cannot be cloned, so the package session is the configured connection. Run it through bin/test sqlite --sqlite-file, mysql or pgsql.');
        }
    }

    /**
     * A copy of the primary Postgres connection without `search_path` (pooled
     * mode accepts only the server default), named in orrery.sessions.pooled.
     *
     * @param  array<string, mixed>  $changes
     */
    private function pooledConnection(string $name = 'pooled_pg', array $changes = []): string
    {
        $this->defineConnection($name, TestEnvironment::PRIMARY, $changes, array_key_exists('search_path', $changes) ? [] : ['search_path']);
        $pooled = $this->app['config']->get('orrery.sessions.pooled');
        $this->app['config']->set('orrery.sessions.pooled', array_values(array_unique([...(is_array($pooled) ? $pooled : []), $name])));

        return $name;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $without
     */
    private function defineConnection(string $name, string $from, array $changes = [], array $without = []): void
    {
        $config = $this->app['config']->get("database.connections.{$from}");

        if (! is_array($config)) {
            throw new LogicException("The connection {$from} is not configured.");
        }

        foreach ($without as $key) {
            unset($config[$key]);
        }

        $this->app['config']->set("database.connections.{$name}", array_replace($config, $changes));
    }

    private function createScratchTable(Connection $connection, string $table = 'session_scratch'): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->dropIfExists($table);
        $schema->create($table, static function (Blueprint $blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->string('value', 64);
        });

        $this->beforeApplicationDestroyed(static function () use ($connection, $table): void {
            // A package session that a failed test left inside a transaction
            // holds a lock on the table: release the sessions first.
            PackageSession::releaseAll();
            $connection->getSchemaBuilder()->dropIfExists($table);
        });
    }

    /**
     * A SQLite file of the test with the scratch table, removed when the
     * application ends.
     */
    private function sqliteFile(string ...$statements): string
    {
        $file = SqliteFiles::create();
        $this->beforeApplicationDestroyed(static function () use ($file): void {
            PackageSession::releaseAll();
            SqliteFiles::remove($file);
        });

        $pdo = new PDO('sqlite:'.$file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('create table session_scratch (id integer primary key, value varchar(64))');

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }

        return $file;
    }

    /**
     * @return list<array{name: string, source: string}>
     */
    private function sessionSettings(Connection $session): array
    {
        return array_map(
            static fn (object $row): array => ['name' => (string) $row->name, 'source' => (string) $row->source],
            // Through the PDO between statements: a pooled autocommit statement carries its own
            // SET LOCAL bounds, which end with that statement.
            $session->getPdo()->query("select name, source from pg_settings where source = 'session' order by name")->fetchAll(PDO::FETCH_OBJ),
        );
    }

    private function expectRefusal(\Closure $open, string $message): void
    {
        try {
            $open();
            self::fail("The pooled session is refused: {$message}.");
        } catch (PooledSessionRefused $refused) {
            self::assertStringContainsString($message, $refused->getMessage());
        }
    }

    /**
     * @return array<string, int>
     */
    private function liveModes(): array
    {
        $modes = [];

        foreach (PackageSession::sessionsForTesting() as $entry) {
            // The host-file modes only: the sibling SQLite mirror file is the package's own.
            if ($entry['mode'] !== PackageSession::SIBLING) {
                $modes[$entry['mode']] = ($modes[$entry['mode']] ?? 0) + 1;
            }
        }

        ksort($modes);

        return $modes;
    }

    private function assertReadOnly(Connection $session, string $when = ''): void
    {
        try {
            $session->table('session_scratch')->insert(['id' => random_int(1000, 1_000_000), 'value' => 'automatic write']);
            self::fail(trim("An automatic session never writes. {$when}"));
        } catch (QueryException $error) {
            self::assertStringContainsStringIgnoringCase('readonly', $error->getMessage(), $when);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function limits(Connection $session): array
    {
        if ($session->getDriverName() === 'pgsql') {
            return [
                'lock_timeout' => $session->scalar('show lock_timeout'),
                'statement_timeout' => $session->scalar('show statement_timeout'),
                'idle_in_transaction_session_timeout' => $session->scalar('show idle_in_transaction_session_timeout'),
            ];
        }

        $row = (array) $session->selectOne('select @@session.lock_wait_timeout as lock_wait_timeout, @@session.innodb_lock_wait_timeout as innodb_lock_wait_timeout, @@session.max_execution_time as max_execution_time, @@session.autocommit as autocommit');

        return array_map('intval', $row);
    }

    /**
     * @return array{lock_wait_timeout: int, innodb_lock_wait_timeout: int}
     */
    private function mysqlTimeouts(Connection $connection): array
    {
        $row = (array) $connection->selectOne('select @@session.lock_wait_timeout as lock_wait_timeout, @@session.innodb_lock_wait_timeout as innodb_lock_wait_timeout');

        return ['lock_wait_timeout' => (int) $row['lock_wait_timeout'], 'innodb_lock_wait_timeout' => (int) $row['innodb_lock_wait_timeout']];
    }

    private function serverSessionId(Connection $connection): int
    {
        return (int) $connection->scalar($connection->getDriverName() === 'pgsql' ? 'select pg_backend_pid()' : 'select connection_id()');
    }

    private function assertServerSessionEnds(int $id): void
    {
        $primary = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $sql = $primary->getDriverName() === 'pgsql'
            ? 'select count(*) from pg_stat_activity where pid = ?'
            : 'select count(*) from information_schema.processlist where id = ?';
        $deadline = microtime(true) + 5;

        while ((int) $primary->scalar($sql, [$id]) > 0) {
            if (microtime(true) > $deadline) {
                self::fail("The server session {$id} is still open after releaseAll().");
            }

            usleep(20_000);
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @param  \Closure(): mixed  $statement
     * @param  int  $bound  the lock wait bound of the statement's session, in seconds
     */
    private function assertGivesUpWithinTheBound(\Closure $statement, int $bound): void
    {
        $started = microtime(true);

        try {
            $statement();
            self::fail('The package statement gives up behind the held lock.');
        } catch (QueryException $timeout) {
            self::assertStringContainsString('Lock wait timeout exceeded', $timeout->getMessage());
        }

        $elapsed = microtime(true) - $started;
        self::assertGreaterThan(max($bound - 1, $bound * 0.8), $elapsed, 'The statement waited for the lock.');
        self::assertLessThan($bound + 3, $elapsed, "The statement gave up after about {$bound} s.");
    }

    private function waitForMetadataLockWaiter(Connection $connection): void
    {
        $deadline = microtime(true) + 10;

        // Only this run's database: another run's ALTER on the same server must not count.
        while ((int) $connection->scalar("select count(*) from information_schema.processlist where state = 'Waiting for table metadata lock' and db = database()") === 0) {
            if (microtime(true) > $deadline) {
                self::fail('The ALTER never queued for the metadata lock.');
            }

            usleep(20_000);
        }
    }

    /**
     * A second MySQL session in a child process that runs $statements and
     * then holds its locks until stop() or 20 s. The parent cannot release a
     * lock while one of its own statements waits, so the holder runs apart.
     *
     * @param  list<string>  $statements
     */
    private function holdMysql(array $statements, bool $waitsForLock = false): object
    {
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $script = <<<'PHP'
            [, $dsn, $user, $password, $statements, $waits] = $argv;
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $list = json_decode($statements, true);
            $last = array_pop($list);
            foreach ($list as $sql) { $pdo->exec($sql); }
            if ($waits === '1') { fwrite(STDOUT, "ready\n"); }
            $pdo->query($last);
            if ($waits !== '1') { fwrite(STDOUT, "ready\n"); }
            sleep(20);
            PHP;
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']);
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $dsn, (string) $config['username'], (string) $config['password'], (string) json_encode($statements), $waitsForLock ? '1' : '0'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start the lock holder.');
        }

        $line = fgets($pipes[1]);

        if ($line !== "ready\n") {
            proc_terminate($process, 9);

            throw new RuntimeException('The lock holder did not start: '.stream_get_contents($pipes[2]));
        }

        $holder = new class($process)
        {
            private bool $stopped = false;

            /** @param resource $process */
            public function __construct(private $process) {}

            public function stop(): void
            {
                if ($this->stopped) {
                    return;
                }

                $this->stopped = true;
                proc_terminate($this->process, 9);
                proc_close($this->process);
            }
        };
        $this->beforeApplicationDestroyed(static function () use ($holder): void {
            $holder->stop();
        });

        return $holder;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, mixed>  $options
     */
    private function rawPdo(array $config, array $options = []): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $config['database']);

        try {
            return new PDO($dsn, (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false] + $options);
        } catch (Throwable $error) {
            throw new RuntimeException('Cannot open the test PDO: '.$error->getMessage(), 0, $error);
        }
    }
}
