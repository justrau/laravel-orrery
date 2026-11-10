<?php

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Workbench\App\Console\OwnershipProbeCommand;
use Workbench\App\Support\WorkbenchDatabase;

/**
 * Base class for tests that run the real Workbench CLI through its launcher
 * (`workbench/bin/testbench`).
 *
 * Those tests share resources of the checkout: the workbench/database directory, the owned
 * bootstrap cache directory workbench/storage/bootstrap-cache that the launcher pins, and the
 * Testbench skeleton in vendor/, where every CLI run copies .env and bootstrap/cache/testbench.yaml.
 * So one checkout-wide lock is held from the start of setUp() to the end of tearDown(). It covers
 * the setup, every CLI run, every assertion and the cleanup. Two suite runs in one checkout
 * therefore run these tests one after the other. The lock file is inside the checkout, so it does
 * not depend on TMPDIR, and a matrix copy has its own.
 *
 * Ordinary package tests (tests/TestCase.php) do not take the lock. They read and write the
 * package and services manifests at the skeleton defaults, and the launcher keeps the Workbench
 * CLI off those two files. So no test here may create, change, delete or assert on them.
 * Ordinary tests also load the skeleton .env file when it exists: they inherit
 * $loadEnvironmentVariables = true from Testbench's TestCase. Without that file, they load
 * Testbench's .env.testbench stub. When the skeleton .env file is missing, Testbench's CLI copies
 * one there for the run (in this checkout, the skeleton's own .env.example, because workbench/
 * has no .env file), and deletes it at the end of the run. A run that constructs
 * `package:sync-skeleton`, for example `workbench:build`, keeps the copy, because
 * SyncSkeletonCommand::configure() drops the end-of-run callbacks. So ordinary tests load that
 * copy while a CLI run is active, and after such a run until something removes it.
 *
 * The launcher also makes the skeleton's vendor link before Testbench starts (WorkbenchSkeleton).
 * Without it, every CLI run made and removed the link, and deleted the default package manifest
 * that ordinary tests use.
 *
 * Every test creates and removes two directories: one inside workbench/database (so it never
 * touches the developer's database.sqlite) and one outside. Filesystem assertions look at those
 * two directories, and WorkbenchBootstrapCacheTest also at the owned bootstrap cache directory.
 */
abstract class WorkbenchCliTestCase extends TestCase
{
    private const LOCK_TIMEOUT_SECONDS = 600;

    /**
     * The longest time one CLI child may run. The polling loop enforces it with checkTimeout(),
     * because Symfony's isRunning() never checks the timeout. It is a bound for a hung child, not
     * an expected duration: a launcher command takes about 0.5 s on a quiet machine, but under a
     * load average of 20 to 57 from other agents' runs (2026-11-07) a WorkbenchBootstrapCacheTest
     * run ended with a ProcessTimedOutException at the old limit of 180 s.
     */
    protected const CHILD_TIMEOUT_SECONDS = 600.0;

    /**
     * @var list<Process>
     */
    private array $children = [];

    protected string $repository;

    /**
     * A test-owned directory inside workbench/database.
     */
    protected string $ownedDirectory;

    /**
     * A test-owned directory outside the checkout.
     */
    protected string $outsideDirectory;

    /**
     * @var resource|null
     */
    private $lock = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = (string) realpath(__DIR__.'/../..');
        $this->lock = self::acquireLock(self::lockPath());

        $suffix = bin2hex(random_bytes(6));

        $this->ownedDirectory = WorkbenchDatabase::directory().DIRECTORY_SEPARATOR.'.workbench-test-'.$suffix;
        mkdir($this->ownedDirectory, 0700);

        $this->outsideDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-workbench-outside-'.$suffix;
        mkdir($this->outsideDirectory, 0700);
    }

    protected function tearDown(): void
    {
        try {
            $this->releaseOwnedResources();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Stop and reap every child that still runs, then remove the owned directories, then release
     * the lock, in that order. It is idempotent: tearDown() calls it, and a test can call it first.
     *
     * A child gets SIGTERM and 10 seconds before SIGKILL, so a `serve` child can stop its own
     * web server child first.
     */
    protected function releaseOwnedResources(): void
    {
        try {
            foreach ($this->children as $child) {
                if ($child->isRunning()) {
                    $child->stop(10);
                }
            }

            $this->children = [];

            if (isset($this->ownedDirectory)) {
                self::removeTree($this->ownedDirectory);
            }

            if (isset($this->outsideDirectory)) {
                self::removeTree($this->outsideDirectory);
            }
        } finally {
            if (is_resource($this->lock)) {
                flock($this->lock, LOCK_UN);
                fclose($this->lock);
            }

            $this->lock = null;
        }
    }

    /**
     * The checkout-wide lock file: <repo>/workbench/database/.workbench-tests.lock.
     */
    protected static function lockPath(): string
    {
        return WorkbenchDatabase::directory().DIRECTORY_SEPARATOR.'.workbench-tests.lock';
    }

    /**
     * Run the Workbench CLI through its supported launcher: `workbench/bin/testbench <arguments>`.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment  Values for the child; false removes a variable.
     */
    protected function testbench(array $arguments, array $environment, ?callable $whileRunning = null): Process
    {
        return $this->runPhp(array_merge([$this->repository.'/workbench/bin/testbench'], $arguments, ['--no-ansi', '--no-interaction']), $environment, $whileRunning);
    }

    /**
     * Run Testbench's own CLI directly, without the launcher: `vendor/bin/testbench <arguments>`.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    protected function directTestbench(array $arguments, array $environment): Process
    {
        return $this->runPhp(array_merge([$this->repository.'/vendor/bin/testbench'], $arguments, ['--no-ansi', '--no-interaction']), $environment);
    }

    /**
     * Run workbench/check.php in the checkout.
     *
     * @param  array<string, string|false>  $environment
     */
    protected function check(array $environment): Process
    {
        return $this->runPhp([$this->repository.'/workbench/check.php'], $environment);
    }

    /**
     * Run PHP with the arguments in the checkout, and wait for it.
     *
     * The loop enforces the timeout: checkTimeout() stops the child and throws
     * ProcessTimedOutException. If anything else throws while the child runs (for example the
     * $whileRunning callback), the finally block stops and reaps the child before the exception
     * leaves, so tearDown() never removes files or releases the lock under a running child.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    protected function runPhp(array $arguments, array $environment, ?callable $whileRunning = null, float $timeout = self::CHILD_TIMEOUT_SECONDS): Process
    {
        $process = $this->startPhp($arguments, $environment, $timeout);

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();

                if ($whileRunning !== null) {
                    $whileRunning();
                }

                usleep(20_000);
            }

            if ($whileRunning !== null) {
                $whileRunning();
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return $process;
    }

    /**
     * Start PHP with the arguments in the checkout, and return at once. releaseOwnedResources()
     * stops the child if it still runs, so a test that starts a server cannot leave it behind.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    protected function startPhp(array $arguments, array $environment, float $timeout = self::CHILD_TIMEOUT_SECONDS): Process
    {
        $process = new Process(
            array_merge([PHP_BINARY], $arguments),
            $this->repository,
            array_merge([
                'TESTBENCH_WORKING_PATH' => $this->repository,
                'XDEBUG_MODE' => 'off',
                // Plain output. The terminal width stays at its default: a wide COLUMNS value makes
                // every padded queue:work line of the seeder long, and a build takes 4 s instead of 0.6 s.
                'NO_COLOR' => '1',
                // The default authorization mode, whatever the caller's environment says. A test
                // of a Workbench switch passes its variable itself.
                'WORKBENCH_AUTH' => false,
                // WAL, whatever the caller's environment says (the rollback-mode switch).
                'ORRERY_TEST_SQLITE_JOURNAL' => false,
            ], $environment),
            null,
            $timeout,
        );

        $this->children[] = $process;
        $process->start();

        return $process;
    }

    /**
     * The JSON report of `workbench:ownership`.
     *
     * @return array<string, mixed>
     */
    protected static function probeReport(Process $probe): array
    {
        foreach (preg_split('/\R/', $probe->getOutput()) ?: [] as $line) {
            if (str_starts_with($line, OwnershipProbeCommand::REPORT_PREFIX)) {
                return (array) json_decode(substr($line, strlen(OwnershipProbeCommand::REPORT_PREFIX)), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::fail("workbench:ownership printed no report:\n".$probe->getOutput().$probe->getErrorOutput());
    }

    protected static function plainOutput(Process $process): string
    {
        $raw = $process->getOutput().$process->getErrorOutput();

        return (string) preg_replace('/\s+/', ' ', (string) preg_replace('/\e\[[0-9;]*m/', '', $raw));
    }

    /**
     * Assert that the output contains the text, ignoring all whitespace. The console wraps an
     * error message at the terminal width, also in the middle of a word.
     */
    protected function assertOutputContains(string $text, Process $process): void
    {
        $this->assertStringContainsString(
            (string) preg_replace('/\s+/', '', $text),
            (string) preg_replace('/\s+/', '', self::plainOutput($process)),
            'The output does not contain ['.$text."]:\n".$process->getOutput().$process->getErrorOutput(),
        );
    }

    protected function assertSucceeded(Process $process, string $what): void
    {
        $this->assertSame(0, $process->getExitCode(), $what." failed:\n".$process->getOutput().$process->getErrorOutput());
    }

    protected function assertRefused(Process $build, string $reason): void
    {
        $this->assertNotSame(0, $build->getExitCode(), "The build did not fail:\n".$build->getOutput().$build->getErrorOutput());
        $this->assertOutputContains('Refusing the Workbench SQLite path', $build);
        $this->assertOutputContains($reason, $build);
        $this->assertStringNotContainsString('Seeding database', self::plainOutput($build));
    }

    /**
     * Create a real SQLite file with one sentinel row and the given user_version.
     */
    protected static function createSentinelDatabase(string $path, int $userVersion = 0): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE sentinel (value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO sentinel (value) VALUES ('untouched')");
        $pdo->exec('PRAGMA user_version = '.$userVersion);
    }

    protected function assertSentinelDatabaseUntouched(string $path, string $hashBefore): void
    {
        $this->assertSame($hashBefore, sha1_file($path), 'The build changed the outside database ['.$path.'].');

        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(['sentinel'], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame('untouched', $pdo->query('SELECT value FROM sentinel')->fetchColumn());
    }

    protected static function userVersion(string $path): int
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }

    /**
     * Every entry under the directory, recursively: relative path => 'dir', 'link -> target', or
     * the sha1 of the file content.
     *
     * @return array<string, string>
     */
    protected static function tree(string $directory): array
    {
        $entries = [];
        $walk = static function (string $path, string $relative) use (&$walk, &$entries): void {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
                $full = $path.DIRECTORY_SEPARATOR.$name;
                $key = ltrim($relative.'/'.$name, '/');

                if (is_link($full)) {
                    $entries[$key] = 'link -> '.readlink($full);
                } elseif (is_dir($full)) {
                    $entries[$key] = 'dir';
                    $walk($full, $key);
                } else {
                    $entries[$key] = (string) sha1_file($full);
                }
            }
        };

        $walk($directory, '');
        ksort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected static function listing(string $directory): array
    {
        $names = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    /**
     * @return resource
     */
    private static function acquireLock(string $path)
    {
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open the Workbench test lock [%s].', $path));
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) > $deadline) {
                fclose($handle);

                throw new RuntimeException(sprintf('Another suite held the Workbench test lock [%s] for %d seconds.', $path, self::LOCK_TIMEOUT_SECONDS));
            }

            usleep(50_000);
        }

        return $handle;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            self::removeTree($path.DIRECTORY_SEPARATOR.$name);
        }

        rmdir($path);
    }
}
