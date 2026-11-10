<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Queue\TimeoutExceededException;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Storage\PostgresSession;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PDOException;
use RuntimeException;

/**
 * A job timeout whose alarm arrives while a package session waits in the
 * begin of a package transaction: the handler runs when the
 * begin returns, so the PDO is inside a transaction while Laravel's level is
 * still 0 and run() has not marked the PDO as held. The JobTimedOut listener
 * calls abandon() (Worker::$killOnTimeout true); with $killOnTimeout false the
 * handler throws TimeoutExceededException out of the begin.
 */
final class InterruptedBeginTest extends TestCase
{
    /**
     * Rejects: an abandon() that decides only by the held mark and the level,
     * so it leaves the transaction that the interrupted BEGIN IMMEDIATE opened,
     * and the listener's writes join it (the kill discards them). Would still
     * pass: an abandon() that also rolls back a transaction that the
     * application opened on the same PDO before run() (not a package one).
     */
    public function test_sqlite_abandon_right_after_the_begin_rolls_it_back(): void
    {
        [$pdo, $session] = $this->sqliteSession();
        $transactions = $this->app->make(PackageTransaction::class);
        $abandoned = null;
        $open = null;
        $pdo->afterBegin = static function () use ($transactions, $session, $pdo, &$abandoned, &$open): void {
            $abandoned = $transactions->abandon($session);
            $open = self::sqliteInTransaction($pdo);
        };

        try {
            $transactions->run($session, static function (): never {
                throw new RuntimeException('the process is killed');
            });
        } catch (RuntimeException) {
        }

        self::assertTrue($abandoned, 'abandon() left the transaction of the interrupted begin');
        self::assertFalse($open, 'the listener would write inside the interrupted transaction');
    }

    /**
     * Rejects: a TimeoutExceededException out of the begin (Worker::$killOnTimeout
     * false) that leaves the SQLite PDO inside the transaction, so the worker
     * holds the write lock for the rest of its life. Would still pass: a
     * ROLLBACK after any non-busy error of the begin (the code sends it only
     * when the BEGIN itself did not fail).
     */
    public function test_sqlite_timeout_out_of_the_begin_leaves_no_transaction(): void
    {
        [$pdo, $session] = $this->sqliteSession();
        $pdo->afterBegin = static function (): never {
            throw new TimeoutExceededException('the job timed out');
        };

        try {
            $this->app->make(PackageTransaction::class)->run($session, static fn (): int => 1);
            self::fail('the timeout did not surface');
        } catch (TimeoutExceededException) {
        }

        self::assertFalse(self::sqliteInTransaction($pdo), 'the PDO stayed inside the transaction of the interrupted begin');
        self::assertFalse($this->app->make(PackageTransaction::class)->active($session));
    }

    /**
     * Rejects: an abandon() on a server package session that returns at level
     * 0, so the transaction that the interrupted begin opened stays and the
     * listener's writes join it.
     */
    public function test_server_abandon_right_after_the_begin_rolls_it_back(): void
    {
        [$pdo, $session] = $this->serverMirror();
        $transactions = $this->app->make(PackageTransaction::class);
        $abandoned = null;
        $open = null;
        $pdo->afterBegin = static function () use ($transactions, $session, &$abandoned, &$open): void {
            $abandoned = $transactions->abandon($session);
            $raw = $session->getRawPdo();
            $open = $raw instanceof PDO && $raw->inTransaction();
        };

        try {
            $transactions->run($session, static function (): never {
                throw new RuntimeException('the process is killed');
            });
        } catch (RuntimeException) {
        }

        self::assertTrue($abandoned, 'abandon() left the transaction of the interrupted begin');
        self::assertFalse($open, 'the listener would write inside the interrupted transaction');
    }

    /**
     * Rejects: a TimeoutExceededException out of the begin of a server package
     * session that rolls back to level 0 (nothing at level 0) and keeps the
     * cached session inside the transaction: later autocommit writes never
     * commit, and its row locks stay.
     */
    public function test_server_timeout_out_of_the_begin_leaves_no_transaction(): void
    {
        [$pdo, $session] = $this->serverMirror();
        $pdo->afterBegin = static function (): never {
            throw new TimeoutExceededException('the job timed out');
        };

        try {
            $this->app->make(PackageTransaction::class)->run($session, static fn (): int => 1);
            self::fail('the timeout did not surface');
        } catch (TimeoutExceededException) {
        }

        $raw = $session->getRawPdo();
        self::assertFalse($raw instanceof PDO && $raw->inTransaction(), 'the session stayed inside the transaction of the interrupted begin');
    }

    /**
     * Rejects: an abandon() that ends a transaction of the application: a SQLite PDO that
     * run() does not hold, inside Laravel's transaction or a raw BEGIN, and a server connection that is not a
     * package session, with its PDO in a transaction at Laravel's level 0, stay inside their transactions.
     * Would still pass: an abandon() that ends a package session's transaction that the application opened on
     * it (no such transaction exists).
     */
    public function test_abandon_leaves_a_host_transaction_alone(): void
    {
        $transactions = $this->app->make(PackageTransaction::class);

        [$pdo, $session] = $this->sqliteSession();
        $session->beginTransaction();
        self::assertFalse($transactions->abandon($session));
        self::assertSame(1, $session->transactionLevel());
        self::assertTrue(self::sqliteInTransaction($pdo), 'abandon() ended the application\'s transaction');
        $session->rollBack();

        $pdo->exec('BEGIN');
        self::assertFalse($transactions->abandon($session));
        self::assertTrue(self::sqliteInTransaction($pdo), 'abandon() ended the application\'s raw transaction');
        $pdo->exec('ROLLBACK');

        $application = $this->app['db']->connection();

        if (! in_array($application->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $raw = $application->getPdo();
        $raw->beginTransaction();

        try {
            self::assertFalse($transactions->abandon($application));
            self::assertTrue($raw->inTransaction(), 'abandon() ended a transaction of the application\'s own connection');
        } finally {
            $raw->rollBack();
        }
    }

    /**
     * Rejects: a PackageSession::bounded() whose begin is outside its try, so
     * a TimeoutExceededException out of the pooled begin keeps the session
     * inside the transaction.
     */
    public function test_pooled_bounded_timeout_out_of_the_begin_leaves_no_transaction(): void
    {
        [$pdo, $session] = $this->serverMirror();

        if (! $session instanceof PostgresSession || ! $session->isPooled()) {
            self::markTestSkipped('PackageSession::bounded() begins a transaction only on a pooled Postgres mirror (ORRERY_TEST_PG_POOLED=1).');
        }

        $pdo->afterBegin = static function (): never {
            throw new TimeoutExceededException('the job timed out');
        };

        try {
            $this->app->make(PackageSession::class)->bounded($session, static fn (Connection $session): mixed => $session->selectOne('select 1'));
            self::fail('the timeout did not surface');
        } catch (TimeoutExceededException) {
        }

        $raw = $session->getRawPdo();
        self::assertFalse($raw instanceof PDO && $raw->inTransaction(), 'the session stayed inside the transaction of the interrupted begin');
    }

    /**
     * A SQLite connection of its own with an interrupting PDO, as run() sees a
     * package session of a SQLite file.
     *
     * @return array{0: PDO, 1: SQLiteConnection}
     */
    private function sqliteSession(): array
    {
        $pdo = self::interruptingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return [$pdo, new SQLiteConnection($pdo, ':memory:', '', ['driver' => 'sqlite', 'name' => 'interrupted'])];
    }

    /**
     * The mirror's package session on a server engine, rebuilt on an
     * interrupting PDO through the connect seam.
     *
     * @return array{0: PDO, 1: Connection}
     */
    private function serverMirror(): array
    {
        $sessions = $this->app->make(PackageSession::class);
        $mirror = $sessions->mirror();

        if (! in_array($mirror->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            self::markTestSkipped('The server begin needs a MySQL, MariaDB or Postgres mirror.');
        }

        $username = $mirror->getConfig('username');
        $password = $mirror->getConfig('password');
        $built = null;
        PackageSession::useConnectAttemptForTesting(static function (string $dsn, array $options) use ($username, $password, &$built): PDO {
            return $built = self::interruptingPdo($dsn, is_string($username) ? $username : null, is_string($password) ? $password : null, $options);
        });
        $sessions->disconnect($mirror);
        $session = $sessions->mirror();
        $session->getPdo();
        PackageSession::useConnectAttemptForTesting(null);
        self::assertInstanceOf(PDO::class, $built);

        return [$built, $session];
    }

    /**
     * A PDO that runs its `afterBegin` callback once, right after its next
     * begin returned (PDO::beginTransaction() or an exec() that starts with
     * BEGIN): there a job timeout's alarm handler runs when it arrives while
     * the session waits in the begin.
     *
     * @param  array<int, mixed>  $options
     */
    private static function interruptingPdo(string $dsn, ?string $username = null, ?string $password = null, array $options = []): PDO
    {
        return new class($dsn, $username, $password, $options) extends PDO
        {
            public ?Closure $afterBegin = null;

            public function beginTransaction(): bool
            {
                $result = parent::beginTransaction();
                $this->interrupt();

                return $result;
            }

            public function exec(string $statement): int|false
            {
                $result = parent::exec($statement);

                if (preg_match('/^\s*begin\b/i', $statement) === 1) {
                    $this->interrupt();
                }

                return $result;
            }

            private function interrupt(): void
            {
                $callback = $this->afterBegin;
                $this->afterBegin = null;

                if ($callback !== null) {
                    $callback();
                }
            }
        };
    }

    /**
     * Whether a SQLite PDO is inside a transaction, on every PHP version (PHP
     * 8.3 does not report a BEGIN IMMEDIATE through PDO::inTransaction()).
     */
    private static function sqliteInTransaction(PDO $pdo): bool
    {
        try {
            $pdo->exec('BEGIN');
        } catch (PDOException) {
            return true;
        }

        $pdo->exec('ROLLBACK');

        return false;
    }
}
