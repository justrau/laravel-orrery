<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures\PhpunitProbes\Counts;

use PHPUnit\Framework\TestCase;

/**
 * A test that setUp skips: PHPUnit counts it before the test is prepared.
 */
final class SetUpSkipProbe extends TestCase
{
    protected function setUp(): void
    {
        self::markTestSkipped('skipped in setUp');
    }

    public function test_one(): void
    {
        self::assertTrue(true);
    }
}
