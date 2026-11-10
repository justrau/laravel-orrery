<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The Workbench build: an accepted in-root path builds and passes the check, and every build
 * starts from no database and no cache entry, also under an inherited APP_ENV=production.
 *
 * A build that skipped the rebuild (for example, a declined production confirmation of
 * migrate:fresh) must never pass on the counts of an earlier build. The test marks the earlier
 * file with PRAGMA user_version, which migrate:fresh does not reset. Only a new file has 0.
 */
#[Group('workbench')]
final class WorkbenchBuildTest extends WorkbenchCliTestCase
{
    private const EARLIER_BUILD_MARK = 4242;

    /**
     * Rejects: a Workbench that creates framework/cache but not its data directory, so in a
     * fresh checkout the build step cache:clear fails ("Failed to clear cache"): the file
     * store's flush() returns false when its directory does not exist.
     * Still passes: a data directory that exists but cannot be written.
     */
    public function test_a_fresh_storage_gets_the_cache_data_directory_and_cache_clear_succeeds(): void
    {
        $data = WorkbenchServiceProvider::storagePath().'/framework/cache/data';
        $this->removeTree($data);
        $this->assertDirectoryDoesNotExist($data);

        $clear = $this->testbench(['cache:clear'], ['DB_DATABASE' => $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite']);

        $this->assertSucceeded($clear, 'cache:clear in a fresh storage');
        $this->assertDirectoryExists($data);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry instanceof SplFileInfo && $entry->isDir() ? rmdir($entry->getPathname()) : unlink((string) $entry);
        }
        rmdir($path);
    }

    public function test_the_build_accepts_an_owned_path_and_rebuilds_it_from_no_database_under_production(): void
    {
        $database = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        $environment = ['DB_DATABASE' => $database];

        // The accepted in-root control.
        $build = $this->testbench(['workbench:build'], $environment);

        $this->assertSucceeded($build, 'The first build');
        $this->assertStringContainsString('Seeding database', self::plainOutput($build));
        $this->assertSucceeded($this->check($environment), 'The check after the first build');
        $this->assertFileExists($database);
        $this->assertSame([], self::listing($this->outsideDirectory));

        // An earlier, valid database. An inherited production environment would make
        // migrate:fresh ask for a confirmation that nobody answers.
        $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA user_version = '.self::EARLIER_BUILD_MARK);
        $pdo = null;
        $this->assertSame(self::EARLIER_BUILD_MARK, self::userVersion($database));

        // An earlier cache entry, for example Laravel's queue:pause flag or queue:restart time. The
        // file cache store keeps its files across builds, so the build must empty it, as the
        // earlier database store was emptied with its file.
        $cacheEntry = WorkbenchServiceProvider::storagePath().'/framework/cache/data/or/re/orrery-build-test-'.bin2hex(random_bytes(4));
        @mkdir(dirname($cacheEntry), 0775, true);
        file_put_contents($cacheEntry, 'stale');

        $production = $environment + ['APP_ENV' => 'production'];
        $rebuild = $this->testbench(['workbench:build'], $production);
        $check = $this->check($production);

        $this->assertSucceeded($rebuild, 'The build under APP_ENV=production');
        $this->assertStringContainsString('Seeding database', self::plainOutput($rebuild));
        $this->assertSame(0, self::userVersion($database), 'The build kept the database file of the earlier build.');
        $this->assertSucceeded($check, 'The check after the build under APP_ENV=production');
        $this->assertFileDoesNotExist($cacheEntry, 'The build kept a cache entry of an earlier build.');
        $this->assertSame([], self::listing($this->outsideDirectory));
    }

    /**
     * migrate:fresh truncates a SQLite file, so while it runs no earlier data survives. The drop
     * step covers the case where migrate:fresh does not run: without it, the earlier file would
     * stay and pass the check. The Workbench build ignores step exit codes, so the order and the
     * options of the steps in testbench.yaml are the contract.
     */
    public function test_the_build_steps_start_from_no_database_and_never_prompt(): void
    {
        $config = Yaml::parseFile($this->repository.DIRECTORY_SEPARATOR.'testbench.yaml');

        $this->assertIsArray($config);
        $this->assertArrayNotHasKey('seeders', $config, 'A top-level seeders key seeds a second time.');
        $this->assertSame([
            'workbench-drop-database',
            'create-sqlite-db',
            'cache:clear',
            ['migrate-fresh' => [
                '--force' => true,
                '--seed' => true,
                '--seeder' => 'Workbench\Database\Seeders\DatabaseSeeder',
            ]],
        ], $config['workbench']['build'] ?? null);
    }

    public function test_drop_database_deletes_only_the_guarded_file_and_its_sidecars(): void
    {
        $database = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        self::createSentinelDatabase($database);

        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            file_put_contents($database.$suffix, 'stale');
        }

        file_put_contents($this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite.keep', 'keep');

        // A sidecar that is a symbolic link: the command removes the link, never its target.
        $outsideFile = $this->outsideDirectory.DIRECTORY_SEPARATOR.'outside.sqlite-wal';
        file_put_contents($outsideFile, 'outside');
        unlink($database.'-wal');
        $this->assertTrue(symlink($outsideFile, $database.'-wal'));

        $drop = $this->testbench(['workbench:drop-database'], ['DB_DATABASE' => $database]);

        $this->assertSucceeded($drop, 'workbench:drop-database');
        $this->assertSame(['database.sqlite.keep'], self::listing($this->ownedDirectory));
        $this->assertSame('outside', file_get_contents($outsideFile));
        $this->assertSame(['outside.sqlite-wal'], self::listing($this->outsideDirectory));
    }

    public function test_drop_database_refuses_a_sidecar_that_is_a_directory(): void
    {
        $database = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        self::createSentinelDatabase($database);
        mkdir($database.'-shm');

        $drop = $this->testbench(['workbench:drop-database'], ['DB_DATABASE' => $database]);

        $this->assertNotSame(0, $drop->getExitCode(), "workbench:drop-database did not fail:\n".$drop->getOutput().$drop->getErrorOutput());
        $this->assertOutputContains('it is not a regular file', $drop);
        $this->assertDirectoryExists($database.'-shm');
        $this->assertFileExists($database, 'The command deleted the database before it refused.');
    }
}
