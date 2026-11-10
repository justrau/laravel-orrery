<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\FreshSchema;

/**
 * A class without its own marker whose parent has one. PHPUnit never runs
 * it: it is not in a *Test.php file of a test suite.
 */
final class MarkedChild extends MarkedParent
{
    public function probe(): void
    {
    }
}
