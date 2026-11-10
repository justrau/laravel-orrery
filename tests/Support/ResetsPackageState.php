<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Queue\Queue;
use Justrau\Orrery\Registration\Modules;
use Justrau\Orrery\Runtime\Hooks;

/**
 * The one reset of the static state of the package.
 *
 * PHPUnit runs the tests in one process, so static state survives from one
 * test to the next. tests/TestCase.php calls resetPackageState() before and
 * after every test.
 *
 * Every other state of the package is a container binding (a singleton or a
 * scoped binding), and Testbench builds a new application for each test,
 * so it needs no reset. What stays static is in the allow-list of
 * tests/Scripts/StaticStateInventoryTest.php:
 * - the public static API (Orrery's callbacks and notification routes, and
 *   Tags): HttpModule resets it;
 * - the test clock seams (Clock, DatabaseClock): StorageModule resets them;
 * - the test modules of Modules::addForTesting(): Modules removes them;
 * - the hook point seam (Hooks::$callback);
 * - Laravel's own static list of payload callbacks.
 */
trait ResetsPackageState
{
    public static function resetPackageState(): void
    {
        // Every registration module resets its allow-listed statics, and the
        // test modules of Modules::addForTesting() are removed.
        Modules::resetAllForTesting();

        // The hook point callback (a test can set it in this process too).
        Hooks::$callback = null;

        // Laravel's own static list: the next application boot registers the
        // package's payload callbacks again.
        Queue::createPayloadUsing(null);
    }
}
