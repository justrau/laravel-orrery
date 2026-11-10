<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * TestEnvironment::alt(): the configuration of the second server of the
 * alt profile (bin/test <mysql|pgsql> --alt).
 */
final class TestEnvironmentAltTest extends TestCase
{
    /**
     * Rejects: an alt configuration from a leftover port variable.
     */
    public function test_alt_is_null_without_the_alt_flag(): void
    {
        foreach (['mysql' => 'ORRERY_TEST_MYSQL_ALT_PORT', 'pgsql' => 'ORRERY_TEST_PGSQL_ALT_PORT'] as $driver => $variable) {
            $env = TestEnvironment::fromArray(self::server($driver) + [$variable => '34000']);

            self::assertNull($env->alt(), "{$driver}: a leftover alt port gives no alt server");
            self::assertFalse($env->toProcessEnv()['ORRERY_TEST_ALT']);
            self::assertFalse($env->toProcessEnv()[$variable], "{$driver}: a child gets no leftover alt port");
        }

        self::assertNull(TestEnvironment::fromArray([])->alt());

        try {
            TestEnvironment::fromArray(['ORRERY_TEST_ALT' => '1']);
            self::fail('ORRERY_TEST_ALT with the sqlite driver was accepted');
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('ORRERY_TEST_ALT', $refusal->getMessage());
        }

        try {
            TestEnvironment::fromArray(self::server('mysql') + ['ORRERY_TEST_ALT' => 'yes']);
            self::fail('ORRERY_TEST_ALT=yes was accepted');
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('ORRERY_TEST_ALT', $refusal->getMessage());
        }
    }

    /**
     * Rejects: an alt configuration with another database name or user.
     */
    public function test_alt_changes_only_the_port_and_reaches_child_processes(): void
    {
        foreach (['mysql' => ['ORRERY_TEST_MYSQL_ALT_PORT', 33107], 'pgsql' => ['ORRERY_TEST_PGSQL_ALT_PORT', 54337]] as $driver => [$variable, $default]) {
            $env = TestEnvironment::fromArray(self::server($driver) + ['ORRERY_TEST_ALT' => '1']);
            $primary = $env->queueConnection();
            $alt = $env->alt();

            self::assertIsArray($alt);
            self::assertSame($default, $alt['port'], "{$driver}: the default alt port");
            self::assertNotSame($primary['port'], $alt['port']);
            self::assertSame(array_replace($primary, ['port' => $default]), $alt, "{$driver}: only the port differs");

            $child = $env->toProcessEnv();
            self::assertSame('1', $child['ORRERY_TEST_ALT']);
            self::assertSame((string) $default, $child[$variable]);
            self::assertEquals($env, TestEnvironment::fromArray(array_filter($child, static fn (mixed $value): bool => $value !== false)));

            $override = TestEnvironment::fromArray(self::server($driver) + ['ORRERY_TEST_ALT' => '1', $variable => '34001']);
            self::assertSame(34001, $override->alt()['port'] ?? null);
            self::assertSame('34001', $override->toProcessEnv()[$variable]);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function server(string $driver): array
    {
        return [
            'ORRERY_TEST_DRIVER' => $driver,
            'ORRERY_TEST_DATABASE' => 'orrery_b4_unit',
            'ORRERY_TEST_TOKEN' => str_repeat('a', 32),
            'ORRERY_TEST_RUN_ID' => 'run_1_0000000a',
        ];
    }
}
