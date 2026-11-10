<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Schema;

use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Justrau\Orrery\Database\Schema\PackageSchema;
use PHPUnit\Framework\TestCase;

/**
 * PackageSchema::byteExactCollation(): the binary NO PAD
 * collation of the partition columns per server, with the PAD SPACE
 * `utf8mb4_bin` on a MySQL server that has no `utf8mb4_0900_bin`.
 */
final class ByteExactCollationTest extends TestCase
{
    /**
     * Rejects: `utf8mb4_0900_bin` on a MySQL server that lacks it (the
     * create would fail), or a fallback on a server that has it.
     */
    public function test_mysql_falls_back_to_utf8mb4_bin_without_the_0900_collation(): void
    {
        self::assertSame('utf8mb4_0900_bin', PackageSchema::byteExactCollation(self::mysql(true)));
        self::assertSame('utf8mb4_bin', PackageSchema::byteExactCollation(self::mysql(false)));
    }

    /**
     * Rejects: a collation on the engines that compare bytes already, or the
     * MySQL name on MariaDB.
     */
    public function test_the_other_servers(): void
    {
        $pdo = static fn () => throw new \LogicException('never connects');
        $config = ['driver' => 'mariadb', 'name' => 'fake'];

        self::assertSame('utf8mb4_nopad_bin', PackageSchema::byteExactCollation(new MariaDbConnection($pdo, 'orrery_fake', '', $config)));
        self::assertNull(PackageSchema::byteExactCollation(new PostgresConnection($pdo, 'orrery_fake', '', ['driver' => 'pgsql', 'name' => 'fake'])));
        self::assertNull(PackageSchema::byteExactCollation(new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'name' => 'fake'])));
    }

    private static function mysql(bool $has0900): MySqlConnection
    {
        $pdo = static fn () => throw new \LogicException('never connects');

        return new class($pdo, 'orrery_fake', '', ['driver' => 'mysql', 'name' => 'fake'], $has0900) extends MySqlConnection
        {
            /** @param  array<string, mixed>  $config */
            public function __construct(mixed $pdo, string $database, string $prefix, array $config, private readonly bool $has0900)
            {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            public function isMaria()
            {
                return false;
            }

            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                return $this->has0900 ? (object) ['found' => 1] : null;
            }
        };
    }
}
