<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connectors\ConnectorInterface;
use Justrau\Orrery\Storage\Connectors\MariaDbConnector;
use Justrau\Orrery\Storage\Connectors\MySqlConnector;
use Justrau\Orrery\Storage\Connectors\PostgresConnector;
use PDO;

/**
 * Laravel's ConnectionFactory for the package sessions
 * (PackageSession::pdoResolver()). Its make() runs Laravel's own steps (the
 * configuration, the loop over several hosts, the connector's DSN and
 * session settings), but PackageSession gives it its own session
 * configuration, takes only the PDO, and never uses a factory that the
 * application binds as `db.factory`. Its MySQL, MariaDB and Postgres
 * connectors connect once (Connectors\ConnectsOnce).
 *
 * Laravel's Connector::createConnection() connects a second time when the
 * first connect fails with a message that its lost-connection detector
 * knows. On Linux, mysqlnd reports a connect timeout as `SQLSTATE[HY000]
 * [2002] Connection timed out`, which it knows, so each unreachable server
 * would cost two connect timeouts. A connector that the application binds as
 * `db.connector.<driver>` is used as it is: the application chose it. SQLite
 * keeps Laravel's connector (a local file has no connect timeout).
 *
 * The application's own connections never use this factory.
 */
final class SessionConnectionFactory extends ConnectionFactory
{
    /**
     * @param  (Closure(string $dsn, array<int, mixed> $options): ?PDO)|null  $attempt  the test seam of each connect attempt (PackageSession::useConnectAttemptForTesting())
     */
    public function __construct(Container $container, private readonly ?Closure $attempt = null)
    {
        parent::__construct($container);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return ConnectorInterface
     */
    public function createConnector(array $config)
    {
        $driver = $config['driver'] ?? null;

        if (is_string($driver) && ! $this->container->bound("db.connector.{$driver}")) {
            $connector = match ($driver) {
                'mysql' => new MySqlConnector($this->attempt),
                'mariadb' => new MariaDbConnector($this->attempt),
                'pgsql' => new PostgresConnector($this->attempt),
                default => null,
            };

            if ($connector !== null) {
                return $connector;
            }
        }

        return parent::createConnector($config);
    }
}
