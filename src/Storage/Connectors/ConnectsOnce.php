<?php

namespace Justrau\Orrery\Storage\Connectors;

use Closure;
use PDO;
use Throwable;

/**
 * A connector of a package session (SessionConnectionFactory): Laravel's
 * connector of the driver, but a failed connect is not tried again.
 *
 * Laravel's Connector::createConnection() connects once more when the
 * lost-connection detector knows the message of the first failure. That
 * list holds connect failures that take a whole connect timeout, for
 * example mysqlnd's `SQLSTATE[HY000] [2002] Connection timed out` on Linux
 * or a name lookup that libpq reports as `Temporary failure in name
 * resolution`. A package session spends at most one connect timeout on an
 * unreachable server per statement (PackageSession::CONNECT_TIMEOUT_SECONDS).
 *
 * The class that uses this trait extends Illuminate\Database\Connectors\Connector.
 */
trait ConnectsOnce
{
    /**
     * @param  (Closure(string $dsn, array<int, mixed> $options): ?PDO)|null  $attempt  the test seam of each connect attempt
     */
    public function __construct(private readonly ?Closure $attempt = null)
    {
    }

    /**
     * Throws the failure of the first connect: no second connect.
     *
     * @param  string  $dsn
     * @param  string  $username
     * @param  string  $password
     * @param  array<int, mixed>  $options
     * @return PDO
     *
     * @throws Throwable
     */
    protected function tryAgainIfCausedByLostConnection(Throwable $e, $dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        throw $e;
    }

    /**
     * @param  string  $dsn
     * @param  string  $username
     * @param  string  $password
     * @param  array<int, mixed>  $options
     * @return PDO
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
    {
        $pdo = $this->attempt === null ? null : ($this->attempt)($dsn, $options);

        if ($pdo !== null) {
            return $pdo;
        }

        return parent::createPdoConnection($dsn, $username, $password, $options);
    }
}
