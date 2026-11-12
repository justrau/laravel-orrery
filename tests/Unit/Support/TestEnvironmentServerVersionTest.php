<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Support;

use Justrau\Orrery\Tests\Support\HarnessRefusal;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ORRERY_TEST_SERVER_VERSION: the server release that the identity check
 * expects, chosen from a short list per engine (the CI job of the previous
 * MariaDB LTS release sets it).
 */
final class TestEnvironmentServerVersionTest extends TestCase
{
    /**
     * Rejects: an environment that expects no release, or another release than the local server's,
     * when the variable is unset.
     * Still passes: a default release that is not the release the local compose file runs
     * (CiWorkflowTest compares the images, not this constant).
     */
    public function test_without_the_variable_each_engine_expects_its_default_release(): void
    {
        foreach (['mysql' => '8.4.', 'pgsql' => '18.', 'mariadb' => '12.3.'] as $driver => $release) {
            $env = TestEnvironment::fromArray(self::server($driver));

            self::assertSame($release, $env->serverVersion, $driver);
            self::assertSame(TestEnvironment::SERVER_VERSIONS[$driver], $env->serverVersion, $driver);
            self::assertFalse($env->toProcessEnv()['ORRERY_TEST_SERVER_VERSION'], "{$driver}: a child gets no variable for the default");
        }

        self::assertNull(TestEnvironment::fromArray([])->serverVersion, 'SQLite has no server release');
    }

    /**
     * Rejects: a MariaDB 11.8 choice that the harness ignores (it would expect 12.3 and refuse the
     * server), and one that a worker process loses (WorkerProcess unsets every inherited
     * ORRERY_TEST_* variable and sets toProcessEnv()).
     * Still passes: an 11.8 server whose features differ from 12.3 (only a CI run on 11.8 shows that).
     */
    public function test_mariadb_can_select_the_previous_lts_release_and_children_inherit_it(): void
    {
        foreach (['11.8' => '11.8.', '12.3' => '12.3.'] as $choice => $release) {
            $env = TestEnvironment::fromArray(self::server('mariadb') + ['ORRERY_TEST_SERVER_VERSION' => $choice]);

            self::assertSame($release, $env->serverVersion);
            $child = $env->toProcessEnv();
            self::assertEquals($env, TestEnvironment::fromArray(array_filter($child, static fn (mixed $value): bool => $value !== false)));
        }

        $child = TestEnvironment::fromArray(self::server('mariadb') + ['ORRERY_TEST_SERVER_VERSION' => '11.8'])->toProcessEnv();
        self::assertSame('11.8', $child['ORRERY_TEST_SERVER_VERSION']);
        self::assertContains('ORRERY_TEST_SERVER_VERSION', TestEnvironment::VARIABLES);
    }

    /**
     * @return iterable<string, array{0: array<string, string>}>
     */
    public static function refusedChoices(): iterable
    {
        yield 'a MariaDB release off the list' => [self::server('mariadb') + ['ORRERY_TEST_SERVER_VERSION' => '11.4']];
        yield 'a prefix of an allowed release' => [self::server('mariadb') + ['ORRERY_TEST_SERVER_VERSION' => '11']];
        yield 'the release with its trailing dot' => [self::server('mariadb') + ['ORRERY_TEST_SERVER_VERSION' => '11.8.']];
        yield 'the MariaDB release on MySQL' => [self::server('mysql') + ['ORRERY_TEST_SERVER_VERSION' => '11.8']];
        yield 'the MariaDB release on Postgres' => [self::server('pgsql') + ['ORRERY_TEST_SERVER_VERSION' => '11.8']];
        yield 'any release on SQLite' => [['ORRERY_TEST_SERVER_VERSION' => '12.3']];
    }

    /**
     * Rejects: a harness that accepts any release (a wrong server would then pass the identity check),
     * a release of another engine, or a release on SQLite (it would be silently ignored).
     * Still passes: a refusal message that names no allowed release (the test checks only the variable).
     *
     * @param  array<string, string>  $env
     */
    #[DataProvider('refusedChoices')]
    public function test_a_release_off_the_list_of_its_engine_is_refused(array $env): void
    {
        try {
            TestEnvironment::fromArray($env);
            self::fail('The harness accepted '.json_encode($env));
        } catch (HarnessRefusal $refusal) {
            self::assertStringContainsString('ORRERY_TEST_SERVER_VERSION', $refusal->getMessage());
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
