<?php

namespace Justrau\Orrery\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workbench\App\Support\WorkbenchDatabase;

/**
 * The pure rules of the Workbench path guard, without the Workbench CLI.
 *
 * These tests are fast, so they stay in `composer test`, outside the `workbench` group. They run
 * no CLI and hold no lock. Each test creates its own uniquely named directory inside
 * workbench/database and one outside, and looks only at those. WorkbenchPathGuardTest proves
 * through the real build that a refusal stops every build step.
 */
final class WorkbenchDatabaseTest extends TestCase
{
    private string $ownedDirectory;

    private string $outsideDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));

        $this->ownedDirectory = WorkbenchDatabase::directory().DIRECTORY_SEPARATOR.'.workbench-test-'.$suffix;
        mkdir($this->ownedDirectory, 0700);

        $this->outsideDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-workbench-outside-'.$suffix;
        mkdir($this->outsideDirectory, 0700);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->ownedDirectory);
        self::removeTree($this->outsideDirectory);

        parent::tearDown();
    }

    public function test_an_unset_or_empty_value_selects_the_default_file(): void
    {
        $default = realpath(WorkbenchDatabase::directory()).DIRECTORY_SEPARATOR.'database.sqlite';

        $this->assertSame($default, WorkbenchDatabase::resolve(null));
        $this->assertSame($default, WorkbenchDatabase::resolve(''));
    }

    public function test_a_database_file_inside_the_workbench_is_accepted(): void
    {
        $file = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';

        $this->assertSame(realpath($this->ownedDirectory).DIRECTORY_SEPARATOR.'database.sqlite', WorkbenchDatabase::resolve($file));
        $this->assertFileDoesNotExist($file, 'resolve() must not create the file.');

        touch($file);

        $this->assertSame(realpath($file), WorkbenchDatabase::resolve($file));
    }

    public function test_a_relative_path_is_refused(): void
    {
        $this->expectRefusal('the path is not absolute');

        WorkbenchDatabase::resolve('workbench/database/database.sqlite');
    }

    public function test_another_file_name_is_refused(): void
    {
        $this->expectRefusal('the file name is not database.sqlite');

        WorkbenchDatabase::resolve($this->ownedDirectory.DIRECTORY_SEPARATOR.'other.sqlite');
    }

    public function test_a_directory_at_the_database_path_is_refused(): void
    {
        mkdir($this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite');

        $this->expectRefusal('the path exists but is not a regular file');

        WorkbenchDatabase::resolve($this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite');
    }

    public function test_a_path_that_climbs_out_of_the_workbench_directory_is_refused(): void
    {
        $this->expectRefusal('the parent directory resolves to');

        WorkbenchDatabase::resolve(WorkbenchDatabase::directory().DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'database.sqlite');
    }

    public function test_a_missing_parent_directory_is_refused(): void
    {
        $this->expectRefusal('the parent directory does not exist');

        WorkbenchDatabase::resolve($this->ownedDirectory.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'database.sqlite');
    }

    public function test_an_owned_directory_that_is_a_symbolic_link_is_refused(): void
    {
        $linkedDirectory = $this->outsideDirectory.DIRECTORY_SEPARATOR.'linked-database-directory';
        $this->assertTrue(symlink($this->outsideDirectory, $linkedDirectory));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is a symbolic link. It must be a real directory');

        WorkbenchDatabase::resolve(null, $linkedDirectory);
    }

    private function expectRefusal(string $reason): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Refusing the Workbench SQLite path \[.*\]: '.preg_quote($reason, '/').'/');
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
