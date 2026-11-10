<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use InvalidArgumentException;
use PDO;

/**
 * The DSN, the credentials and the options of a new PDO to the database of
 * a Laravel connection configuration (sqlite, mysql or pgsql), for the test
 * PDOs of the retry protocol. The PDO is never persistent and
 * runs in exception mode. PackageSession::usePdoFactoryForTesting() applies the
 * session settings (the isolation level and the lock wait) to it.
 */
trait PdoFromConfig
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: ?string, 2: ?string, 3: array<int, mixed>}
     */
    private static function pdoArguments(array $config): array
    {
        $driver = $config['driver'] ?? null;
        $database = (string) ($config['database'] ?? '');

        $dsn = match ($driver) {
            'sqlite' => $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:'.$database,
            'mysql', 'mariadb' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $database),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $database),
            default => throw new InvalidArgumentException('The test PDOs support sqlite, mysql and pgsql.'),
        };

        $username = $driver === 'sqlite' ? null : (string) $config['username'];
        $password = $driver === 'sqlite' ? null : (string) $config['password'];

        return [$dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false]];
    }
}
