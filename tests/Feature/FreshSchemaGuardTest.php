<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard\FailedLeftoverProbe;
use Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard\IdentityRefusalProbe;
use Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard\MirrorLeftoverProbe;
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\HarnessTiming;
use Justrau\Orrery\Tests\Support\ReusedSchema;
use Justrau\Orrery\Tests\Support\SqliteFiles;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use SimpleXMLElement;
use Symfony\Component\Process\Process;

/**
 * The guard of the fresh schema mode (ReusedSchema::afterFreshTest() and
 * beforeFreshTest(), called by TestCase): a test whose rollback leaves
 * objects in the test databases fails, the objects are dropped, and the
 * next test migrates on empty databases. Before the guard, one rollback
 * that missed the mirror (wave-B matrix, SchemaTest) failed about 900 later
 * tests with "table orrery_jobs already exists".
 *
 * The guard tests run on a private pair of SQLite files in separate mode
 * with a table prefix, on every engine. The cascade test runs a child
 * PHPUnit process on its own SQLite files.
 */
#[Group('harness')]
final class FreshSchemaGuardTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->files as $file) {
                SqliteFiles::remove($file);
            }

            $this->files = [];

            foreach ($this->scratch as $scratch) {
                if (is_file($scratch)) {
                    unlink($scratch);
                }
            }

            $this->scratch = [];
        }
    }

    /**
     * Rejects: a guard that reports the empty migrations tables of a clean
     * rollback, or that drops them.
     */
    public function test_a_clean_rollback_passes_and_keeps_the_migrations_tables(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        self::migrationsTables($primary, $mirror);

        ReusedSchema::afterFreshTest($env, 'Some\\Test::test_it');
        ReusedSchema::beforeFreshTest($env, 'Some\\Test::test_next');

        self::assertSame(['migrations'], self::tables($primary));
        self::assertSame(['pfx_migrations'], self::tables($mirror));
    }

    /**
     * Rejects: a guard that checks only the primary database (the wave-B
     * leftovers were in the mirror), that keeps the leftovers, or whose
     * message names neither the test nor the objects.
     */
    public function test_a_rollback_that_leaves_tables_fails_the_test_and_drops_them(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        self::migrationsTables($primary, $mirror);
        $mirror->exec('CREATE TABLE pfx_orrery_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL)');
        $mirror->exec('CREATE VIEW pfx_orrery_view AS SELECT id FROM pfx_orrery_jobs');

        try {
            ReusedSchema::afterFreshTest($env, 'Some\\Test::test_it');
            self::fail('The guard accepted the leftover tables.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('The fresh schema rollback after Some\\Test::test_it left objects', $failure->getMessage());
            self::assertStringContainsString('mirror table pfx_orrery_jobs', $failure->getMessage());
            self::assertStringContainsString('mirror view pfx_orrery_view', $failure->getMessage());
        }

        self::assertSame([], self::tables($primary));
        self::assertSame([], self::tables($mirror), 'the leftovers of the mirror stay');
        // The next migration creates the same table again.
        $mirror->exec('CREATE TABLE pfx_orrery_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL)');
    }

    /**
     * Rejects: a guard that checks only the tables, so rows left in a
     * migrations table (the next migrate then skips the package migration)
     * pass.
     */
    public function test_rows_left_in_a_migrations_table_fail(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        self::migrationsTables($primary, $mirror);
        $primary->exec("INSERT INTO migrations (migration, batch) VALUES ('create_jobs', 1)");

        try {
            ReusedSchema::afterFreshTest($env, 'Some\\Test::test_it');
            self::fail('The guard accepted a filled migrations table.');
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('primary rows in migrations', $failure->getMessage());
        }

        self::assertSame([], self::tables($primary));
    }

    /**
     * Rejects: a check before the migration that migrates on top of the
     * leftovers, or that refuses without dropping them (then every later
     * test refuses too).
     */
    public function test_leftovers_before_a_migration_refuse_once_and_are_dropped(): void
    {
        [$env, $primary, $mirror] = $this->privateFiles();
        $primary->exec('CREATE TABLE scratch (id INTEGER PRIMARY KEY)');

        try {
            ReusedSchema::beforeFreshTest($env, 'Some\\Test::test_it');
            self::fail('The check accepted the leftover table.');
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('before the fresh migration of Some\\Test::test_it', $refusal->getMessage());
            self::assertStringContainsString('primary table scratch', $refusal->getMessage());
        }

        self::assertSame([], self::tables($primary));
        ReusedSchema::beforeFreshTest($env, 'Some\\Test::test_next');
    }

    /**
     * Rejects: a harness where one rollback that misses the mirror fails the
     * migration of every later test (the wave-B cascade), and a guard that
     * passes the test that left the tables.
     */
    public function test_one_rollback_that_misses_the_mirror_fails_only_its_own_test(): void
    {
        [$results, $output, $exit] = $this->runProbe(MirrorLeftoverProbe::class);

        self::assertCount(3, $results, $output);
        self::assertSame('passed', $results['test_the_next_test_migrates'], $output);
        self::assertSame('passed', $results['test_and_the_one_after_it'], $output);
        self::assertStringContainsString(
            'The fresh schema rollback after '.MirrorLeftoverProbe::class.'::test_a_rollback_that_misses_the_mirror left objects',
            $results['test_a_rollback_that_misses_the_mirror'],
        );
        self::assertStringContainsString('mirror table orrery_jobs', $results['test_a_rollback_that_misses_the_mirror']);
        self::assertSame(1, $exit, $output);
    }

    /**
     * Rejects: a teardown guard that connects to the test databases (and
     * could drop their tables) after setUp() stopped before the endpoint
     * proof, so on an endpoint that the test never proved. The harness
     * timing of the child counts each guard and check.
     */
    public function test_a_test_refused_before_its_endpoint_proof_runs_no_guard(): void
    {
        $timing = $this->scratchFile();
        [$results, $output] = $this->runProbe(IdentityRefusalProbe::class, [HarnessTiming::VARIABLE => $timing]);

        self::assertCount(1, $results, $output);
        self::assertStringContainsString('identity', $results['test_never_runs'], $output);
        $phases = (array) (json_decode((string) file_get_contents($timing), true)['phases'] ?? []);
        self::assertArrayHasKey('identity', $phases, $output);
        self::assertArrayNotHasKey('fresh.guard', $phases, 'the guard ran after a refused endpoint proof');
        self::assertArrayNotHasKey('fresh.check', $phases);
    }

    /**
     * Rejects: a leak that stays silent because the test failed already
     * (PHPUnit then drops the guard's teardown failure): the guard writes
     * one line with the test and the objects to STDERR.
     */
    public function test_a_failed_test_that_leaves_tables_names_them_on_stderr(): void
    {
        [$results, $output] = $this->runProbe(FailedLeftoverProbe::class);

        self::assertCount(1, $results, $output);
        self::assertStringContainsString('The probe fails.', $results['test_fails_and_leaves_the_mirror_migrated'], $output);
        self::assertMatchesRegularExpression(
            '/^orrery fresh guard: The fresh schema rollback after '.preg_quote(FailedLeftoverProbe::class.'::test_fails_and_leaves_the_mirror_migrated', '/').' left objects.*mirror table orrery_jobs/m',
            $output,
        );
    }

    /**
     * Runs one probe class in a child PHPUnit, fresh mode on its own SQLite
     * files in separate mode.
     *
     * @param  class-string  $probe
     * @param  array<string, string>  $extra
     * @return array{0: array<string, string>, 1: string, 2: int|null} the result per test ('passed' or the fault), the output, the exit code
     */
    private function runProbe(string $probe, array $extra = []): array
    {
        $file = $this->files[] = SqliteFiles::create();
        $junit = $this->scratchFile();
        $root = dirname(__DIR__, 2);
        // Process inherits the parent's variables: false unsets one, so the
        // run's engine, prefix and database never reach the child.
        $environment = array_map(
            static fn (): bool => false,
            array_filter(getenv(), static fn (string $name): bool => preg_match('/^(ORRERY_TEST_|ORRERY_EXPECT_|DB_|DATABASE_URL$)/', $name) === 1, ARRAY_FILTER_USE_KEY),
        );
        $environment = $extra + [
            'ORRERY_TEST_DRIVER' => 'sqlite',
            'ORRERY_TEST_SQLITE_FILE' => $file,
            'ORRERY_TEST_MIRROR' => 'separate',
            'ORRERY_TEST_SCHEMA' => 'fresh',
        ] + $environment;
        $path = $root.'/tests/Support/Fixtures/FreshSchemaGuard/'.substr($probe, (int) strrpos($probe, '\\') + 1).'.php';

        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml.dist', '--do-not-cache-result', '--colors=never',
            '--log-junit', $junit, $path,
        ], $root, $environment, null, 120);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        self::assertFileExists($junit, $output);
        $results = [];

        foreach ((new SimpleXMLElement((string) file_get_contents($junit)))->xpath('//testcase') ?: [] as $case) {
            $fault = $case->failure ?? $case->error ?? null;
            $results[(string) $case['name']] = $fault === null ? 'passed' : (string) $fault;
        }

        return [$results, $output, $process->getExitCode()];
    }

    /**
     * A temporary file outside the SQLite directories (SqliteFiles::remove()
     * refuses a directory with other files), removed in tearDown().
     */
    private function scratchFile(): string
    {
        return $this->scratch[] = (string) tempnam(sys_get_temp_dir(), 'orrery-fresh-guard-');
    }

    /**
     * @return array{0: TestEnvironment, 1: PDO, 2: PDO}
     */
    private function privateFiles(): array
    {
        $file = $this->files[] = SqliteFiles::create();
        $env = TestEnvironment::fromArray(['ORRERY_TEST_SQLITE_FILE' => $file, 'ORRERY_TEST_MIRROR' => 'separate', 'ORRERY_TEST_PREFIX' => 'pfx_']);
        $pdo = static fn (string $path): PDO => new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return [$env, $pdo($env->database), $pdo((string) $env->mirrorDatabase())];
    }

    /**
     * The state after a clean rollback: each database keeps its empty
     * migrations table.
     */
    private static function migrationsTables(PDO $primary, PDO $mirror): void
    {
        $primary->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
        $mirror->exec('CREATE TABLE pfx_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
    }

    /**
     * @return list<string>
     */
    private static function tables(PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
}
