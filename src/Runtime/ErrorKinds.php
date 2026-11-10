<?php

namespace Justrau\Orrery\Runtime;

use Illuminate\Container\Container;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\MirrorRefused;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransactionBusy;
use Justrau\Orrery\Storage\PooledSessionRefused;
use Justrau\Orrery\Storage\SessionPdoException;
use Justrau\Orrery\Storage\SiblingFileUnavailable;
use PDOException;
use Throwable;

/**
 * The two kinds of errors of watched work.
 *
 * A dependency error means that a package session cannot reach its database,
 * so the next observations would fail too: the watcher opens its breaker. It
 * is one of these:
 * - MirrorRefused, SiblingFileUnavailable or PooledSessionRefused,
 *   anywhere in the chain of previous exceptions: the package refuses its
 *   mirror, the sibling SQLite file cannot be created or used, or a pooled
 *   session is refused;
 * - a QueryException on a package session (PackageSession::isPackageSession()) whose
 *   driver error Laravel's lost-connection detector recognises, or whose
 *   SQLSTATE has the class 08 (connection exception). The detector reads the
 *   driver's message, never the QueryException's own, which holds the SQL
 *   with its bindings;
 * - the same for a SessionPdoException: the PDOException of a package
 *   session that Laravel does not wrap in a QueryException (a failed connect
 *   in a begin or in the build of a pooled Postgres session, a raw exec()).
 *   Any other PDOException names no session, so it stays a data error;
 * - on a MySQL or MariaDB session, also a MySQL client connection error
 *   (MYSQL_CONNECTION_ERRORS) by its error number, whatever its SQLSTATE
 *   and text.
 *
 * The SQLSTATE comes from errorInfo, else from the driver code, else from
 * the `SQLSTATE[xxxxx]` at the start of the driver's message: libpq's
 * connect failure (for example `SQLSTATE[08006] [7] … timeout expired`)
 * has no errorInfo and the driver code 7.
 *
 * Every other error is a data error: it drops only its own observation. So
 * an error of an application connection never opens the breaker. The
 * breaker is the mirror's: only a dependency error of the mirror's session
 * opens it (isMirrorDependency()). The operation runner uses isDependency()
 * per connection.
 */
final class ErrorKinds
{
    /**
     * A refused automatic access to a host SQLite file (D18), anywhere in the
     * chain: the watcher treats it as a skip, never as an error.
     */
    public static function isHostFileRefusal(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof HostFileAccessNotAllowed) {
                return true;
            }
        }

        return false;
    }

    public const DEPENDENCY = 'dependency';

    public const DATA = 'data';

    /**
     * The MySQL client errors of a connection that cannot be used: 2002
     * (cannot connect through the socket or TCP, the connect timeout
     * included), 2003 (cannot connect to the server), 2005 (unknown host),
     * 2006 (server has gone away), 2013 (lost connection during a query) and
     * 2055 (lost connection, system error).
     */
    private const MYSQL_CONNECTION_ERRORS = [2002, 2003, 2005, 2006, 2013, 2055];

    public static function classify(Throwable $error): string
    {
        return self::isDependency($error) ? self::DEPENDENCY : self::DATA;
    }

    public static function isDependency(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MirrorRefused || $current instanceof SiblingFileUnavailable || $current instanceof PooledSessionRefused) {
                return true;
            }
        }

        $session = self::sessionOf($error);

        if (! PackageSession::isPackageSession($session)) {
            return false;
        }

        /** @var QueryException|SessionPdoException $error */
        return str_starts_with(self::sqlState($error), '08')
            // Never the QueryException's own message: it holds the SQL with its bindings (a job's text).
            || self::causedByLostConnection($error instanceof QueryException ? ($error->getPrevious() ?? $error) : $error)
            || self::isMySqlConnectionError((string) $session, $error);
    }

    /**
     * Whether an error opens the mirror breaker (Watcher::failed()): a
     * dependency error of the mirror's session (PackageSession::mirrorSessionName();
     * a queue connection with the mirror's connection name is that session),
     * a refused or unusable mirror (MirrorRefused, SiblingFileUnavailable),
     * or a refused pooled session of the mirror's connection. A dependency
     * error of another package session, for example a secondary queue
     * database, is handled per target and never stops the work on the
     * mirror. When the mirror's name cannot be read, every dependency error
     * counts.
     */
    public static function isMirrorDependency(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof MirrorRefused || $current instanceof SiblingFileUnavailable) {
                return true;
            }
        }

        if (! self::isDependency($error)) {
            return false;
        }

        try {
            $mirror = Container::getInstance()->make(PackageSession::class)->mirrorSessionName();
        } catch (Throwable) {
            return true;
        }

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PooledSessionRefused) {
                return PackageSession::NAME_PREFIX.$current->connection === $mirror;
            }
        }

        return self::sessionOf($error) === $mirror;
    }

    /**
     * Whether an error is a lock timeout on the mirror's session:
     * the mirror answers, but its writes are blocked (a REINDEX, an
     * ALTER that waits for a metadata lock). Two of them within
     * Watcher::MIRROR_TIMEOUT_WINDOW_SECONDS open the breaker
     * (Watcher::failed()), also with a success between them. By driver:
     * - MySQL and MariaDB: 1205 (lock wait timeout);
     * - Postgres: SQLSTATE 55P03 (lock_timeout);
     * - SQLite: result code 5 or 6 (busy, locked) after its busy_timeout,
     *   also a PackageTransactionBusy of the mirror's session.
     * A statement timeout (MySQL 3024, MariaDB 1969, Postgres 57014) never
     * counts: MySQL applies max_execution_time to reads only, so a slow tick
     * phase or dashboard read must not stop the observations. The numbers
     * are read from the driver error only, never from a message (it holds
     * the SQL with its bindings).
     */
    public static function isMirrorTimeout(Throwable $error): bool
    {
        try {
            $sessions = Container::getInstance()->make(PackageSession::class);
            $mirror = $sessions->mirrorSessionName();

            for ($current = $error; $current !== null; $current = $current->getPrevious()) {
                if ($current instanceof PackageTransactionBusy) {
                    $name = $current->session === null ? null : explode('::', $current->session)[0];

                    return $name !== null && ($name === $mirror || PackageSession::NAME_PREFIX.$name === $mirror);
                }

                if ($current instanceof QueryException || $current instanceof SessionPdoException) {
                    break;
                }
            }

            if (! $current instanceof QueryException && ! $current instanceof SessionPdoException) {
                return false;
            }

            $session = self::sessionOf($current);

            if ($session !== $mirror) {
                return false;
            }

            $driver = $sessions->sessionDriver((string) $session);
        } catch (Throwable) {
            return false;
        }

        $number = self::driverErrorNumber($current);

        return match ($driver) {
            'mysql', 'mariadb' => $number === 1205,
            'pgsql' => self::sqlState($current) === '55P03',
            'sqlite' => $number !== null && in_array($number & 0xFF, [5, 6], true),
            default => false,
        };
    }

    /**
     * The package session (or connection) name that an error of a statement
     * names, without a `::read` or `::write` suffix; null for an error that
     * names none.
     */
    public static function sessionOf(Throwable $error): ?string
    {
        $name = match (true) {
            $error instanceof QueryException => $error->connectionName,
            $error instanceof SessionPdoException => $error->session,
            default => null,
        };

        return $name === null ? null : explode('::', $name)[0];
    }

    /**
     * A MySQL client connection error (MYSQL_CONNECTION_ERRORS) of a MySQL
     * or MariaDB session. mysqlnd reports them with SQLSTATE HY000, and some
     * texts are not in Laravel's list, for example the connect timeout of
     * macOS (`SQLSTATE[HY000] [2002] Operation timed out`). The number is
     * errorInfo[1], else the `[nnnn]` after the SQLSTATE of a connect
     * failure's message. Another driver's numbers mean something else.
     */
    private static function isMySqlConnectionError(string $session, QueryException|SessionPdoException $error): bool
    {
        if (! in_array(self::driverErrorNumber($error), self::MYSQL_CONNECTION_ERRORS, true)) {
            return false;
        }

        try {
            $driver = Container::getInstance()->make(PackageSession::class)->sessionDriver($session);
        } catch (Throwable) {
            // Nothing may escape the classification (Watcher::failed()).
            return false;
        }

        return $driver === 'mysql' || $driver === 'mariadb';
    }

    private static function driverErrorNumber(QueryException|SessionPdoException $error): ?int
    {
        // A QueryException carries the driver's PDOException as its previous.
        $driver = $error instanceof QueryException ? $error->getPrevious() : $error;

        foreach ([$error, $driver] as $candidate) {
            $number = $candidate instanceof PDOException ? ($candidate->errorInfo[1] ?? null) : null;

            if (is_int($number) || (is_string($number) && ctype_digit($number))) {
                return (int) $number;
            }
        }

        if (preg_match('/^SQLSTATE\[[0-9A-Z]{5}\] \[(\d+)\]/', ($driver ?? $error)->getMessage(), $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private static function sqlState(QueryException|SessionPdoException $error): string
    {
        // A QueryException carries the driver's PDOException as its previous.
        $driver = $error instanceof QueryException ? $error->getPrevious() : $error;
        $state = $error instanceof QueryException ? ($error->errorInfo[0] ?? null) : null;

        if (! is_string($state) || $state === '') {
            $state = $driver instanceof PDOException ? ($driver->errorInfo[0] ?? $driver->getCode()) : $error->getCode();
        }

        $state = is_scalar($state) ? (string) $state : '';

        if (strlen($state) !== 5 && preg_match('/^SQLSTATE\[([0-9A-Z]{5})\]/', ($driver ?? $error)->getMessage(), $match) === 1) {
            return $match[1];
        }

        return $state;
    }

    /**
     * Laravel's own detector, through the trait that every supported release
     * has (it uses a bound detector where the release has one; one that
     * throws gives false). The
     * detector has no state, so each call builds its own (no static cache);
     * this runs only for a failed statement.
     */
    private static function causedByLostConnection(Throwable $error): bool
    {
        $detector = new class
        {
            use DetectsLostConnections;

            public function check(Throwable $error): bool
            {
                return (bool) $this->causedByLostConnection($error);
            }
        };

        try {
            return $detector->check($error);
        } catch (Throwable) {
            // An application's detector that throws: no lost connection known, and nothing escapes into the host.
            return false;
        }
    }
}
