<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures\PhpunitProbes\BeforeClass;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An error in setUpBeforeClass: PHPUnit counts a test and an error in its
 * summary, and writes no test case to the JUnit file.
 */
final class BeforeClassErrorProbe extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        throw new RuntimeException('orrery-before-class-error');
    }

    public function test_one(): void
    {
        self::assertTrue(true);
    }
}
