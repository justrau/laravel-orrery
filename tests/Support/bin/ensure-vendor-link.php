<?php

/*
 * One launcher's vendor link step, for WorkbenchSkeletonConcurrencyTest.
 *
 * Usage: php ensure-vendor-link.php <skeleton> <vendor> <barrier directory> <name> <hold|run>
 *
 * It calls WorkbenchSkeleton::ensureVendorLink() with barrier files in <barrier directory>:
 *  - when another process holds the link lock, it arrives at <name>-lock-wait.reached;
 *  - with "hold", when ensureVendorLink() has decided to remove or make the link, it arrives at
 *    <name>-decided.reached and waits for <name>-decided.release (at most 30 seconds, then exit 3).
 * When ensureVendorLink() returns, it writes the inode of <skeleton>/vendor, or "missing" when the
 * path is not a symbolic link, to <name>.done (atomically) and exits 0. On an exception it prints
 * the message and exits 1.
 */

declare(strict_types=1);

use Workbench\App\Support\WorkbenchSkeleton;

require __DIR__.'/../../../vendor/autoload.php';

[, $skeleton, $vendor, $barrier, $name, $mode] = $argv + [null, null, null, null, null, null];

if (! is_string($skeleton) || ! is_string($vendor) || ! is_string($barrier) || ! is_string($name) || ! in_array($mode, ['hold', 'run'], true)) {
    fwrite(STDERR, "Usage: php ensure-vendor-link.php <skeleton> <vendor> <barrier directory> <name> <hold|run>\n");
    exit(2);
}

$hook = static function (string $point) use ($barrier, $name, $mode): void {
    if ($point === 'lock-wait') {
        $reached = $barrier.DIRECTORY_SEPARATOR.$name.'-lock-wait.reached';

        if (! is_file($reached)) {
            touch($reached);
        }

        return;
    }

    if ($point === 'decided' && $mode === 'hold') {
        touch($barrier.DIRECTORY_SEPARATOR.$name.'-decided.reached');
        $release = $barrier.DIRECTORY_SEPARATOR.$name.'-decided.release';
        $deadline = microtime(true) + 30;

        while (! is_file($release)) {
            if (microtime(true) > $deadline) {
                fwrite(STDERR, sprintf("No %s within 30 seconds.\n", $release));
                exit(3);
            }

            usleep(10_000);
            clearstatcache(true, $release);
        }
    }
};

try {
    WorkbenchSkeleton::ensureVendorLink($skeleton, $vendor, $hook);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}

$link = $skeleton.DIRECTORY_SEPARATOR.'vendor';
clearstatcache(true, $link);
$stat = is_link($link) ? lstat($link) : false;
$done = $barrier.DIRECTORY_SEPARATOR.$name.'.done';
file_put_contents($done.'.tmp', $stat === false ? 'missing' : (string) $stat['ino']);
rename($done.'.tmp', $done);

exit(0);
