<?php

/*
 * PHPUnit bootstrap: the startup gate of bin/test, then the Composer autoloader,
 * then the harness configuration check and lease proof.
 *
 * bin/test starts every child that can touch a test database with
 * ORRERY_START_GATE=<path of a file that does not exist yet>. The supervisor
 * creates that file only after it has recorded the child in its lease. So the
 * child does nothing before it is registered:
 *
 * - It waits until the gate file exists, then it continues.
 * - After 30 seconds without the file, it exits with 79.
 * - If its parent process exits before the gate opens, it exits with 79 at once.
 *
 * ORRERY_START_GATE_TIMEOUT can only shorten the 30-second deadline (for the
 * tests of the gate itself). A process without ORRERY_START_GATE does not wait.
 * The variable is removed after the gate opens, so the processes that this one
 * starts do not inherit an open gate.
 *
 * Other entrypoints (for example a worker CLI) can require this file. It is safe
 * to require more than once.
 */

declare(strict_types=1);
use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\HarnessTiming;
use Justrau\Orrery\Tests\Support\LeaseProof;
use Justrau\Orrery\Tests\Support\ReusedSchema;
use Justrau\Orrery\Tests\Support\TestEnvironment;

(static function (): void {
    $gate = getenv('ORRERY_START_GATE');

    if (! is_string($gate) || $gate === '') {
        return;
    }

    $timeout = 30.0;
    $override = getenv('ORRERY_START_GATE_TIMEOUT');

    if (is_string($override) && is_numeric($override) && (float) $override > 0 && (float) $override < $timeout) {
        $timeout = (float) $override;
    }

    $parent = function_exists('posix_getppid') ? posix_getppid() : null;

    if (getenv('ORRERY_REGISTRY_TEST') === '1' && getenv('ORRERY_TEST_GATE_IGNORE_PARENT') === '1') {
        // Logic tests that prove bin/test itself stops this child turn off
        // the early exit, so the exit of the parent cannot hide a missing stop.
        $parent = null;
    }

    $deadline = microtime(true) + $timeout;

    while (! file_exists($gate)) {
        if ($parent !== null && posix_getppid() !== $parent) {
            fwrite(STDERR, "orrery: the supervising process exited before it opened the startup gate {$gate}\n");
            exit(79);
        }

        if (microtime(true) >= $deadline) {
            fwrite(STDERR, sprintf("orrery: the startup gate %s did not open within %s seconds\n", $gate, $timeout));
            exit(79);
        }

        usleep(10_000);
        clearstatcache();
    }

    putenv('ORRERY_START_GATE');
    unset($_ENV['ORRERY_START_GATE'], $_SERVER['ORRERY_START_GATE']);
})();

require_once __DIR__.'/../vendor/autoload.php';

// The opt-in harness profile reads and removes its variable now, before any
// test can start a child process that would inherit it.
HarnessTiming::enabled();

// The configuration check and, for a MySQL or Postgres run, the lease proof:
// the run of ORRERY_TEST_RUN_ID holds both database names and lists this
// process group. On a refusal it prints why and exits 78 before any test
// runs, so a direct vendor/bin/phpunit with server variables points to
// bin/test. It runs once per process (see Support\LeaseProof).
//
// The stand-in for PHPUnit in the logic tests of bin/test
// (tests/Scripts/Fixtures/phpunit-stub.php) touches no database and runs
// with injected process-inspection failures. It defines
// ORRERY_BOOTSTRAP_GATE_ONLY before it requires this file, so it passes only
// the startup gate. A constant cannot come from the environment.
if (! defined('ORRERY_BOOTSTRAP_GATE_ONLY')) {
    LeaseProof::bootstrap();

    // The schema mode (ORRERY_TEST_SCHEMA=fresh|reuse): an invalid value, or
    // reuse on in-memory SQLite, is refused here with the same exit 78.
    try {
        ReusedSchema::mode(TestEnvironment::fromEnv());
    } catch (HarnessRefusal $refusal) {
        fwrite(STDERR, 'orrery: '.$refusal->getMessage()."\n");

        exit(78);
    }
}
