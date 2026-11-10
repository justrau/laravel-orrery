<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * An in-memory SQLite PDO that stands in for a MySQL or Postgres server PDO
 * in the connect tests (ConnectAttemptsTest), which never reach a server.
 * It accepts the session statements that Laravel's connector and
 * PackageSession send after a connect (`use …`, `SET …`), and a begin
 * (beginTransaction(), or a pooled Postgres session's combined `BEGIN …`
 * exec) opens a real SQLite transaction.
 * loseConnection() makes its statements fail as a dropped server connection
 * does, with a message that Laravel's lost-connection detector knows, and
 * failNextStatement() with any driver error.
 */
final class ServerStandInPdo extends PDO
{
    private ?PDOException $nextFailure = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function loseConnection(): void
    {
        $this->failNextStatement(new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
    }

    /**
     * The next statement throws $error, and so does every later one.
     */
    public function failNextStatement(PDOException $error): void
    {
        $this->nextFailure = $error;
    }

    /**
     * The next statements succeed again (a lock that was released).
     */
    public function recover(): void
    {
        $this->nextFailure = null;
    }

    public function exec(string $statement): int|false
    {
        $this->failWhenLost();

        if (preg_match('/^\s*begin\b/i', $statement) === 1) {
            // A pooled Postgres session's combined begin (`BEGIN ISOLATION
            // LEVEL …; SET LOCAL …`): a real transaction, so PDO sees it.
            parent::beginTransaction();

            return 0;
        }

        return self::isSessionStatement($statement) ? 0 : parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->failWhenLost();

        return parent::beginTransaction();
    }

    /**
     * @param  array<int, mixed>  $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->failWhenLost();

        return parent::prepare(self::isSessionStatement($query) ? 'select 1' : $query, $options);
    }

    private function failWhenLost(): void
    {
        if ($this->nextFailure !== null) {
            throw $this->nextFailure;
        }
    }

    private static function isSessionStatement(string $sql): bool
    {
        return preg_match('/^\s*(use|set)\s/i', $sql) === 1;
    }
}
