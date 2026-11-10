<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PDOException;

/**
 * rollBack() throws a PDOException before the ROLLBACK reaches the server,
 * so the server transaction stays open and the PDO's own transaction flag
 * stays set. The raw form (exec('ROLLBACK')) throws in the same way. Only
 * closing the PDO ends that transaction (the server discards it).
 */
final class ThrowingRollbackPdo extends PDO
{
    use PdoFromConfig;

    /**
     * A PDO for a Laravel connection configuration (sqlite, mysql, pgsql).
     *
     * @param  array<string, mixed>  $config
     */
    public static function forConfig(array $config): self
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);

        return new self($dsn, $username, $password, $options);
    }

    public function rollBack(): bool
    {
        throw self::failure();
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*ROLLBACK\s*;?\s*$/i', $statement) === 1) {
            throw self::failure();
        }

        return parent::exec($statement);
    }

    private static function failure(): PDOException
    {
        return new PDOException('SQLSTATE[HY000]: General error: the test rollback failed before the ROLLBACK reached the server');
    }
}
