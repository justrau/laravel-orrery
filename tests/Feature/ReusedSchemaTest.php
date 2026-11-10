<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Justrau\Orrery\Tests\Support\Fixtures\FreshSchema\MarkedChild;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\ReusedSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The reuse schema mode (Support\ReusedSchema): the reset after each test,
 * the schema fingerprint guard, the emptiness assertion and the FreshSchema
 * marker.
 *
 * The SQLite-file tests run on every engine, on a private pair of files in
 * separate mode with a table prefix, so both test databases and the prefix
 * are covered. The run-database tests use the run's own database (MySQL,
 * Postgres or a SQLite file) and carry #[FreshSchema], so they never touch
 * the kept schema of a reuse run.
 */
#[Group('harness')]
final class ReusedSchemaTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }

            $this->files = [];
        }
    }

    /**
     * Rejects: a reset that leaves rows, that keeps the used ids, that empties only one of the two databases, or that empties the migrations table.
     */
    public function test_the_reset_empties_every_table_and_restarts_the_ids_on_a_sqlite_file(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $schema = ReusedSchema::forTesting($env);
        $schema->prepare('key', self::sqliteMigration($primary, $mirror));

        self::fill($primary, $mirror);
        $schema->afterTest('the first test');

        self::assertSame(0, self::rows($primary, 'jobs'));
        self::assertSame(0, self::rows($mirror, 'pfx_orrery_jobs'));
        self::assertSame(0, self::rows($mirror, 'pfx_orrery_settings'));
        self::assertSame(1, self::rows($primary, 'migrations'), 'the reset emptied the migrations table');
        self::assertSame(1, self::rows($mirror, 'pfx_migrations'), 'the reset emptied the migrations table of the mirror');
        self::assertSame(1, self::insertId($primary, 'jobs'), 'the id of jobs did not restart');
        self::assertSame(1, self::insertId($mirror, 'pfx_orrery_jobs'), 'the id of the prefixed mirror table did not restart');
        self::assertTrue($schema->kept());
    }

    /**
     * Rejects: a guard that ignores an added table, column or index, or a dropped table; a guard that keeps the changed schema; a message without the marker.
     *
     * @param  Closure(PDO, PDO): void  $change
     */
    #[DataProvider('schemaChanges')]
    public function test_a_changed_schema_fails_the_test_names_the_marker_and_is_dropped(Closure $change, string $shown): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $schema = ReusedSchema::forTesting($env);
        $schema->prepare('key', self::sqliteMigration($primary, $mirror));
        self::fill($primary, $mirror);
        $change($primary, $mirror);

        try {
            $schema->afterTest('Some\\Test::test_it');
            self::fail('The guard accepted a changed schema.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The schema after Some\\Test::test_it differs from the migrated state', $failure->getMessage());
            self::assertStringContainsString('#['.FreshSchema::class.']', $failure->getMessage());
            self::assertStringContainsString($shown, $failure->getMessage());
        }

        self::assertFalse($schema->kept(), 'the changed schema is still kept');
        self::assertSame([], self::tables($primary), 'the changed primary database was not dropped');
        self::assertSame([], self::tables($mirror), 'the changed mirror database was not dropped');

        $migrations = 0;
        $schema->prepare('key', static function () use (&$migrations, $primary, $mirror): void {
            $migrations++;
            self::sqliteMigration($primary, $mirror)();
        });
        self::assertSame(1, $migrations, 'the next test did not migrate again');
    }

    /**
     * @return array<string, array{0: Closure(PDO, PDO): void, 1: string}>
     */
    public static function schemaChanges(): array
    {
        return [
            'an added table' => [static fn (PDO $primary): int|false => $primary->exec('CREATE TABLE scratch (id INTEGER PRIMARY KEY)'), 'scratch'],
            'an added column' => [static fn (PDO $primary, PDO $mirror): int|false => $mirror->exec('ALTER TABLE pfx_orrery_jobs ADD COLUMN extra TEXT'), 'extra'],
            'an added index' => [static fn (PDO $primary, PDO $mirror): int|false => $mirror->exec('CREATE INDEX pfx_extra_index ON pfx_orrery_jobs (uuid)'), 'pfx_extra_index'],
            'a dropped table' => [static fn (PDO $primary, PDO $mirror): int|false => $mirror->exec('DROP TABLE pfx_orrery_settings'), 'pfx_orrery_settings'],
        ];
    }

    /**
     * Rejects: a reset that does not check its own result, so a table that is not empty after the reset stays kept.
     */
    public function test_a_table_that_is_not_empty_after_the_reset_fails_and_drops_the_schema(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $schema = ReusedSchema::forTesting($env);
        $schema->prepare('key', static function () use ($primary, $mirror): void {
            self::sqliteMigration($primary, $mirror)();
            // A trigger of the migrated schema writes a row again after each delete.
            $primary->exec('CREATE TABLE refill (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT)');
            $primary->exec("CREATE TRIGGER refill_again AFTER DELETE ON jobs BEGIN INSERT INTO refill (note) VALUES ('again'); END");
        });
        self::fill($primary, $mirror);

        try {
            $schema->afterTest('the test');
            self::fail('The reset accepted a table with rows.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('The reuse schema is not clean after the reset in the primary database', $failure->getMessage());
            self::assertStringContainsString('"refill"', $failure->getMessage());
        }

        self::assertFalse($schema->kept(), 'a schema that the reset could not clean is still kept');
    }

    /**
     * Rejects: a kept schema that migrates before every test, or that ignores a changed configuration key.
     */
    public function test_one_key_migrates_once_and_another_key_migrates_again(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $schema = ReusedSchema::forTesting($env);
        $migrations = 0;
        $migrate = static function () use (&$migrations, $primary, $mirror): void {
            $migrations++;
            self::sqliteMigration($primary, $mirror)();
        };

        $schema->prepare('one', $migrate);
        $schema->afterTest('first');
        $schema->prepare('one', $migrate);
        $schema->afterTest('second');
        self::assertSame(1, $migrations);

        $schema->prepare('two', $migrate);
        self::assertSame(2, $migrations, 'another configuration key did not migrate again');
    }

    /**
     * Rejects: a kept schema whose migration writes rows that the first reset then deletes.
     */
    public function test_a_migration_that_writes_rows_is_refused(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $schema = ReusedSchema::forTesting($env);

        try {
            $schema->prepare('key', static function () use ($primary, $mirror): void {
                self::sqliteMigration($primary, $mirror)();
                $mirror->exec("INSERT INTO pfx_orrery_settings (name, value) VALUES ('seeded', '1')");
            });
            self::fail('The kept schema accepted a migration that writes rows.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('not clean after the migration in the mirror database', $failure->getMessage());
        }

        self::assertFalse($schema->kept());
    }

    /**
     * Rejects: a reset that misses the counters of a table that the test emptied itself, or the tables of the mirror, on the run's engine.
     */
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_the_reset_restarts_the_ids_on_the_run_database(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('In-memory SQLite has no reuse mode: each test has a new database.');
        }

        $schema = ReusedSchema::forTesting($env);
        $schema->prepare('key', fn () => $this->migrateOnce($env));

        $jobs = $this->app['db']->connection(TestEnvironment::PRIMARY);
        $job = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1];
        $first = $jobs->table('jobs')->insertGetId($job);
        $jobs->table('jobs')->insertGetId($job);
        // A table that the test empties itself: only its counter is used.
        $jobs->table('jobs')->delete();
        $this->mirror()->table('orrery_settings')->insert(['key' => 'reused', 'value' => '1']);

        $this->resetConnections();
        $schema->afterTest('the run-database test');

        self::assertSame(0, $this->mirror()->table('orrery_settings')->count());
        self::assertSame((int) $first, (int) $jobs->table('jobs')->insertGetId($job), 'the id of jobs did not restart');
        self::assertGreaterThan(0, $jobs->table('migrations')->count(), 'the reset emptied the migrations table');
    }

    /**
     * Rejects: a guard that misses a changed column on a server engine.
     */
    #[Group('shared-database')]
    #[FreshSchema]
    public function test_a_changed_column_fails_on_the_run_database(): void
    {
        $env = $this->testEnvironment();

        if ($env->inMemory()) {
            self::markTestSkipped('In-memory SQLite has no reuse mode: each test has a new database.');
        }

        $schema = ReusedSchema::forTesting($env);
        $schema->prepare('key', fn () => $this->migrateOnce($env));

        $this->mirrorSchema()->table('orrery_settings', static function (Blueprint $table): void {
            $table->string('reuse_probe')->nullable();
        });
        $this->resetConnections();

        try {
            $schema->afterTest('the run-database test');
            self::fail('The guard accepted a changed column.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('reuse_probe', $failure->getMessage());
        }

        self::assertFalse($schema->kept());
        self::assertFalse($this->mirrorSchema()->hasTable('orrery_settings'), 'the changed schema was not dropped');

        // The next preparation migrates again, and the fresh rollback of this
        // test finds its tables.
        $schema->prepare('key', fn () => $this->migrateOnce($env));
        self::assertTrue($this->mirrorSchema()->hasTable('orrery_settings'));
        self::assertFalse($this->mirrorSchema()->hasColumn('orrery_settings', 'reuse_probe'));
    }

    /**
     * Rejects: a mirror outage (DDL) in a test without the marker.
     */
    public function test_an_unmarked_test_cannot_begin_a_mirror_outage(): void
    {
        try {
            $this->mirrorOutage();
            self::fail('A test without #[FreshSchema] began a mirror outage.');
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('MirrorOutage::begin() changes the schema', $refusal->getMessage());
            self::assertStringContainsString(self::class.'::test_an_unmarked_test_cannot_begin_a_mirror_outage needs the attribute #['.FreshSchema::class.']', $refusal->getMessage());
        }
    }

    /**
     * Rejects: a marker on the class or on a parent class that the check does not see, or a marker on another method that counts.
     */
    public function test_the_marker_is_read_from_the_method_and_the_class_chain(): void
    {
        self::assertFalse(FreshSchema::marked($this));
        self::assertTrue(FreshSchema::marked(new MarkedChild('probe')));
        self::assertTrue(FreshSchema::marked(new self('test_the_reset_restarts_the_ids_on_the_run_database')));
    }

    /**
     * Rejects: a mode that accepts reuse on in-memory SQLite (its schema dies with each test) or an unknown value.
     */
    public function test_the_mode_refuses_reuse_in_memory_and_unknown_values(): void
    {
        $saved = getenv(ReusedSchema::VARIABLE);
        $memory = TestEnvironment::fromArray([]);
        [$file] = $this->privateFiles();

        try {
            putenv(ReusedSchema::VARIABLE);
            self::assertSame(ReusedSchema::FRESH, ReusedSchema::mode($memory));

            putenv(ReusedSchema::VARIABLE.'=reuse');
            self::assertSame(ReusedSchema::REUSE, ReusedSchema::mode($file));
            self::assertRefused(static fn () => ReusedSchema::mode($memory), 'In-memory SQLite runs only the fresh mode');

            putenv(ReusedSchema::VARIABLE.'=sometimes');
            self::assertRefused(static fn () => ReusedSchema::mode($file), "must be fresh or reuse, not 'sometimes'");
        } finally {
            putenv($saved === false ? ReusedSchema::VARIABLE : ReusedSchema::VARIABLE.'='.$saved);
        }
    }

    // Helpers

    private static function assertRefused(Closure $call, string $message): void
    {
        try {
            $call();
            self::fail('No refusal: '.$message);
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString($message, $refusal->getMessage());
        }
    }

    /**
     * Drops the PDOs of this test's connections, as the reset of TestCase does.
     */
    private function resetConnections(): void
    {
        foreach ($this->app['db']->getConnections() as $connection) {
            $connection->setPdo(null)->setReadPdo(null);
        }

        $this->app['db']->purge(TestEnvironment::PRIMARY);
        $this->app['db']->purge(TestEnvironment::MIRROR);
    }

    /**
     * A private pair of SQLite files in separate mode with the prefix pfx_.
     *
     * @return array{0: TestEnvironment, 1: PDO, 2: PDO}
     */
    private function privateFiles(): array
    {
        $file = $this->files[] = SqliteFiles::create();
        $env = TestEnvironment::fromArray(['ORRERY_TEST_SQLITE_FILE' => $file, 'ORRERY_TEST_MIRROR' => 'separate', 'ORRERY_TEST_PREFIX' => 'pfx_']);
        $pdo = static fn (string $path): PDO => new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return [$env, $pdo($env->database), $pdo($env->mirrorDatabase())];
    }

    /**
     * @return Closure(): void
     */
    private static function sqliteMigration(PDO $primary, PDO $mirror): Closure
    {
        return static function () use ($primary, $mirror): void {
            $primary->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL)');
            $primary->exec("INSERT INTO migrations (migration) VALUES ('create_jobs')");
            $primary->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL)');
            $mirror->exec('CREATE TABLE pfx_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL)');
            $mirror->exec("INSERT INTO pfx_migrations (migration) VALUES ('create_orrery_tables')");
            $mirror->exec('CREATE TABLE pfx_orrery_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL)');
            $mirror->exec('CREATE UNIQUE INDEX pfx_orrery_jobs_uuid ON pfx_orrery_jobs (uuid)');
            $mirror->exec('CREATE TABLE pfx_orrery_settings (name TEXT PRIMARY KEY, value TEXT)');
        };
    }

    private static function fill(PDO $primary, PDO $mirror): void
    {
        $primary->exec("INSERT INTO jobs (queue) VALUES ('a'), ('b'), ('c')");
        $mirror->exec("INSERT INTO pfx_orrery_jobs (uuid) VALUES ('u1'), ('u2')");
        $mirror->exec("INSERT INTO pfx_orrery_settings (name, value) VALUES ('k', 'v')");
    }

    private static function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    }

    private static function insertId(PDO $pdo, string $table): int
    {
        $column = $table === 'jobs' ? 'queue' : 'uuid';
        $pdo->exec("INSERT INTO \"{$table}\" ({$column}) VALUES ('next')");

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<string>
     */
    private static function tables(PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
}
