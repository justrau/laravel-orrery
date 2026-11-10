<?php

namespace Justrau\Orrery\Tests\Feature;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Workbench\App\Support\WorkbenchSkeleton;

/**
 * The launcher's skeleton vendor link, in test-owned directories only.
 *
 * WorkbenchSkeleton::ensureVendorLink() must leave a symbolic link whose autoloader Testbench
 * accepts (laravel_vendor_exists(): the same autoload.php hash), so that Testbench never creates
 * or removes the link itself, and never runs RefreshPackageDiscovery. These cases use a temporary
 * skeleton and two temporary vendor directories with different autoload.php files, so they touch
 * no shared file and stay outside the `workbench` group. They run one launcher at a time.
 * WorkbenchSkeletonConcurrencyTest runs two, and WorkbenchBootstrapCacheTest checks the real link
 * across the launcher sequence.
 */
final class WorkbenchSkeletonTest extends TestCase
{
    private string $directory;

    private string $skeleton;

    private string $vendor;

    private string $otherVendor;

    private string $link;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orrery-skeleton-'.bin2hex(random_bytes(6));
        $this->skeleton = $this->directory.DIRECTORY_SEPARATOR.'skeleton';
        $this->vendor = $this->directory.DIRECTORY_SEPARATOR.'vendor';
        $this->otherVendor = $this->directory.DIRECTORY_SEPARATOR.'other-vendor';
        $this->link = $this->skeleton.DIRECTORY_SEPARATOR.'vendor';

        foreach ([$this->skeleton, $this->vendor, $this->otherVendor] as $directory) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->vendor.'/autoload.php', "<?php // the checkout's autoloader\n");
        file_put_contents($this->otherVendor.'/autoload.php', "<?php // another autoloader\n");
    }

    protected function tearDown(): void
    {
        self::removeTree($this->directory);

        parent::tearDown();
    }

    public function test_a_missing_link_is_created_to_the_vendor_directory(): void
    {
        WorkbenchSkeleton::ensureVendorLink($this->skeleton, $this->vendor);

        $this->assertTrue(is_link($this->link));
        $this->assertSame($this->vendor, readlink($this->link));
        $this->assertTrue(WorkbenchSkeleton::vendorAccepted($this->link, $this->vendor));

        // The lock file is next to the skeleton, and the launcher released it.
        $lock = fopen($this->skeleton.'.workbench-vendor-link.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'The launcher did not release the vendor link lock.');
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function test_an_accepted_link_is_left_as_it_is(): void
    {
        symlink($this->vendor, $this->link);
        $before = lstat($this->link);

        WorkbenchSkeleton::ensureVendorLink($this->skeleton, $this->vendor);

        clearstatcache(true, $this->link);
        $this->assertSame($before['ino'], lstat($this->link)['ino'], 'The launcher replaced a link that Testbench accepts.');
    }

    public function test_a_link_to_another_vendor_directory_or_nowhere_is_replaced(): void
    {
        symlink($this->otherVendor, $this->link);
        WorkbenchSkeleton::ensureVendorLink($this->skeleton, $this->vendor);
        $this->assertSame($this->vendor, readlink($this->link));

        unlink($this->link);
        symlink($this->directory.'/missing', $this->link);
        WorkbenchSkeleton::ensureVendorLink($this->skeleton, $this->vendor);
        $this->assertSame($this->vendor, readlink($this->link));
        $this->assertFileExists($this->otherVendor.'/autoload.php', 'The launcher changed the other vendor directory.');
    }

    public function test_a_directory_or_file_at_the_link_path_is_refused_and_kept(): void
    {
        mkdir($this->link);
        file_put_contents($this->link.'/marker', 'kept');
        $this->assertRefusedAndKept('marker');

        self::removeTree($this->link);
        file_put_contents($this->link, 'kept');
        $this->assertRefusedAndKept(null);
    }

    public function test_a_directory_with_the_same_autoloader_is_refused_and_kept(): void
    {
        // Testbench's laravel_vendor_exists() accepts this directory, but its Commander still
        // schedules the end-of-run removal for every path that is not a link (Commander.php:137).
        mkdir($this->link);
        copy($this->vendor.'/autoload.php', $this->link.'/autoload.php');
        file_put_contents($this->link.'/marker', 'kept');
        $before = self::tree($this->link);

        $this->assertRefusedAndKept('marker');
        $this->assertSame($before, self::tree($this->link), 'The launcher changed the refused directory.');
    }

    private function assertRefusedAndKept(?string $marker): void
    {
        try {
            WorkbenchSkeleton::ensureVendorLink($this->skeleton, $this->vendor);
            $this->fail('The launcher accepted a vendor path that is not a link.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exists, but it is not a link to ['.$this->vendor.']', $exception->getMessage());
        }

        $this->assertFalse(is_link($this->link));

        if ($marker === null) {
            $this->assertSame('kept', file_get_contents($this->link));
        } else {
            $this->assertSame('kept', file_get_contents($this->link.'/'.$marker));
        }
    }

    /**
     * @return array<string, string> File name => sha1 of the content.
     */
    private static function tree(string $directory): array
    {
        $tree = [];

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $tree[$name] = (string) sha1_file($directory.DIRECTORY_SEPARATOR.$name);
        }

        ksort($tree);

        return $tree;
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
