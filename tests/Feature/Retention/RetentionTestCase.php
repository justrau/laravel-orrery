<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Retention;

use Justrau\Orrery\Tests\Feature\Lifecycle\CycleTestCase;

/**
 * The base of the retention and monitoring tests: the lifecycle
 * fixtures of CycleTestCase and the retention fixtures.
 */
abstract class RetentionTestCase extends CycleTestCase
{
    use RetentionFixtures;
}
