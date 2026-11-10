<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use Illuminate\Database\Connection;
use LogicException;
use PDO;
use RuntimeException;

/**
 * Ends the server session of a connection from a second connection, as a
 * server timeout, a failover or an administrator does (no
 * transparent reconnect). MySQL and Postgres only: SQLite has no server
 * session.
 *
 * The victim keeps its PDO object. Its next statement fails with a lost
 * connection, and then Laravel's reconnect path decides what happens.
 */
trait KillsServerSessions
{
    /** Seconds to wait until the server no longer lists the ended session. */
    private static int $endSessionDeadline = 10;

    protected function endServerSession(Connection $victim, Connection $killer): void
    {
        $pdo = $victim->getPdo();

        if (! $pdo instanceof PDO) {
            throw new LogicException('The victim has no open PDO.');
        }

        // The raw PDO, so that no Laravel callback or event runs on the victim.
        match ($victim->getDriverName()) {
            'mysql', 'mariadb' => $this->killMysql((int) $pdo->query('select connection_id()')->fetchColumn(), $killer),
            'pgsql' => $this->terminatePostgres((int) $pdo->query('select pg_backend_pid()')->fetchColumn(), $killer),
            default => throw new LogicException('Only MySQL and Postgres have server sessions to end.'),
        };
    }

    private function killMysql(int $id, Connection $killer): void
    {
        $killer->statement('kill '.$id);

        $this->waitUntilGone(static fn (): int => (int) $killer->scalar('select count(*) from information_schema.processlist where id = ?', [$id]), "MySQL session {$id}");
    }

    private function terminatePostgres(int $pid, Connection $killer): void
    {
        $killer->scalar('select pg_terminate_backend(?)', [$pid]);

        $this->waitUntilGone(static fn (): int => (int) $killer->scalar('select count(*) from pg_stat_activity where pid = ?', [$pid]), "Postgres backend {$pid}");
    }

    /**
     * @param  \Closure(): int  $count
     */
    private function waitUntilGone(\Closure $count, string $what): void
    {
        $deadline = microtime(true) + self::$endSessionDeadline;

        while ($count() > 0) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("{$what} still exists ".self::$endSessionDeadline.' s after it was ended.');
            }

            usleep(20_000);
        }
    }
}
