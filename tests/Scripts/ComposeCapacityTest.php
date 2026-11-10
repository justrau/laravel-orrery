<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The capacity of the disposable test servers in docker/testing/compose.yaml.
 *
 * The data of each server is in a tmpfs, and the kernel charges tmpfs pages to
 * the memory cgroup of the container. So a memory limit that does not cover the
 * tmpfs and the server stops the server at the limit, and a tmpfs that is too
 * small for concurrent suites makes Postgres refuse every connection. The
 * expected values are the values that CLAUDE.md and the Compose
 * comments name. The tests read only the file; they never call Docker.
 */
#[Group('scripts')]
final class ComposeCapacityTest extends TestCase
{
    private const MIB = 1048576;

    /** service => [tmpfs bytes, memory limit bytes] */
    private const CAPACITY = [
        'mysql' => [512 * self::MIB, 1024 * self::MIB],
        'mysql-alt' => [512 * self::MIB, 1024 * self::MIB],
        'postgres' => [1024 * self::MIB, 1536 * self::MIB],
        'pgsql-alt' => [1024 * self::MIB, 1536 * self::MIB],
    ];

    /** The memory that a limit must keep for the server after a full tmpfs. */
    private const SERVER_ALLOWANCE = 384 * self::MIB;

    /** The alt service of each default service. */
    private const ALT = ['mysql' => 'mysql-alt', 'postgres' => 'pgsql-alt'];

    /**
     * Rejects: a service whose tmpfs or memory limit differs from the documented
     * value, for example the old 512 MB Postgres tmpfs.
     * Still passes: documentation that names other values (the test does not read it).
     */
    public function test_each_service_has_the_documented_tmpfs_and_memory_limit(): void
    {
        $services = self::services();

        self::assertEqualsCanonicalizing(array_keys(self::CAPACITY), array_keys($services));

        foreach (self::CAPACITY as $name => [$tmpfs, $memory]) {
            self::assertSame($tmpfs, self::tmpfsSize($services[$name]), "The tmpfs size of {$name}");
            self::assertSame($memory, self::bytes($services[$name]['mem_limit']), "The memory limit of {$name}");
        }
    }

    /**
     * Rejects: a memory limit that a full tmpfs fills up to less than the server
     * allowance, for example a 1 GiB tmpfs under a 1g limit.
     * Still passes: an allowance that the server itself outgrows under a heavier load.
     */
    public function test_each_memory_limit_covers_a_full_tmpfs_and_the_server(): void
    {
        foreach (self::services() as $name => $service) {
            $room = self::bytes($service['mem_limit']) - self::tmpfsSize($service);

            self::assertGreaterThanOrEqual(self::SERVER_ALLOWANCE, $room, "The memory limit of {$name} leaves too little room after a full tmpfs.");
        }
    }

    /**
     * Rejects: a MySQL service that keeps performance_schema (more than 100 MiB) or the
     * 64 MiB default log buffer.
     * Still passes: other flags that grow the memory of mysqld.
     */
    public function test_mysql_runs_without_performance_schema_and_with_a_small_log_buffer(): void
    {
        foreach (['mysql', 'mysql-alt'] as $name) {
            $command = self::services()[$name]['command'];

            self::assertContains('--performance-schema=OFF', $command, $name);
            self::assertContains('--innodb-log-buffer-size=16777216', $command, $name);
            self::assertContains('--skip-log-bin', $command, $name);
        }
    }

    /**
     * Rejects: a Postgres service without a bound on its WAL.
     * Still passes: a WAL bound that is too large for the tmpfs.
     */
    public function test_postgres_bounds_its_wal(): void
    {
        foreach (['postgres', 'pgsql-alt'] as $name) {
            self::assertContains('max_wal_size=128MB', self::services()[$name]['command'], $name);
        }
    }

    /**
     * Rejects: an alt service whose image, command, tmpfs or memory limit drifts
     * from its default service, other than the Postgres port.
     * Still passes: a drift in the health check timing.
     */
    public function test_each_alt_service_copies_its_default_service(): void
    {
        $services = self::services();

        foreach (self::ALT as $default => $alt) {
            self::assertSame($services[$default]['image'], $services[$alt]['image'], $alt);
            self::assertSame($services[$default]['mem_limit'], $services[$alt]['mem_limit'], $alt);
            self::assertSame($services[$default]['volumes'], $services[$alt]['volumes'], $alt);

            $altCommand = $services[$alt]['command'];
            if ($alt === 'pgsql-alt') {
                $index = array_search('port=5433', $altCommand, true);
                self::assertIsInt($index, 'pgsql-alt listens on 5433');
                self::assertSame('-c', $altCommand[$index - 1]);
                array_splice($altCommand, $index - 1, 2);
            }
            self::assertSame($services[$default]['command'], $altCommand, $alt);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function services(): array
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2).'/docker/testing/compose.yaml');

        return $config['services'];
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private static function tmpfsSize(array $service): int
    {
        $sizes = [];
        foreach ($service['volumes'] as $volume) {
            if (is_array($volume) && ($volume['type'] ?? null) === 'tmpfs') {
                $sizes[] = self::bytes($volume['tmpfs']['size']);
            }
        }
        self::assertCount(1, $sizes, 'Each service has exactly one tmpfs.');

        return $sizes[0];
    }

    /** A Compose byte value: an integer, or a number with the unit b, k, m or g (binary units). */
    private static function bytes(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (preg_match('/^(\d+)([bkmg]?)$/i', $value, $match) !== 1) {
            self::fail("Unknown byte value: {$value}");
        }
        $power = ['' => 0, 'b' => 0, 'k' => 1, 'm' => 2, 'g' => 3][strtolower($match[2])];

        return (int) $match[1] * 1024 ** $power;
    }
}
