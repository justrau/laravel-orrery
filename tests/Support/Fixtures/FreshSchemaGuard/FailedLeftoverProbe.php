<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard;

use Justrau\Orrery\Tests\TestCase;

/**
 * A fixture of FreshSchemaGuardTest: a test that fails and whose rollback
 * misses the mirror (it clears orrery.database). PHPUnit drops the guard's
 * teardown failure of a failed test, so the guard names the leftovers on
 * STDERR. PHPUnit runs it only when a test names this file.
 */
final class FailedLeftoverProbe extends TestCase
{
    public function test_fails_and_leaves_the_mirror_migrated(): void
    {
        $this->app['config']->set('orrery.database', null);

        self::fail('The probe fails.');
    }
}
