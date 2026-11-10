<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use PDO;

/**
 * The physical table names of a connection's database, read through its PDO
 * from the engine's catalog. The schema builder is not used, so the names
 * show what the prefix logic really produced.
 */
final class PhysicalTables
{
    /**
     * @return list<string>  sorted
     */
    public static function of(Connection $connection): array
    {
        return self::onPdo($connection->getPdo(), $connection->getDriverName());
    }

    /**
     * The same, through a PDO that a test kept, for example after its
     * application was torn down.
     *
     * @return list<string>  sorted
     */
    public static function onPdo(PDO $pdo, string $driver): array
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'",
            'mysql' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
            'pgsql' => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'",
            default => throw new HarnessRefusal('Unsupported driver '.$driver),
        };

        /** @var list<string> $names */
        $names = array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        sort($names);

        return $names;
    }
}
