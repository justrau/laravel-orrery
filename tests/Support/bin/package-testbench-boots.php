<?php

/*
 * Boots the ordinary package test application (tests/TestCase.php) again and again, like the fast
 * suite does, until a stop file exists. WorkbenchBootstrapCacheTest runs it beside the Workbench
 * CLI as a regression control for the interference that a review found. It does
 * not prove that the two share no file: a valid manifest that another process writes atomically
 * passes it.
 *
 * Usage: php package-testbench-boots.php <status file> <stop file> <time limit in seconds>
 *
 * Each boot runs Testbench's own createApplication(), which reads and, when needed, writes the
 * package and services manifests at the Testbench skeleton defaults. After each boot, the script
 * requires the package provider to be loaded, and both manifest files to exist and to include
 * cleanly. So a manifest that another process deletes or writes partly fails the loop.
 * The status file holds the number of completed boots and is replaced atomically after each one.
 * The script exits 0 when it sees the stop file, and 1 on the first failure or at the time limit.
 */

declare(strict_types=1);

use Illuminate\Foundation\PackageManifest;
use Justrau\Orrery\OrreryServiceProvider;
use Justrau\Orrery\Tests\TestCase;

require __DIR__.'/../../../vendor/autoload.php';

[, $statusFile, $stopFile, $timeLimit] = $argv + [null, null, null, null];

if (! is_string($statusFile) || ! is_string($stopFile) || ! is_numeric($timeLimit)) {
    fwrite(STDERR, "Usage: php package-testbench-boots.php <status file> <stop file> <time limit in seconds>\n");
    exit(2);
}

$case = new class('packageTestbenchBoot') extends TestCase {};
$deadline = microtime(true) + (float) $timeLimit;
$boots = 0;

$writeStatus = static function (int $boots) use ($statusFile): void {
    $temporary = $statusFile.'.tmp';
    file_put_contents($temporary, (string) $boots);
    rename($temporary, $statusFile);
};

$writeStatus(0);

try {
    while (! is_file($stopFile)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException(sprintf('No stop file after %s seconds.', $timeLimit));
        }

        $app = $case->createApplication();

        $packages = $app->make(PackageManifest::class)->manifestPath;
        $services = $app->getCachedServicesPath();

        foreach ([$packages, $services] as $manifest) {
            if (! is_file($manifest) || ! is_array(require $manifest)) {
                throw new RuntimeException(sprintf('The manifest [%s] is missing or invalid after a boot.', $manifest));
            }
        }

        if (! $app->providerIsLoaded(OrreryServiceProvider::class)) {
            throw new RuntimeException('The package provider did not load.');
        }

        $app->flush();
        $writeStatus(++$boots);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("Boot %d failed: %s: %s\n", $boots + 1, get_class($exception), $exception->getMessage()));
    exit(1);
}

fwrite(STDOUT, sprintf("Completed %d package Testbench boots.\n", $boots));
exit(0);
