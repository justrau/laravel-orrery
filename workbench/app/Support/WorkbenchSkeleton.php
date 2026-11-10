<?php

namespace Workbench\App\Support;

use RuntimeException;

/**
 * The vendor link of Testbench's skeleton (vendor/orchestra/testbench-core/laravel/vendor), which
 * workbench/bin/testbench creates before Testbench starts.
 *
 * Testbench's CLI needs that link: the skeleton application loads its autoloader and its package
 * list through it, and the web server of `serve` loads public/index.php, which requires
 * vendor/autoload.php. When the link is missing at the start of a run, Testbench creates it for
 * that run and removes it at the end. testbench-core 10.14.1 and 11.4.0 have the same code; the
 * line numbers are 10.14.1 / 11.4.0 where they differ:
 *  - src/Console/Commander.php:137-143 registers deleteVendorSymlink() for the end of the run
 *    when the skeleton vendor path is not a link.
 *  - src/Foundation/Actions/CreateVendorSymlink.php:39-48 creates the link when
 *    laravel_vendor_exists() (src/functions.php:380-389 / 360-369) is false, and then runs
 *    RefreshPackageDiscovery.
 *  - src/Foundation/Actions/RefreshPackageDiscovery.php:26-27 deletes
 *    $app->bootstrapPath('cache/packages.php'). That is the fixed skeleton default, not the
 *    APP_PACKAGES_CACHE path, so it deletes the package manifest that ordinary package tests
 *    read and write.
 *  - src/Foundation/Application.php:174-181 / 176-183 (deleteVendorSymlink()) creates a whole
 *    application to remove the link. That application writes both manifests again at the pinned
 *    owned paths, after `optimize:clear` or `clear-compiled` of the same run deleted them.
 * A run that constructs `package:sync-skeleton` (for example `workbench:build`) drops those
 * end-of-run callbacks (src/Foundation/Console/SyncSkeletonCommand.php:35), and so leaves the link
 * in place. In a checkout without such a run, every CLI run deleted the shared default package
 * manifest.
 *
 * With a symbolic link whose autoloader Testbench accepts, laravel_vendor_exists() is true, so
 * CreateVendorSymlink does nothing, and Commander.php:137-143 registers no end-of-run removal,
 * because the path is a link. Both conditions are needed. So the launcher creates the link first,
 * under a lock that other launchers share.
 *
 * This class has no Laravel dependency on purpose: the launcher uses it before Laravel loads.
 */
final class WorkbenchSkeleton
{
    /**
     * How long a launcher waits for another launcher's link lock.
     */
    public const LOCK_TIMEOUT_SECONDS = 60.0;

    /**
     * Make <skeleton>/vendor a symbolic link that Testbench accepts, unless one is already there.
     *
     * Testbench's laravel_vendor_exists() accepts <skeleton>/vendor when its autoload.php has the
     * MD5 hash of <vendor>/autoload.php. But Commander.php:137-143 also schedules the end-of-run
     * removal, with its whole application, whenever the path is not a symbolic link, whatever its
     * hash. So only a symbolic link with that hash counts as done:
     *  - such a link is kept;
     *  - a link without it (dangling, or to another vendor directory) is replaced, as Testbench's
     *    DeleteVendorSymlink does;
     *  - anything else at the path (a directory, also one with the same autoload.php, or a file)
     *    is refused and kept, because the launcher never deletes it.
     *
     * One exclusive lock per skeleton (<skeleton>.workbench-vendor-link.lock, next to the skeleton
     * directory) covers the whole check, the replacement and the final check, and the path is read
     * again inside the lock. Without it, a launcher that decided to replace a missing or stale link
     * could remove the link that another launcher made meanwhile and already uses.
     *
     * $hook exists for WorkbenchSkeletonConcurrencyTest only; the launcher passes none. It is
     * called with 'lock-wait' after each failed attempt to take the lock, and with 'decided' after
     * the check inside the lock, just before the launcher removes or makes the link.
     *
     * @param  (callable(string): void)|null  $hook
     *
     * @throws RuntimeException When the path holds something else, the lock cannot be taken, or
     *                          the link cannot be made.
     */
    public static function ensureVendorLink(string $skeleton, string $vendor, ?callable $hook = null): void
    {
        $link = $skeleton.DIRECTORY_SEPARATOR.'vendor';
        $lock = self::acquireLock($skeleton.'.workbench-vendor-link.lock', $hook);

        try {
            clearstatcache(true, $link);

            if (is_link($link)) {
                if (self::vendorAccepted($link, $vendor)) {
                    return;
                }

                if ($hook !== null) {
                    $hook('decided');
                }

                unlink($link);
            } elseif (file_exists($link)) {
                throw new RuntimeException(sprintf(
                    'The Testbench skeleton vendor path [%s] exists, but it is not a link to [%s]. Remove it, then start the Workbench CLI again.',
                    $link,
                    $vendor,
                ));
            } elseif ($hook !== null) {
                $hook('decided');
            }

            if (! @symlink($vendor, $link)) {
                throw new RuntimeException(sprintf('Cannot link the Testbench skeleton vendor path [%s] to [%s].', $link, $vendor));
            }

            clearstatcache(true, $link);

            if (! is_link($link) || ! self::vendorAccepted($link, $vendor)) {
                throw new RuntimeException(sprintf('The Testbench skeleton vendor path [%s] does not lead to [%s].', $link, $vendor));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Testbench's laravel_vendor_exists(): the autoloader under $link is a file with the hash of
     * the checkout's autoloader. It does not check that $link is a symbolic link. The result reads
     * the file system, so it changes after a symlink().
     *
     * @phpstan-impure
     */
    public static function vendorAccepted(string $link, string $vendor): bool
    {
        clearstatcache(true, $link);
        $autoload = $link.DIRECTORY_SEPARATOR.'autoload.php';
        $expected = $vendor.DIRECTORY_SEPARATOR.'autoload.php';

        return is_file($autoload) && is_file($expected) && hash_file('md5', $autoload) === hash_file('md5', $expected);
    }

    /**
     * @param  (callable(string): void)|null  $hook
     * @return resource
     */
    private static function acquireLock(string $path, ?callable $hook)
    {
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Cannot open the Workbench vendor link lock [%s].', $path));
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if ($hook !== null) {
                $hook('lock-wait');
            }

            if (microtime(true) > $deadline) {
                fclose($handle);

                throw new RuntimeException(sprintf('Another Workbench launcher held the vendor link lock [%s] for %d seconds.', $path, self::LOCK_TIMEOUT_SECONDS));
            }

            usleep(20_000);
        }

        return $handle;
    }
}
