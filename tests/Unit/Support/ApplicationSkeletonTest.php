<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Justrau\Orrery\Tests\Support\ApplicationSkeleton;
use PHPUnit\Framework\TestCase;

class ApplicationSkeletonTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/orrery-skeleton-test-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/source/bootstrap', 0700, true);
        mkdir($this->root.'/target-of-link', 0700, true);

        file_put_contents($this->root.'/source/bootstrap/providers.php', "<?php\n\nreturn [];\n");
        file_put_contents($this->root.'/target-of-link/keep.txt', 'keep');
        symlink($this->root.'/target-of-link', $this->root.'/source/vendor');
    }

    protected function tearDown(): void
    {
        foreach (['source/vendor'] as $link) {
            if (is_link($this->root.'/'.$link)) {
                unlink($this->root.'/'.$link);
            }
        }

        @unlink($this->root.'/source/bootstrap/providers.php');
        @unlink($this->root.'/target-of-link/keep.txt');
        @unlink($this->root.'/source/storage/view.php');
        @rmdir($this->root.'/source/storage');
        @rmdir($this->root.'/source/bootstrap');
        @rmdir($this->root.'/source');
        @rmdir($this->root.'/target-of-link');
        @rmdir($this->root);
    }

    /**
     * Rejects: a copy that shares its files with the source (a link to the skeleton), or that
     * follows the skeleton's vendor link and copies the whole vendor tree.
     */
    public function test_copy_is_private_and_keeps_links_as_links(): void
    {
        $copy = ApplicationSkeleton::copy($this->root.'/source');

        try {
            $this->assertNotSame(realpath($this->root.'/source'), realpath($copy));
            $this->assertFalse(is_link($copy));
            $this->assertFalse(is_link($copy.'/bootstrap/providers.php'));
            $this->assertSame("<?php\n\nreturn [];\n", file_get_contents($copy.'/bootstrap/providers.php'));

            $this->assertTrue(is_link($copy.'/vendor'));
            $this->assertSame($this->root.'/target-of-link', readlink($copy.'/vendor'));

            // A write into the copy leaves the source unchanged.
            file_put_contents($copy.'/bootstrap/providers.php', 'changed');
            $this->assertSame("<?php\n\nreturn [];\n", file_get_contents($this->root.'/source/bootstrap/providers.php'));
        } finally {
            ApplicationSkeleton::remove($copy);
        }
    }

    /**
     * Rejects: a copy that fails when a runtime file of the shared skeleton is deleted while it
     * copies, and a copy that keeps an empty file in place of the deleted one.
     */
    public function test_copy_skips_a_file_that_is_deleted_while_it_copies(): void
    {
        // The hook deletes the file after the directory scan and before its copy.
        mkdir($this->root.'/source/storage', 0700);
        file_put_contents($this->root.'/source/storage/view.php', 'compiled');

        $copy = ApplicationSkeleton::copy($this->root.'/source', static function (string $from): void {
            if (str_ends_with($from, '/storage/view.php')) {
                unlink($from);
            }
        });

        try {
            $this->assertDirectoryExists($copy.'/storage');
            $this->assertFileDoesNotExist($copy.'/storage/view.php');
            $this->assertFileExists($copy.'/bootstrap/providers.php');
        } finally {
            ApplicationSkeleton::remove($copy);
        }
    }

    /**
     * Rejects: a removal that deletes through the vendor link (the real vendor tree), or that
     * leaves the copy behind.
     */
    public function test_remove_deletes_the_copy_but_not_the_link_target(): void
    {
        $copy = ApplicationSkeleton::copy($this->root.'/source');

        ApplicationSkeleton::remove($copy);

        $this->assertFileDoesNotExist($copy);
        $this->assertFileExists($this->root.'/target-of-link/keep.txt');
        $this->assertFileExists($this->root.'/source/bootstrap/providers.php');
    }

    /**
     * Rejects: a fingerprint that ignores a changed file content, so the install test cannot see
     * a write into the shared skeleton.
     */
    public function test_fingerprint_changes_when_a_file_changes(): void
    {
        $before = ApplicationSkeleton::fingerprint($this->root.'/source');

        file_put_contents($this->root.'/source/bootstrap/providers.php', "<?php\n\nreturn [1];\n");

        $this->assertNotSame($before, ApplicationSkeleton::fingerprint($this->root.'/source'));
    }
}
