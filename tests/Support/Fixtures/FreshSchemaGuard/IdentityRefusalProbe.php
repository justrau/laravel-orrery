<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\FreshSchemaGuard;

use Justrau\Orrery\Tests\TestCase;

/**
 * A fixture of FreshSchemaGuardTest: its environment points the package at
 * another connection (as PreD8ConfigurationTest once did), so the harness
 * refuses the identity in setUp(), before the endpoint proof. Its teardown
 * then must not run the fresh guard. PHPUnit runs it only when a test names
 * this file.
 */
final class IdentityRefusalProbe extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('orrery.database', null);
    }

    public function test_never_runs(): void
    {
        self::fail('The harness accepted an identity that does not match the test environment.');
    }
}
