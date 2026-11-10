<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PDOException;

/**
 * commit() throws a PDOException before the COMMIT reaches the server, so the
 * server transaction stays open ("a test
 * connection whose commit() throws before it reaches the server").
 *
 * The raw form of a commit (exec('COMMIT'), which the package transaction
 * uses on SQLite) throws in the same way. The PDO's own transaction flag
 * stays set, so a later rollBack() or a disconnect ends the server
 * transaction. Every commit throws: $attempts counts them.
 */
final class ThrowingCommitPdo extends PDO
{
    use PdoFromConfig;

    /** The commit calls that threw. */
    public int $attempts = 0;

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

    public function commit(): bool
    {
        $this->attempts++;

        throw self::failure();
    }

    public function exec(string $statement): int|false
    {
        if (preg_match('/^\s*(COMMIT|END)\b/i', $statement) === 1) {
            $this->attempts++;

            throw self::failure();
        }

        return parent::exec($statement);
    }

    private static function failure(): PDOException
    {
        return new PDOException('SQLSTATE[HY000]: General error: the test commit failed before the COMMIT reached the server');
    }
}
