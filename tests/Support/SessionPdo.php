<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use Justrau\Orrery\Storage\PostgresSession;
use PDO;

/**
 * A test PDO that replaces the PDO of a live package session through
 * setPdo() gets the PDO attribute that the package gives every PDO of that
 * session: a pooled Postgres session emulates its prepares
 * (PackageSession::applyToFactoryPdo() and afterConnect()), because its
 * autocommit statements carry their bounds in the same simple query
 * (AutocommitBounds). Without it, Postgres refuses each autocommit statement
 * with "cannot insert multiple commands into a prepared statement", so the
 * test would measure its own PDO, not the package.
 *
 * Every other session keeps the PDO as it is.
 */
final class SessionPdo
{
    /**
     * @template T of PDO
     *
     * @param  T  $pdo
     * @return T
     */
    public static function for(Connection $session, PDO $pdo): PDO
    {
        if ($session instanceof PostgresSession && $session->isPooled()) {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        }

        return $pdo;
    }
}
