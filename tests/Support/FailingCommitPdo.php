<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * A PDO whose commit() fails with a deadlock-shaped error.
 *
 * The first $failures calls of commit() end the server transaction without
 * a commit and throw a PDOException with SQLSTATE 40001 and the MySQL
 * deadlock message, as a server does that aborts a transaction on a
 * serialization failure at COMMIT. Laravel's retry path of
 * transaction(…, attempts) then begins the next attempt (every Laravel 12
 * and 13 release; the lowest ones do not roll the PDO back themselves).
 * Later calls commit.
 */
final class FailingCommitPdo extends PDO
{
    /** The commits that failed. */
    public int $failedCommits = 0;

    /** The commits that reached the server. */
    public int $commits = 0;

    public function __construct(string $dsn, ?string $username, ?string $password, private int $failures)
    {
        parent::__construct($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * A new PDO to the database of a Laravel connection configuration
     * (sqlite, mysql or pgsql).
     *
     * @param  array<string, mixed>  $config
     */
    public static function forConfig(array $config, int $failures): self
    {
        $driver = $config['driver'] ?? null;
        $database = (string) ($config['database'] ?? '');

        $dsn = match ($driver) {
            'sqlite' => $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:'.$database,
            'mysql', 'mariadb' => sprintf('mysql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $database),
            'pgsql' => sprintf('pgsql:host=%s;port=%d;dbname=%s', $config['host'], $config['port'], $database),
            default => throw new InvalidArgumentException('FailingCommitPdo supports sqlite, mysql, mariadb and pgsql.'),
        };

        $username = $driver === 'sqlite' ? null : (string) $config['username'];
        $password = $driver === 'sqlite' ? null : (string) $config['password'];

        return new self($dsn, $username, $password, $failures);
    }

    public function commit(): bool
    {
        if ($this->failures > 0) {
            $this->failures--;
            $this->failedCommits++;
            parent::rollBack();

            throw self::deadlock();
        }

        $this->commits++;

        return parent::commit();
    }

    private static function deadlock(): PDOException
    {
        // PDOException keeps the SQLSTATE as a string code.
        return new class('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction') extends PDOException
        {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '40001';
                $this->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
            }
        };
    }
}
