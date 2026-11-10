<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures\PhpunitProbes\BeforeClass;

use PHPUnit\Framework\TestCase;

/**
 * A passing test next to BeforeClassErrorProbe, so the JUnit file has an
 * executed test.
 */
final class PassingProbe extends TestCase
{
    public function test_passes(): void
    {
        self::assertTrue(true);
    }
}
