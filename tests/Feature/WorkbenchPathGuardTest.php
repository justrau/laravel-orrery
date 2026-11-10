<?php

namespace Justrau\Orrery\Tests\Feature;

use Justrau\Orrery\Tests\Support\WorkbenchCliTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * The Workbench path guard: the Workbench never deletes, creates, migrates or seeds a SQLite
 * file outside workbench/database.
 *
 * The CLI tests run the real `workbench:build` through the Workbench launcher with a hostile
 * DB_DATABASE and then check the test-owned files. A guard that fails open lets the build's drop,
 * create-sqlite-db and migrate-fresh steps delete, create or rewrite the outside file, and these
 * tests see it.
 * Each containment branch has its own case:
 *  - a final symbolic link (to an existing file, and dangling);
 *  - an existing regular file outside the owned directory, directly and through an intermediate
 *    directory symlink (existing-file containment);
 *  - a missing file whose parent is outside, directly and through an intermediate directory
 *    symlink (parent containment).
 * WorkbenchDatabaseTest (fast, not in the workbench group) tests the other rules of
 * WorkbenchDatabase::resolve() directly. WorkbenchBuildTest has the accepted in-root control.
 */
#[Group('workbench')]
final class WorkbenchPathGuardTest extends WorkbenchCliTestCase
{
    public function test_the_build_refuses_a_missing_database_whose_parent_directory_is_outside(): void
    {
        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'database.sqlite';

        $build = $this->build($target);

        $this->assertFileDoesNotExist($target, 'The build created its database outside the Workbench.');
        $this->assertSame([], self::listing($this->outsideDirectory), 'The build created a file outside the Workbench.');
        $this->assertSame([], self::listing($this->ownedDirectory));
        $this->assertRefused($build, 'the parent directory resolves to');
    }

    public function test_the_build_refuses_an_existing_regular_database_outside_the_workbench(): void
    {
        $outsideFile = $this->outsideDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        self::createSentinelDatabase($outsideFile);
        $hash = (string) sha1_file($outsideFile);

        $build = $this->build($outsideFile);

        $this->assertSentinelDatabaseUntouched($outsideFile, $hash);
        $this->assertSame(['database.sqlite'], self::listing($this->outsideDirectory));
        $this->assertRefused($build, 'which is outside the owned directory');
    }

    public function test_the_build_refuses_an_intermediate_directory_link_to_an_outside_database(): void
    {
        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'target';
        mkdir($target);
        $outsideFile = $target.DIRECTORY_SEPARATOR.'database.sqlite';
        self::createSentinelDatabase($outsideFile);
        $hash = (string) sha1_file($outsideFile);

        $this->assertTrue(symlink($target, $this->ownedDirectory.DIRECTORY_SEPARATOR.'linked'));

        $build = $this->build($this->ownedDirectory.DIRECTORY_SEPARATOR.'linked'.DIRECTORY_SEPARATOR.'database.sqlite');

        $this->assertSentinelDatabaseUntouched($outsideFile, $hash);
        $this->assertSame(['database.sqlite'], self::listing($target));
        $this->assertSame(['linked'], self::listing($this->ownedDirectory));
        $this->assertRefused($build, 'the file resolves to');
    }

    public function test_the_build_refuses_an_intermediate_directory_link_to_an_outside_directory_without_a_database(): void
    {
        $target = $this->outsideDirectory.DIRECTORY_SEPARATOR.'target';
        mkdir($target);

        $this->assertTrue(symlink($target, $this->ownedDirectory.DIRECTORY_SEPARATOR.'linked'));

        $build = $this->build($this->ownedDirectory.DIRECTORY_SEPARATOR.'linked'.DIRECTORY_SEPARATOR.'database.sqlite');

        $this->assertSame([], self::listing($target), 'The build created its database in the outside directory.');
        $this->assertSame(['linked'], self::listing($this->ownedDirectory));
        $this->assertRefused($build, 'the parent directory resolves to');
    }

    public function test_the_build_refuses_a_symlink_inside_the_workbench_that_points_to_an_outside_database(): void
    {
        $outsideFile = $this->outsideDirectory.DIRECTORY_SEPARATOR.'outside.sqlite';
        self::createSentinelDatabase($outsideFile);
        $hash = (string) sha1_file($outsideFile);

        $link = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->assertTrue(symlink($outsideFile, $link));

        $build = $this->build($link);

        $this->assertSentinelDatabaseUntouched($outsideFile, $hash);
        $this->assertSame(['outside.sqlite'], self::listing($this->outsideDirectory), 'The build created a file outside the Workbench.');
        $this->assertSame(['database.sqlite'], self::listing($this->ownedDirectory));
        $this->assertTrue(is_link($link));
        $this->assertRefused($build, 'the path is a symbolic link');
    }

    public function test_the_build_refuses_a_dangling_symlink_inside_the_workbench(): void
    {
        // file_exists() reports a dangling link as absent. A guard that tests existence that way
        // treats the link as a new file, and `create-sqlite-db` then creates the link's target.
        $missingTarget = $this->outsideDirectory.DIRECTORY_SEPARATOR.'missing.sqlite';
        $link = $this->ownedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        $this->assertTrue(symlink($missingTarget, $link));
        $this->assertFalse(file_exists($link));

        $build = $this->build($link);

        $this->assertFalse(file_exists($missingTarget), 'The build created the target of a dangling link outside the Workbench.');
        $this->assertSame([], self::listing($this->outsideDirectory), 'The build created a file outside the Workbench.');
        $this->assertSame(['database.sqlite'], self::listing($this->ownedDirectory));
        $this->assertRefused($build, 'the path is a symbolic link');
    }

    private function build(string $databasePath): Process
    {
        return $this->testbench(['workbench:build'], ['DB_DATABASE' => $databasePath]);
    }
}
