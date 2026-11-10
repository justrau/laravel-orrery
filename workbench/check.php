<?php

/*
 * Postcondition check of the Workbench database. `composer build` runs it after
 * `testbench workbench:build`, because the Workbench build ignores the exit codes of its steps.
 *
 * It resolves the database path with the same guard as WorkbenchServiceProvider, then checks
 * that the file exists and that every count in workbench/fixtures.md holds. It opens the file
 * with PRAGMA query_only, so it never writes. It exits with 1 on the first problem it cannot
 * recover from, or after it lists every count that does not hold.
 */

declare(strict_types=1);

use Workbench\App\Support\FixtureManifest;
use Workbench\App\Support\WorkbenchDatabase;

require __DIR__.'/../vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, 'Workbench check failed: '.$message.PHP_EOL);

    exit(1);
};

try {
    $database = WorkbenchDatabase::resolve(WorkbenchDatabase::configured());

    if (! is_file($database)) {
        $fail(sprintf('the database file [%s] does not exist. Run `composer build`.', $database));
    }

    $manifest = FixtureManifest::load();

    // The package tables live in the sibling mirror file. Attached as
    // `orrery`, its tables answer the unqualified names that main does not have.
    $sibling = WorkbenchDatabase::sibling($database);

    if (! is_file($sibling)) {
        $fail(sprintf('the Orrery sibling file [%s] does not exist. Run `composer build`.', $sibling));
    }

    $pdo = new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('ATTACH DATABASE '.$pdo->quote($sibling).' AS orrery');
    $pdo->exec('PRAGMA query_only = ON');

    $failures = $manifest->verify($pdo);
} catch (Throwable $exception) {
    $fail($exception->getMessage());
}

if ($failures !== []) {
    $fail(sprintf(
        '%d of %d counts in workbench/fixtures.md do not hold for [%s]:%s - %s',
        count($failures),
        count($manifest->checks),
        $database,
        PHP_EOL,
        implode(PHP_EOL.' - ', $failures),
    ));
}

fwrite(STDOUT, sprintf('Workbench check passed: %d counts hold for [%s].', count($manifest->checks), $database).PHP_EOL);

exit(0);
