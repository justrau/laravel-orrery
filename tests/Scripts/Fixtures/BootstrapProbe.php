<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * A probe for HarnessBootstrapTest. It is in no test suite: the test runs it
 * by path. It touches no database. It only writes ORRERY_PROBE_OUT/ran, which
 * proves that tests/bootstrap.php let PHPUnit run a test.
 */
final class BootstrapProbe extends TestCase
{
    public function test_probe(): void
    {
        $out = getenv('ORRERY_PROBE_OUT');
        self::assertIsString($out);
        self::assertNotSame('', $out);

        file_put_contents($out.'/ran', (string) getmypid());
        self::assertFileExists($out.'/ran');
    }
}
