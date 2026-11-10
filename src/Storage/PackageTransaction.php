<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Runtime\Hooks;
use PDO;
use PDOException;
use Throwable;
use WeakMap;

/**
 * One package transaction on a package session.
 *
 * Laravel repeats a statement after a lost connection only at transaction
 * level 0. So every conditional mirror write whose row count decides a
 * result runs through run().
 *
 * - SQLite: BEGIN IMMEDIATE through the session's PDO, then $work, then
 *   COMMIT, or ROLLBACK after a throw. SQLITE_BUSY at any point retries the
 *   whole transaction, so $work runs again with a fresh read. After 3
 *   attempts, run() throws PackageTransactionBusy. These direct PDO calls fire
 *   no Laravel transaction event, so run() counts its own BEGIN IMMEDIATE,
 *   COMMIT and ROLLBACK statements.
 * - SQLite inside an open transaction (a caller's run(), or the application's
 *   transaction on the shared in-memory connection of the tests): a
 *   SAVEPOINT orrery_<depth>, with RELEASE, or ROLLBACK TO after a throw.
 * - MySQL and Postgres: Laravel's beginTransaction(), $work, commit(), and
 *   rollBack() after a throw, also a throw of beginTransaction() itself or of
 *   commit(). When the rollback fails, the session is disconnected, so it
 *   never stays inside the transaction. No retry.
 * - Every raw statement goes through exec(), so a PDO in silent error mode
 *   never turns a failed BEGIN IMMEDIATE or COMMIT into a success.
 * - Every round trip reaches the test recorder
 *   (PackageSession::recordForTesting()): the raw SQLite statements here,
 *   and the server begins, commits and rollbacks through the session class.
 *
 * PHP 8.3 does not report a SQLite BEGIN IMMEDIATE through
 * PDO::inTransaction() (PHP 8.4 does). So run() keeps its own record of the
 * PDOs that it holds in a transaction, and active() reads that record too.
 */
final class PackageTransaction
{
    public const MAX_ATTEMPTS = 3;

    public const BUSY_HOOK = 'package_transaction.busy';

    /** @var array{begin: int, commit: int, rollback: int} */
    private array $counters = ['begin' => 0, 'commit' => 0, 'rollback' => 0];

    /** @var WeakMap<PDO, int>|null the package levels (the transaction and its savepoints) that run() holds per PDO */
    private ?WeakMap $held = null;

    /**
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     *
     * @throws PackageTransactionBusy
     */
    public function run(Connection $session, callable $work): mixed
    {
        if ($session->getDriverName() === 'sqlite') {
            return $this->runSqlite($session, $work);
        }

        return $this->runServer($session, $work);
    }

    /**
     * Whether the session's PDO is inside a transaction: a package
     * transaction of run(), Laravel's transaction, or one that PDO reports.
     */
    public function active(Connection $session): bool
    {
        return self::isOpen($session);
    }

    /**
     * Ends a package transaction that the code which opened it can no longer
     * finish: Laravel's job-timeout alarm runs its JobFailed and
     * JobTimedOut listeners at any point, also inside run(), and then kills
     * the process, so the server discards that transaction and every write
     * that joined it. The listeners call this first, so their own writes run
     * in autocommit. Returns true when it rolled a transaction back.
     *
     * Only a package transaction: on SQLite one that run() began (the PDO is
     * held while Laravel's level is 0, from just before its BEGIN IMMEDIATE,
     * so an alarm that arrives while the begin waits finds it; a savepoint
     * inside the application's own transaction of the in-memory test
     * connection is left alone), on a server a transaction of a package
     * session, also one whose begin returned before Laravel raised the level
     * (the PDO is in a transaction at level 0). The interrupted run() then
     * meets the closed transaction and throws, which the watcher counts.
     */
    public function abandon(Connection $session): bool
    {
        try {
            $pdo = $session->getRawPdo();

            if ($session->getDriverName() === 'sqlite') {
                if (! $pdo instanceof PDO || $session->transactionLevel() > 0 || ! isset(self::held()[$pdo])) {
                    return false;
                }

                $this->rollBackSqlite($session, $pdo);
                unset(self::held()[$pdo]);

                return true;
            }

            if (! PackageSession::isPackageSession($session->getName())) {
                return false;
            }

            if ($session->transactionLevel() === 0) {
                if (! self::rawInTransaction($session)) {
                    return false;
                }

                // The begin returned, and Laravel has not raised the level yet.
                $this->endRawTransaction($session);

                return true;
            }

            $this->rollBackServer($session, 0);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{begin: int, commit: int, rollback: int}
     */
    public static function sqliteCounters(): array
    {
        return self::bound()->counters;
    }

    public static function resetCounters(): void
    {
        self::bound()->counters = ['begin' => 0, 'commit' => 0, 'rollback' => 0];
    }

    /**
     * The rule of active(), for the classes that have no instance.
     */
    public static function isOpen(Connection $session): bool
    {
        if ($session->transactionLevel() > 0) {
            return true;
        }

        $pdo = $session->getRawPdo();

        return $pdo instanceof PDO && (isset(self::held()[$pdo]) || $pdo->inTransaction());
    }

    /**
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function runSqlite(Connection $session, callable $work): mixed
    {
        $pdo = $session->getPdo();

        if (self::isOpen($session)) {
            return $this->runSavepoint($session, $pdo, $work);
        }

        for ($attempt = 1; ; $attempt++) {
            // Held before the begin: an alarm that arrives while the begin
            // waits runs when it returns, and abandon() must find the PDO.
            self::held()[$pdo] = 1;

            try {
                $this->counters['begin']++;
                self::exec($session, $pdo, PackageSession::BEGIN, 'BEGIN IMMEDIATE');
            } catch (Throwable $error) {
                unset(self::held()[$pdo]);

                if (! self::isBusy($error)) {
                    if (! $error instanceof PDOException) {
                        // The BEGIN returned, and an alarm threw after it
                        // (Worker::$killOnTimeout false): never leave the PDO
                        // inside the transaction, which holds the write lock.
                        $this->rollBackSqlite($session, $pdo);
                    }

                    throw $error;
                }

                $this->afterBusy($session, $attempt, $error);

                continue;
            }

            try {
                $result = $work($session);
                $this->counters['commit']++;
                self::exec($session, $pdo, PackageSession::COMMIT, 'COMMIT');
            } catch (Throwable $error) {
                $this->rollBackSqlite($session, $pdo);
                unset(self::held()[$pdo]);

                if (! self::isBusy($error)) {
                    throw $error;
                }

                $this->afterBusy($session, $attempt, $error);

                continue;
            }

            unset(self::held()[$pdo]);

            return $result;
        }
    }

    /**
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function runSavepoint(Connection $session, PDO $pdo, callable $work): mixed
    {
        $outer = self::held()[$pdo] ?? 0;
        $savepoint = 'orrery_'.($outer + 1);

        self::exec($session, $pdo, PackageSession::BEGIN, "SAVEPOINT {$savepoint}");
        self::held()[$pdo] = $outer + 1;

        try {
            $result = $work($session);
        } catch (Throwable $error) {
            try {
                self::exec($session, $pdo, PackageSession::ROLLBACK, "ROLLBACK TO SAVEPOINT {$savepoint}");
                self::exec($session, $pdo, PackageSession::COMMIT, "RELEASE SAVEPOINT {$savepoint}");
            } catch (Throwable) {
                // The savepoint is gone (SQLite ended the transaction, or the work did): the
                // caller gets the work's error, which names the cause (a busy one is retried).
            } finally {
                $this->restoreLevel($pdo, $outer);
            }

            throw $error;
        }

        try {
            self::exec($session, $pdo, PackageSession::COMMIT, "RELEASE SAVEPOINT {$savepoint}");
        } finally {
            $this->restoreLevel($pdo, $outer);
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(Connection): T  $work
     * @return T
     */
    private function runServer(Connection $session, callable $work): mixed
    {
        $before = $session->transactionLevel();

        try {
            // Laravel begins the transaction and raises the level before it
            // fires TransactionBeginning. A throwing listener must not leave
            // the cached session inside that transaction.
            $session->beginTransaction();
            $result = $work($session);
        } catch (Throwable $error) {
            $this->rollBackServer($session, $before);

            throw $error;
        }

        try {
            $session->commit();
        } catch (Throwable $error) {
            // Laravel keeps the level after a failed commit. Roll back, so a
            // cached session never stays inside a transaction.
            $this->rollBackServer($session, $before);

            throw $error;
        }

        return $result;
    }

    private function rollBackServer(Connection $session, int $before): void
    {
        try {
            $session->rollBack($before);
        } catch (Throwable) {
            // The rollback failed too (for example a lost connection). The
            // caller gets the first error.
        }

        if ($before === 0 && ($session->transactionLevel() > 0 || self::rawInTransaction($session))) {
            // Also a begin that returned before Laravel raised the level (an
            // alarm's exception right after it): the PDO is in a transaction at level 0.
            // Closing the session ends the server transaction; the next
            // PackageSession::for() builds a new one at level 0.
            if (PackageSession::isPackageSession($session->getName())) {
                Container::getInstance()->make(PackageSession::class)->disconnect($session);
            } else {
                $session->disconnect();
            }
        }
    }

    /**
     * Whether the session's PDO, already connected, reports an open
     * transaction (a server PDO does, whatever Laravel's level says).
     */
    public static function rawInTransaction(Connection $session): bool
    {
        $pdo = $session->getRawPdo();

        try {
            return $pdo instanceof PDO && $pdo->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ends a server transaction that Laravel does not count (level 0): a
     * rollback on the PDO, else the session is closed, which ends it too.
     */
    private function endRawTransaction(Connection $session): void
    {
        try {
            $pdo = $session->getRawPdo();

            if ($pdo instanceof PDO && $pdo->rollBack()) {
                return;
            }
        } catch (Throwable) {
            // Closed below.
        }

        Container::getInstance()->make(PackageSession::class)->disconnect($session);
    }

    private function rollBackSqlite(Connection $session, PDO $pdo): void
    {
        $this->counters['rollback']++;

        try {
            self::exec($session, $pdo, PackageSession::ROLLBACK, 'ROLLBACK');
        } catch (Throwable) {
            // SQLite already ended the transaction (some errors roll back on
            // their own). There is nothing left to roll back.
        }
    }

    private function restoreLevel(PDO $pdo, int $outer): void
    {
        if ($outer === 0) {
            unset(self::held()[$pdo]);
        } else {
            self::held()[$pdo] = $outer;
        }
    }

    private function afterBusy(Connection $session, int $attempt, Throwable $error): void
    {
        Hooks::reach(self::BUSY_HOOK);

        if ($attempt >= self::MAX_ATTEMPTS) {
            $busy = new PackageTransactionBusy(
                sprintf('The SQLite package transaction got SQLITE_BUSY in each of its %d attempts: %s', self::MAX_ATTEMPTS, $error->getMessage()),
                0,
                $error,
            );
            // The watcher counts a busy mirror (ErrorKinds::isMirrorTimeout()).
            $busy->session = $session->getName();

            throw $busy;
        }
    }

    /**
     * SQLITE_BUSY: result code 5 (with its extended codes), or the message of
     * an error that is not a QueryException.
     */
    private static function isBusy(Throwable $error): bool
    {
        for ($current = $error, $depth = 0; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof PDOException && is_array($current->errorInfo) && (((int) ($current->errorInfo[1] ?? 0)) & 0xFF) === 5) {
                return true;
            }

            // Never a QueryException's own message: it holds the SQL with its
            // bindings, so a job's text could read as busy.
            if (! $current instanceof QueryException && str_contains($current->getMessage(), 'database is locked')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One raw statement, reported to the test recorder first. A PDO in silent
     * error mode returns false where exception mode throws: the false result
     * becomes a PDOException with the driver's error information, so a failed
     * BEGIN IMMEDIATE or COMMIT never passes as a success.
     */
    private static function exec(Connection $session, PDO $pdo, string $kind, string $sql): void
    {
        if (method_exists($session, 'recordRoundTrip')) {
            $session->recordRoundTrip($kind, $sql, $session->getName());
        } else {
            PackageSession::record($kind, $sql, $session->getName());
        }

        if ($pdo->exec($sql) !== false) {
            return;
        }

        $info = $pdo->errorInfo();
        $error = new PDOException(sprintf(
            'SQLSTATE[%s]: %s (%s)',
            (string) ($info[0] ?? 'HY000'),
            (string) ($info[2] ?? 'the statement failed without an error message'),
            $sql,
        ));
        $error->errorInfo = $info;

        throw $error;
    }

    /**
     * @return WeakMap<PDO, int>
     */
    private static function held(): WeakMap
    {
        $state = self::bound();

        if ($state->held === null) {
            /** @var WeakMap<PDO, int> $held */
            $held = new WeakMap;
            $state->held = $held;
        }

        return $state->held;
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
