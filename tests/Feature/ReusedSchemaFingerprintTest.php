<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Closure;
use Justrau\Orrery\Tests\Support\FreshSchema;
use Justrau\Orrery\Tests\Support\PdoFromConfig;
use Justrau\Orrery\Tests\Support\ReusedSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The schema fingerprint of the reuse mode (Support\ReusedSchema), one
 * captured property at a time: each case changes exactly one property of a
 * small schema and expects the guard to fail. The control case changes
 * nothing and expects the guard to pass and the reset to restart the ids.
 *
 * The SQLite cases run on a private pair of SQLite files (separate mode,
 * prefix pfx_), so they run on every engine. The MySQL and Postgres cases
 * run on the run's own databases, only on their engine. The class carries
 * #[FreshSchema] and migrates nothing itself: each case builds its small
 * schema on the empty databases and drops it at the end.
 */
#[Group('harness')]
#[FreshSchema]
final class ReusedSchemaFingerprintTest extends TestCase
{
    use PdoFromConfig;

    /** @var list<string> */
    private array $files = [];

    private ?ReusedSchema $schema = null;

    /**
     * No package migration: the cases build their own schema.
     */
    protected function migrateFresh(TestEnvironment $environment): void
    {
    }

    protected function tearDown(): void
    {
        try {
            $this->schema?->discard();
            $this->schema = null;
            parent::tearDown();
        } finally {
            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }

            $this->files = [];
        }
    }

    /**
     * Rejects: a fingerprint that does not capture this property, for
     * example one that reads only the table and column names, or that
     * leaves out the views, the sequences or the schemas.
     * Still passes: a guard that sees the change through another value
     * than the named property (for example the whole SQL text of the
     * table), as long as its message contains the expected value of the
     * change (a type, a default, a collation or a name).
     *
     * @param  Closure(PDO, string): void  $change
     */
    #[DataProvider('changes')]
    public function test_a_change_of_one_captured_property_fails_the_guard(string $engine, string $property, Closure $change, string $shown): void
    {
        [$env, $primary, $mirror] = $this->databasesFor($engine);
        $this->schema = ReusedSchema::forTesting($env);
        $this->schema->prepare('key', static fn () => self::probeSchema($engine, $env, $primary, $mirror));
        self::fill($engine, $env, $primary, $mirror);
        $change($primary, $env->primaryPrefix());
        $primary = $mirror = null;

        $failure = null;

        try {
            $this->schema->afterTest('Probe\\Test::test_it');
        } catch (AssertionFailedError $caught) {
            $failure = $caught;
        }

        self::assertNotNull($failure, "The guard accepted a schema whose {$property} changed.");
        self::assertStringContainsString('The schema after Probe\\Test::test_it differs from the migrated state', $failure->getMessage());
        self::assertStringContainsString($shown, $failure->getMessage());
        self::assertFalse($this->schema->kept(), 'the changed schema is still kept');
        self::assertSame([], self::tables($engine, $this->databasesFor($engine, $env)[1]), 'the changed schema was not dropped');
    }

    /**
     * Rejects: a fingerprint that holds a value that changes with the rows
     * (an auto-increment counter, a row count, table statistics), so a
     * test that only wrote rows fails the guard; a reset that keeps the
     * rows or the used ids (also of a table that the test emptied itself),
     * or empties the migrations table; a reset that stops at a foreign key
     * between two tables with rows.
     * Still passes: a reset that empties every table after every test.
     */
    #[DataProvider('engines')]
    public function test_an_unchanged_schema_passes_and_is_reset(string $engine): void
    {
        [$env, $primary, $mirror] = $this->databasesFor($engine);
        $this->schema = ReusedSchema::forTesting($env);
        $this->schema->prepare('key', static fn () => self::probeSchema($engine, $env, $primary, $mirror));
        self::fill($engine, $env, $primary, $mirror);
        $p = self::quote($engine, $env->primaryPrefix().'probe_jobs');
        $tags = self::quote($engine, $env->primaryPrefix().'probe_tags');
        // A deleted row: the counter is above the rows that stay.
        $primary->exec("DELETE FROM {$p} WHERE queue = 'b'");
        // A table that the test emptied itself: only its counter is used.
        $primary->exec("INSERT INTO {$tags} (tag) VALUES ('x')");
        $primary->exec("DELETE FROM {$tags}");

        $this->schema->afterTest('Probe\\Test::test_it');

        self::assertTrue($this->schema->kept());
        self::assertSame(0, (int) $primary->query("SELECT COUNT(*) FROM {$p}")->fetchColumn());
        self::assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM '.self::quote($engine, $env->primaryPrefix().'probe_x_children'))->fetchColumn(), 'the child table of a foreign key kept its rows');
        self::assertSame(0, (int) $primary->query('SELECT COUNT(*) FROM '.self::quote($engine, $env->primaryPrefix().'probe_a_parents'))->fetchColumn(), 'the parent table of a foreign key kept its rows');
        self::assertSame(1, (int) $primary->query('SELECT COUNT(*) FROM '.self::quote($engine, $env->primaryPrefix().'migrations'))->fetchColumn(), 'the reset emptied the migrations table');
        $primary->exec("INSERT INTO {$p} (queue) VALUES ('next')");
        self::assertSame(1, (int) $primary->query("SELECT MAX(id) FROM {$p}")->fetchColumn(), 'the id did not restart');
        $primary->exec("INSERT INTO {$tags} (tag) VALUES ('y')");
        self::assertSame(1, (int) $primary->query("SELECT MAX(id) FROM {$tags}")->fetchColumn(), 'the id of the table that the test emptied did not restart');

        if ($mirror !== null) {
            $settings = self::quote($engine, $env->mirrorPrefix().'probe_settings');
            self::assertSame(0, (int) $mirror->query("SELECT COUNT(*) FROM {$settings}")->fetchColumn(), 'the mirror database kept its rows');
        }

        // A database in which only a counter is used: the next reset restarts it too.
        $primary->exec("DELETE FROM {$tags}");
        $primary->exec("DELETE FROM {$p}");
        $primary->exec("INSERT INTO {$tags} (tag) VALUES ('z')");
        $primary->exec("DELETE FROM {$tags}");
        $this->schema->afterTest('Probe\\Test::test_again');
        self::assertTrue($this->schema->kept());
        $primary->exec("INSERT INTO {$tags} (tag) VALUES ('again')");
        self::assertSame(1, (int) $primary->query("SELECT MAX(id) FROM {$tags}")->fetchColumn(), 'the id of a database with only a used counter did not restart');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        $engines = ['sqlite' => ['sqlite']];
        $driver = TestEnvironment::fromEnv()->driver;

        if ($driver !== 'sqlite') {
            $engines[$driver] = [$driver];
        }

        return $engines;
    }

    /**
     * One change per captured property: the SQLite cases always, the cases
     * of the run's server engine on that engine.
     *
     * @return array<string, array{0: string, 1: string, 2: Closure(PDO, string): void, 3: string}>
     */
    public static function changes(): array
    {
        $cases = [];
        $add = static function (string $engine, array $changes) use (&$cases): void {
            foreach ($changes as $property => [$change, $shown]) {
                $cases["{$engine}: {$property}"] = [$engine, $property, $change, $shown];
            }
        };

        $add('sqlite', self::sqliteChanges());

        $add(TestEnvironment::fromEnv()->driver, match (TestEnvironment::fromEnv()->driver) {
            'mysql' => self::mysqlChanges(),
            'pgsql' => self::postgresChanges(),
            default => [],
        });

        return $cases;
    }

    /**
     * @return array<string, array{0: Closure(PDO, string): void, 1: string}>
     */
    private static function sqliteChanges(): array
    {
        // SQLite cannot alter a column: the table is created again with the
        // same name, so only its SQL text differs.
        $rebuild = static fn (string $columns): Closure => static function (PDO $pdo) use ($columns): void {
            $pdo->exec('DROP TABLE probe_jobs');
            $pdo->exec("CREATE TABLE probe_jobs ({$columns})");
            $pdo->exec('CREATE INDEX probe_jobs_queue_index ON probe_jobs (queue)');
            $pdo->exec('CREATE UNIQUE INDEX probe_jobs_note_unique ON probe_jobs (note)');
        };
        $columns = static fn (string $attempts = 'attempts INTEGER NOT NULL DEFAULT 0', string $reserved = 'reserved_at INTEGER NULL'): string => "id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(191) NOT NULL, {$attempts}, {$reserved}, note VARCHAR(191) NULL";

        return [
            'column type' => [$rebuild($columns('attempts TEXT NOT NULL DEFAULT 0')), 'attempts TEXT'],
            'column default' => [$rebuild($columns('attempts INTEGER NOT NULL DEFAULT 4242')), '4242'],
            'nullability' => [$rebuild($columns(reserved: 'reserved_at INTEGER NOT NULL')), 'reserved_at INTEGER NOT NULL'],
            'index uniqueness' => [static function (PDO $pdo): void {
                $pdo->exec('DROP INDEX probe_jobs_queue_index');
                $pdo->exec('CREATE UNIQUE INDEX probe_jobs_queue_index ON probe_jobs (queue)');
            }, 'CREATE UNIQUE INDEX probe_jobs_queue_index'],
            'a view' => [static fn (PDO $pdo): int|false => $pdo->exec('CREATE VIEW probe_view AS SELECT id FROM probe_jobs'), 'probe_view'],
            'a trigger' => [static fn (PDO $pdo): int|false => $pdo->exec("CREATE TRIGGER probe_trigger AFTER INSERT ON probe_jobs BEGIN SELECT 1; END"), 'probe_trigger'],
        ];
    }

    /**
     * @return array<string, array{0: Closure(PDO, string): void, 1: string}>
     */
    private static function mysqlChanges(): array
    {
        $alter = static fn (string $sql): Closure => static fn (PDO $pdo, string $prefix): int|false => $pdo->exec('ALTER TABLE `'.$prefix.'probe_jobs` '.str_replace('{p}', $prefix, $sql));

        return [
            'column type' => [$alter('MODIFY attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0'), 'smallint'],
            'column default' => [$alter('ALTER COLUMN attempts SET DEFAULT 242'), '242'],
            'nullability' => [$alter('MODIFY reserved_at INT UNSIGNED NOT NULL'), 'reserved_at'],
            'collation' => [$alter('MODIFY queue VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'), 'utf8mb4_bin'],
            'extra' => [$alter('MODIFY id BIGINT UNSIGNED NOT NULL'), 'auto_increment'],
            'column position' => [$alter('MODIFY note VARCHAR(191) NULL FIRST'), 'note'],
            'index uniqueness' => [$alter('DROP INDEX `{p}probe_jobs_queue_index`, ADD UNIQUE INDEX `{p}probe_jobs_queue_index` (queue)'), 'probe_jobs_queue_index'],
            'index prefix length' => [$alter('DROP INDEX `{p}probe_jobs_queue_index`, ADD INDEX `{p}probe_jobs_queue_index` (queue(20))'), 'probe_jobs_queue_index'],
            'a view' => [static fn (PDO $pdo, string $prefix): int|false => $pdo->exec("CREATE VIEW `{$prefix}probe_view` AS SELECT id FROM `{$prefix}probe_jobs`"), 'probe_view'],
        ];
    }

    /**
     * @return array<string, array{0: Closure(PDO, string): void, 1: string}>
     */
    private static function postgresChanges(): array
    {
        $alter = static fn (string $sql): Closure => static fn (PDO $pdo, string $prefix): int|false => $pdo->exec('ALTER TABLE "'.$prefix.'probe_jobs" '.$sql);

        return [
            'column type' => [$alter('ALTER COLUMN attempts TYPE numeric(9,2)'), 'numeric(9,2)'],
            'type modifier' => [$alter('ALTER COLUMN queue TYPE varchar(64)'), 'character varying(64)'],
            'column default' => [$alter('ALTER COLUMN attempts SET DEFAULT 4242'), '4242'],
            'nullability' => [$alter('ALTER COLUMN reserved_at SET NOT NULL'), 'reserved_at'],
            'index uniqueness' => [static function (PDO $pdo, string $prefix): void {
                $pdo->exec("DROP INDEX \"{$prefix}probe_jobs_queue_index\"");
                $pdo->exec("CREATE UNIQUE INDEX \"{$prefix}probe_jobs_queue_index\" ON \"{$prefix}probe_jobs\" (queue)");
            }, 'probe_jobs_queue_index'],
            'a view' => [static fn (PDO $pdo, string $prefix): int|false => $pdo->exec("CREATE VIEW \"{$prefix}probe_view\" AS SELECT id FROM \"{$prefix}probe_jobs\""), 'probe_view'],
            'a sequence' => [static fn (PDO $pdo, string $prefix): int|false => $pdo->exec("CREATE SEQUENCE \"{$prefix}probe_sequence\""), 'probe_sequence'],
            'an extra schema' => [static fn (PDO $pdo): int|false => $pdo->exec('CREATE SCHEMA orrery_probe_schema'), 'orrery_probe_schema'],
        ];
    }

    // Helpers

    /**
     * The environment and the PDOs of the primary and (in separate mode)
     * the mirror database: private SQLite files for the sqlite cases, the
     * run's databases for a server engine.
     *
     * @return array{0: TestEnvironment, 1: PDO, 2: PDO|null}
     */
    private function databasesFor(string $engine, ?TestEnvironment $env = null): array
    {
        if ($env === null && $engine === 'sqlite') {
            $file = $this->files[] = SqliteFiles::create();
            $env = TestEnvironment::fromArray(['ORRERY_TEST_SQLITE_FILE' => $file, 'ORRERY_TEST_MIRROR' => 'separate', 'ORRERY_TEST_PREFIX' => 'pfx_']);
        }

        $env ??= $this->testEnvironment();
        $mirror = $env->mirrorConnection();

        return [$env, self::pdo($env->queueConnection()), $mirror === null ? null : self::pdo($mirror)];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function pdo(array $config): PDO
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);

        return new PDO($dsn, $username, $password, $options);
    }

    private static function quote(string $engine, string $name): string
    {
        return $engine === 'mysql' ? "`{$name}`" : "\"{$name}\"";
    }

    /**
     * The small schema: a migrations table with its row, a probe table
     * with two indexes, a second table, and a parent and a child table with
     * a foreign key in the primary database, and in separate mode a
     * migrations table and a settings table in the mirror database.
     */
    private static function probeSchema(string $engine, TestEnvironment $env, PDO $primary, ?PDO $mirror): void
    {
        [$serial, $bigSerial, $tiny, $text, $suffix] = match ($engine) {
            'mysql' => ['INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'TINYINT UNSIGNED', 'TEXT', ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],
            'pgsql' => ['serial PRIMARY KEY', 'bigserial PRIMARY KEY', 'smallint', 'text', ''],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER', 'TEXT', ''],
        };
        $q = static fn (string $name): string => self::quote($engine, $name);
        $migrations = static function (PDO $pdo, string $prefix) use ($q, $serial, $suffix): void {
            $pdo->exec('CREATE TABLE '.$q($prefix.'migrations')." (id {$serial}, migration VARCHAR(191) NOT NULL){$suffix}");
            $pdo->exec('INSERT INTO '.$q($prefix.'migrations')." (migration) VALUES ('probe')");
        };

        $p = $env->primaryPrefix();
        $migrations($primary, $p);
        $primary->exec('CREATE TABLE '.$q($p.'probe_jobs')." (id {$bigSerial}, queue VARCHAR(191) NOT NULL, attempts {$tiny} NOT NULL DEFAULT 0, reserved_at ".($engine === 'mysql' ? 'INT UNSIGNED' : 'INTEGER')." NULL, note VARCHAR(191) NULL){$suffix}");
        $primary->exec('CREATE INDEX '.$q($p.'probe_jobs_queue_index').' ON '.$q($p.'probe_jobs').' (queue)');
        $primary->exec('CREATE UNIQUE INDEX '.$q($p.'probe_jobs_note_unique').' ON '.$q($p.'probe_jobs').' (note)');
        $primary->exec('CREATE TABLE '.$q($p.'probe_tags')." (id {$bigSerial}, tag VARCHAR(191) NOT NULL){$suffix}");
        // A foreign key whose parent sorts before its child: on Postgres a
        // delete of the parent first fails.
        $primary->exec('CREATE TABLE '.$q($p.'probe_a_parents')." (id {$bigSerial}){$suffix}");
        $primary->exec('CREATE TABLE '.$q($p.'probe_x_children').' (parent_id '.match ($engine) {
            'mysql' => 'BIGINT UNSIGNED',
            'pgsql' => 'bigint',
            default => 'INTEGER',
        }.' NOT NULL REFERENCES '.$q($p.'probe_a_parents')." (id)){$suffix}");

        if ($mirror !== null) {
            $m = $env->mirrorPrefix();
            $migrations($mirror, $m);
            $mirror->exec('CREATE TABLE '.$q($m.'probe_settings')." (name VARCHAR(191) NOT NULL PRIMARY KEY, value {$text} NULL){$suffix}");
        }
    }

    private static function fill(string $engine, TestEnvironment $env, PDO $primary, ?PDO $mirror): void
    {
        $primary->exec('INSERT INTO '.self::quote($engine, $env->primaryPrefix().'probe_jobs')." (queue, reserved_at, note) VALUES ('a', 5, 'one'), ('b', 5, 'two')");
        $primary->exec('INSERT INTO '.self::quote($engine, $env->primaryPrefix().'probe_a_parents').' (id) VALUES (1)');
        $primary->exec('INSERT INTO '.self::quote($engine, $env->primaryPrefix().'probe_x_children').' (parent_id) VALUES (1)');
        $mirror?->exec('INSERT INTO '.self::quote($engine, $env->mirrorPrefix().'probe_settings')." (name, value) VALUES ('k', 'v')");
    }

    /**
     * The tables and views of the primary database.
     *
     * @return list<string>
     */
    private static function tables(string $engine, PDO $pdo): array
    {
        return $pdo->query(match ($engine) {
            'mysql' => 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
            'pgsql' => "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S')",
            default => "SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
        })->fetchAll(PDO::FETCH_COLUMN);
    }
}
