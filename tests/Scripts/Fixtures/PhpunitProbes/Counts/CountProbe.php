<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts\Fixtures\PhpunitProbes\Counts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Run by KnownFailuresCheckTest with the installed PHPUnit (--test-suffix
 * Probe.php): each kind of test that PHPUnit counts differently in its
 * summary and its JUnit file. The suites never load *Probe.php files.
 */
final class CountProbe extends TestCase
{
    public function test_passes(): void
    {
        self::assertTrue(true);
        self::assertTrue(true);
    }

    public function test_skipped_in_the_body(): void
    {
        self::assertTrue(true);
        self::markTestSkipped('skipped in the body');
    }

    public function test_incomplete(): void
    {
        self::markTestIncomplete('incomplete');
    }

    #[RequiresPhpExtension('orrery_missing_extension')]
    public function test_requires_a_missing_extension(): void
    {
        self::assertTrue(true);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function sets(): iterable
    {
        yield 'a' => [1];
        yield 'b' => [2];
    }

    #[DataProvider('sets')]
    public function test_data(int $value): void
    {
        self::assertGreaterThan(0, $value);
    }
}
