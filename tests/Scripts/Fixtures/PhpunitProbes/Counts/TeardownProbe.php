<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures\PhpunitProbes\Counts;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An error in the test and a failed assertion in tearDown: PHPUnit writes
 * both as faults of one JUnit test case.
 */
final class TeardownProbe extends TestCase
{
    protected function tearDown(): void
    {
        self::assertTrue(false, 'orrery-teardown-failure');
    }

    public function test_error_then_teardown_failure(): void
    {
        throw new RuntimeException('orrery-listed-error');
    }
}
