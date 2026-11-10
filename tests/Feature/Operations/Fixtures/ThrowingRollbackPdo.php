<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations\Fixtures;

use PDO;
use PDOException;
use Justrau\Orrery\Tests\Support\PdoFromConfig;

/**
 * A PDO whose rollBack() throws and leaves the transaction open.
 * Closing the PDO ends the server transaction.
 */
final class ThrowingRollbackPdo extends PDO
{
    use PdoFromConfig;

    /** @param  array<string, mixed>  $config */
    public static function forConfig(array $config): self
    {
        [$dsn, $username, $password, $options] = self::pdoArguments($config);

        return new self($dsn, $username, $password, $options);
    }

    public function rollBack(): bool
    {
        throw new PDOException('SQLSTATE[HY000]: General error: the test rollback failed');
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*rollback\b/i', $statement) === 1) {
            throw new PDOException('SQLSTATE[HY000]: General error: the test rollback failed');
        }

        return parent::exec($statement);
    }
}
