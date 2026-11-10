<?php

namespace Justrau\Orrery\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\HarnessTiming;
use Justrau\Orrery\Tests\Support\LeaseProof;
use Justrau\Orrery\Tests\Support\MirrorOutage;
use Justrau\Orrery\Tests\Support\PackageStatements;
use Justrau\Orrery\Tests\Support\ResetsPackageState;
use Justrau\Orrery\Tests\Support\ReusedSchema;
use Justrau\Orrery\Tests\Support\SharedCache;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

use function Orchestra\Testbench\default_migration_path;

/**
 * The package test case. The database configuration comes from the
 * ORRERY_TEST_* variables (see Support\TestEnvironment): in-memory SQLite
 * by default, or the engine, mirror mode and prefix that bin/test selects.
 *
 * Before the first migration of every test, it proves the run's lease (server
 * engines, once per process) and asserts the resolved identity of both
 * connections, including the endpoint token. Package tables live on
 * mirror(); the jobs, failed_jobs and job_batches tables stay on the default
 * connection (testbench).
 *
 * tearDown() first stops the workers that Support\WorkerProcess started.
 * Only when every worker is proved stopped does it remove the directories of
 * Support\Barrier::forTest(), end the mirror outages and roll the schema
 * back. When a stop fails, it removes and rolls back nothing, because the
 * worker can still use them. It records the failure, and every later test
 * of this PHPUnit process refuses to start (setUp()) until that test's own
 * teardown completes. bin/test's lease keeps the worker's process group
 * until the end of the run.
 *
 * The schema mode (ORRERY_TEST_SCHEMA, see Support\ReusedSchema): the fresh
 * mode migrates before every test and rolls back after it; on a SQLite file
 * or a server, a guard then fails a test whose rollback left objects in the
 * test databases and drops them (ReusedSchema::afterFreshTest()), so the next
 * migration starts from empty databases. The reuse mode
 * migrates once per process, and after each test checks the schema
 * fingerprint and empties every table. A test with #[FreshSchema] gets the
 * fresh behaviour in both modes.
 */
abstract class TestCase extends BaseTestCase
{
    use ResetsPackageState;

    /** The test whose teardown could not prove its workers stopped: its schema was not rolled back. */
    private static ?self $failedTeardownTest = null;

    private static ?string $failedTeardownReason = null;

    /**
     * The fresh guard of the last teardown proved the test databases empty
     * (ReusedSchema::afterFreshTest()), so the next fresh migration skips its
     * own check. The next migration consumes it.
     */
    private static bool $freshDatabasesEmpty = false;

    /** @var list<MirrorOutage> */
    private array $mirrorOutages = [];

    /**
     * This test proved its endpoints (the lease and the identity with the
     * endpoint token): only then may the fresh guard connect to them.
     */
    private bool $endpointsProved = false;

    /** setUp() refused this test, so it created nothing to tear down. */
    private bool $refusedAtSetUp = false;

    /** This test uses the kept schema of the reuse mode, so its teardown resets it. */
    /**
     * Package sessions have no event dispatcher. With this switch on
     * (the default), the harness fires QueryExecuted and the transaction
     * events of the package sessions' statements on the application's
     * dispatcher (PackageStatements::bridge()), so a test can count them as
     * before. A test of the sessions themselves turns it off.
     */
    protected bool $packageStatementEvents = true;

    private bool $usesReusedSchema = false;

    /** The directory of the shared file cache store that this test opted into (useSharedCache()), or null. */
    private ?string $sharedCache = null;

    protected function setUp(): void
    {
        if (self::$failedTeardownTest !== null) {
            // The failed test itself keeps its own teardown to finish.
            $this->refusedAtSetUp = self::$failedTeardownTest !== $this;

            throw new HarnessRefusal(
                'An earlier test ('.self::$failedTeardownTest->name().') could not prove that its workers stopped. Its schema was not rolled back, '
                .'and a worker can still use it, so no later test of this process starts. bin/test stops the worker with its process group. Cause: '
                .self::$failedTeardownReason
            );
        }

        static::resetPackageState();
        PackageStatements::reset();
        FreshSchema::enter($this);

        parent::setUp();

        // The SPA sends it with every request, and a write without it is refused (Authenticate).
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');

        if ($this->packageStatementEvents) {
            PackageStatements::bridge($this->app);
        }
    }

    protected function tearDown(): void
    {
        if ($this->refusedAtSetUp) {
            return;
        }

        // The workers first: no other process may use the barriers, the
        // mirror or the schema while they go.
        try {
            WorkerProcess::stopAll();
        } catch (Throwable $failure) {
            if (self::$failedTeardownTest === null) {
                self::$failedTeardownTest = $this;
                self::$failedTeardownReason = $failure->getMessage();
            }

            throw new HarnessRefusal(
                'The workers of this test are not proved stopped. Its barriers, its mirror outages and its schema stay, '
                .'and no later test of this process starts. '.$failure->getMessage(),
                0,
                $failure,
            );
        }

        // The database manager of the test, taken before the destruction
        // callbacks (the rollback of the fresh mode still uses it).
        $db = $this->databaseManager();
        // The package sessions are a container singleton: the
        // instance is taken now, and its sessions are released after the
        // application is gone.
        $sessions = $this->packageSessions();

        try {
            Barrier::removeAll();
            SharedCache::removeAll();
            $this->sharedCache = null;
        } finally {
            try {
                $this->endMirrorOutages();
            } finally {
                try {
                    parent::tearDown();
                } finally {
                    $reused = $this->usesReusedSchema;
                    $this->usesReusedSchema = false;
                    FreshSchema::leave();
                    static::resetPackageState();
                    self::releaseConnections($db);
                    // The package sessions live outside the manager.
                    $sessions?->releaseSessions();

                    if (! $reused) {
                        // The migration's down() leaves the sibling mirror
                        // file as it is, so a fresh test removes it.
                        self::removeSibling($this->testEnvironment());
                        $this->guardFreshSchema();
                    }
                }
            }
        }

        // The teardown that failed before is complete now: its schema is rolled back.
        if (self::$failedTeardownTest === $this) {
            self::$failedTeardownTest = null;
            self::$failedTeardownReason = null;
        }
    }

    /**
     * The fresh mode's guard after the rollback (ReusedSchema::afterFreshTest()):
     * objects that the test left in the test databases are dropped and fail
     * this test, so they never break the migration of the next test. Only a
     * completed guard lets the next migration skip its own check.
     */
    private function guardFreshSchema(): void
    {
        $environment = $this->testEnvironment();
        $proved = $this->endpointsProved;
        $this->endpointsProved = false;

        if ($environment->inMemory() || ReusedSchema::mode($environment) !== ReusedSchema::FRESH) {
            return;
        }

        if (! $proved) {
            // setUp() stopped before the endpoint proof: the guard never
            // connects to an endpoint that this test did not prove. The
            // next test checks after its own proof.
            self::$freshDatabasesEmpty = false;

            return;
        }

        try {
            HarnessTiming::measure('fresh.guard', fn () => ReusedSchema::afterFreshTest($environment, static::class.'::'.$this->name()));
            self::$freshDatabasesEmpty = true;
        } catch (AssertionFailedError $leftover) {
            // The guard dropped what it found.
            self::$freshDatabasesEmpty = true;

            if (! $this->status()->isSuccess()) {
                // PHPUnit drops a teardown failure of a test that already
                // failed, errored, was skipped or is incomplete: the leak
                // must still show.
                fwrite(STDERR, 'orrery fresh guard: '.str_replace("\n", ' ', $leftover->getMessage()).PHP_EOL);
            }

            throw $leftover;
        }
    }

    /**
     * The package sessions of the application, or null when the test never
     * resolved them.
     */
    private function packageSessions(): ?PackageSession
    {
        try {
            if ($this->app !== null && $this->app->resolved(PackageSession::class)) {
                return $this->app->make(PackageSession::class);
            }
        } catch (Throwable) {
            // A broken application has nothing to release here.
        }

        return null;
    }

    /**
     * The database manager of the application, or null when the test never
     * resolved it.
     */
    private function databaseManager(): ?DatabaseManager
    {
        try {
            if ($this->app !== null && $this->app->resolved('db')) {
                $db = $this->app->make('db');

                return $db instanceof DatabaseManager ? $db : null;
            }
        } catch (Throwable) {
            // A broken application has nothing to release here.
        }

        return null;
    }

    /**
     * Drops the PDO of every connection of the manager, the package sessions
     * included, after the application is gone. The application is a graph of
     * reference cycles, so without this its PDOs stay open until PHP's cycle
     * collector runs, and on a server engine the open connections of many
     * tests passed max_connections. A
     * Connection::disconnect() is not used: in Laravel 13 it also rolls back
     * the transaction records, which runs the test's rollback callbacks.
     */
    private static function releaseConnections(?DatabaseManager $db): void
    {
        if ($db === null) {
            return;
        }

        foreach ($db->getConnections() as $connection) {
            try {
                $connection->setPdo(null)->setReadPdo(null);
            } catch (Throwable) {
                // The next connection still gets its release.
            }
        }
    }

    /**
     * Why an earlier teardown could not prove its workers stopped, or null.
     */
    /**
     * Deletes the sibling SQLite mirror file with its WAL files, after the
     * package sessions are released.
     */
    private static function removeSibling(TestEnvironment $environment): void
    {
        $sibling = $environment->siblingDatabase();

        if ($sibling === null) {
            return;
        }

        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($sibling.$suffix)) {
                @unlink($sibling.$suffix);
            }
        }
    }

    protected static function failedTeardown(): ?string
    {
        return self::$failedTeardownReason;
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OrreryServiceProvider::class];
    }

    protected function testEnvironment(): TestEnvironment
    {
        $environment = TestEnvironment::fromEnv();

        return $this->sharedCache === null ? $environment : $environment->withSharedCache($this->sharedCache);
    }

    /**
     * Switches this test to a `file` cache store in a private directory that
     * its worker processes share (SharedCache): Laravel's
     * native queue pause lives in the default store. The children of
     * WorkerProcess::start($this->testEnvironment()) get the same store, and
     * their identity check expects it. Call it before the first worker
     * starts; the teardown removes the directory after the workers stop.
     */
    protected function useSharedCache(): TestEnvironment
    {
        $this->sharedCache ??= SharedCache::create();
        $environment = $this->testEnvironment();
        // Only the cache keys of apply(): the test may have changed other configuration.
        $this->app['config']->set('cache.stores.'.SharedCache::STORE, SharedCache::store($this->sharedCache));
        $this->app['config']->set('cache.default', SharedCache::STORE);
        $this->app->forgetInstance('cache.store');
        $this->app['cache']->forgetDriver(SharedCache::STORE);

        return $environment;
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $this->testEnvironment()->apply($app['config']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $environment = $this->testEnvironment();

        // Nothing is migrated before the run proves that it owns the
        // database and both connections resolve to the intended endpoint.
        HarnessTiming::measure('identity', function () use ($environment): void {
            LeaseProof::ensure($environment);
            $environment->identity($this->app)->assertMatches($environment);
        });
        $this->endpointsProved = true;

        if (ReusedSchema::mode($environment) === ReusedSchema::REUSE) {
            $reused = ReusedSchema::for($environment);

            if (! FreshSchema::marked($this)) {
                HarnessTiming::measure('reuse.prepare', fn () => $reused->prepare($this->schemaKey(), fn () => HarnessTiming::measure('reuse.migrate', fn () => $this->migrateOnce($environment))));
                $this->usesReusedSchema = true;
                // Testbench runs these callbacks in the reverse order of their
                // registration, so the first one runs last: after the test's own
                // callbacks, which can still change rows or drop their tables.
                $this->beforeApplicationDestroyed(function (): void {
                    HarnessTiming::measure('reuse.after_test', fn () => $this->resetReusedSchema());
                });

                return;
            }

            // A marked test migrates and rolls back by itself, on empty
            // databases. The next unmarked test migrates again.
            HarnessTiming::measure('reuse.discard', fn () => $reused->discard());
        }

        if (ReusedSchema::mode($environment) === ReusedSchema::FRESH && ! $environment->inMemory()) {
            $proved = self::$freshDatabasesEmpty;
            self::$freshDatabasesEmpty = false;

            if (! $proved) {
                // The first test of the process, or a teardown whose guard
                // did not finish: the databases must be empty before the
                // migration, else "table already exists" fails every test.
                HarnessTiming::measure('fresh.check', fn () => ReusedSchema::beforeFreshTest($environment, static::class.'::'.$this->name()));
            }
        }

        HarnessTiming::measure('fresh.migrate', fn () => $this->migrateFresh($environment));
    }

    /**
     * The migrations of the fresh mode (and of a #[FreshSchema] test in the
     * reuse mode): Laravel's and the package's, each rolled back after the
     * test. A harness test that builds its own small schema on the empty
     * databases overrides it.
     */
    protected function migrateFresh(TestEnvironment $environment): void
    {
        $this->loadLaravelMigrations(['--database' => TestEnvironment::PRIMARY]);
        $this->loadMigrationsFrom([
            '--database' => $environment->mirrorConnectionName(),
            '--path' => realpath(__DIR__.'/../database/migrations'),
            '--realpath' => true,
        ]);
    }

    /**
     * The rollback of the fresh mode, timed for the harness profile.
     */
    protected function tearDownInteractsWithMigrations(): void
    {
        HarnessTiming::measure('fresh.rollback', fn () => parent::tearDownInteractsWithMigrations());
    }

    /**
     * The migrations of the reuse mode: the same two migrate runs as the
     * fresh mode, without the rollback after the test.
     */
    protected function migrateOnce(TestEnvironment $environment): void
    {
        /** @var ConsoleKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);

        foreach ([
            ['--database' => TestEnvironment::PRIMARY, '--path' => default_migration_path(), '--realpath' => true],
            ['--database' => $environment->mirrorConnectionName(), '--path' => realpath(__DIR__.'/../database/migrations'), '--realpath' => true],
        ] as $options) {
            $status = $kernel->call('migrate', $options);

            if ($status !== 0) {
                throw new RuntimeException('The migrations of the reuse schema failed (exit '.$status.'): '.$kernel->output());
            }
        }

        $kernel->setArtisan(null);
    }

    /**
     * The configuration that the migrations read: the default connection
     * and the package connection (the main migration creates every package
     * table there, the fence table included). A test with
     * another key gets a new migration in the reuse mode.
     */
    private function schemaKey(): string
    {
        $config = $this->app['config'];
        $package = $config->get('orrery.database') ?? $config->get('database.default');

        return (string) json_encode([
            'default' => $config->get('database.default'),
            'package' => [$package, $config->get('database.connections.'.$package)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The reset of the reuse mode after the test. No session of this process
     * may keep a transaction or a lock. So a transaction that is still open
     * on a PDO is rolled back on the PDO itself (a Laravel rollback would run
     * the test's rollback callbacks), and the PDO of every connection and
     * package session is dropped.
     */
    private function resetReusedSchema(): void
    {
        $db = $this->app['db'];

        foreach ($db->getConnections() as $connection) {
            foreach ([$connection->getRawPdo(), $connection->getRawReadPdo()] as $pdo) {
                try {
                    if ($pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable) {
                    // The disconnect below closes the session.
                }
            }
        }

        // Connection::disconnect() also rolls back Laravel's transaction
        // records (Laravel 13), which runs the test's rollback callbacks, so
        // the PDOs are dropped directly. The application goes after this.
        foreach ($db->getConnections() as $connection) {
            $connection->setPdo(null)->setReadPdo(null);
        }

        // The package sessions live outside the manager.
        PackageSession::releaseAll();

        ReusedSchema::for($this->testEnvironment())->afterTest(static::class.'::'.$this->name());
    }

    /**
     * The connection of the package tables: testbench in same mode,
     * testbench_mirror in separate mode.
     */
    protected function mirror(): Connection
    {
        $environment = $this->testEnvironment();
        $sibling = $environment->siblingConnection();

        if ($sibling !== null) {
            // The sibling SQLite mirror file: the tests read and write
            // it through a connection of their own.
            $config = $this->app['config'];

            if ($config->get('database.connections.'.TestEnvironment::SIBLING) !== $sibling) {
                $config->set('database.connections.'.TestEnvironment::SIBLING, $sibling);
                $this->app['db']->purge(TestEnvironment::SIBLING);
            }

            // The package installs the sibling's schema when it first opens the file.
            $this->app->make(PackageSession::class)->mirror();

            /** @var Connection $siblingConnection */
            $siblingConnection = $this->app['db']->connection(TestEnvironment::SIBLING);

            return $siblingConnection;
        }

        /** @var Connection $connection */
        $connection = $this->app['db']->connection($environment->mirrorConnectionName());

        return $connection;
    }

    /**
     * The connection name of the package's mirror session at this moment
     * (`orrery.session.<connection>`, with `#sibling` for the sibling SQLite
     * mirror file; the configured name on in-memory SQLite).
     */
    protected static function mirrorSession(): string
    {
        return \Illuminate\Container\Container::getInstance()->make(PackageSession::class)->mirrorName();
    }

    protected function mirrorSchema(): Builder
    {
        return $this->mirror()->getSchemaBuilder();
    }

    /**
     * Makes the package's storage fail until end() or the end of the test.
     * Call it outside of any transaction (see Support\MirrorOutage).
     */
    protected function mirrorOutage(): MirrorOutage
    {

        return $this->mirrorOutages[] = MirrorOutage::begin($this->app, $this->testEnvironment());
    }

    /**
     * Ends the outages that a test left active, so the migrations can roll
     * back. A transaction that the test left open (through Laravel or on the
     * PDO) is rolled back first.
     */
    private function endMirrorOutages(): void
    {
        $outages = array_filter($this->mirrorOutages, static fn (MirrorOutage $outage): bool => $outage->isActive());
        $this->mirrorOutages = [];

        if ($outages === [] || $this->app === null) {
            return;
        }

        // The same rule as the outage's own guard: Laravel's transactions and
        // transactions opened on an existing PDO.
        MirrorOutage::rollBackOpenTransactions($this->app);

        foreach (array_reverse($outages) as $outage) {
            $outage->end();
        }
    }

    protected function work(int $times = 1): void
    {
        /** @var Worker $worker */
        $worker = $this->app->make('queue.worker');

        for ($i = 0; $i < $times; $i++) {
            $worker->runNextJob('database', 'default', $this->workerOptions());
        }
    }

    protected function workerOptions(): WorkerOptions
    {
        $options = new WorkerOptions;
        $options->sleep = 0;
        $options->maxTries = 1;

        return $options;
    }
}
