<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Storage;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MySqlConnector as LaravelMySqlConnector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Retry\OperationRunner;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Runtime\WatchResult;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Storage\PackageTransactionBusy;
use Justrau\Orrery\Storage\PooledSessionRefused;
use Justrau\Orrery\Storage\PostgresSession;
use Justrau\Orrery\Storage\SessionPdoException;
use Justrau\Orrery\Tests\Support\ServerStandInPdo;
use Justrau\Orrery\Tests\TestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The connect bound of a package session: one unreachable server costs at
 * most one connect timeout (PackageSession::CONNECT_TIMEOUT_SECONDS) per
 * statement, on every OS.
 *
 * Laravel tries a connect again when its lost-connection detector knows the
 * failure: Connector::createConnection() connects a second time, and
 * Connection::tryAgainIfCausedByLostConnection() reconnects, which connects
 * twice more. On Linux, mysqlnd reports a connect timeout as
 * `SQLSTATE[HY000] [2002] Connection timed out`, which the detector knows,
 * so a statement waited up to four connect timeouts there (macOS says
 * "Operation timed out", which it does not know). These tests reproduce the
 * Linux failure on every OS: the connect seam of the package's connectors
 * (PackageSession::useConnectAttemptForTesting()) throws the exact Linux
 * text, and each attempt lets the connect timeout of its PDO options pass on
 * the frozen test clock. No test reaches a server.
 */
final class ConnectAttemptsTest extends TestCase
{
    /** mysqlnd's text for a connect timeout on Linux. */
    private const MYSQL_LINUX_TIMEOUT = 'SQLSTATE[HY000] [2002] Connection timed out';

    /** mysqlnd's text for a connect timeout on macOS: neither class 08 nor a known lost-connection text. */
    private const MYSQL_MACOS_TIMEOUT = 'SQLSTATE[HY000] [2002] Operation timed out';

    /** libpq's text for a name lookup that failed after its own wait (also in Laravel's list). */
    private const POSTGRES_LOOKUP_FAILURE = 'SQLSTATE[08006] [7] could not translate host name "db.orrery.invalid" to address: Temporary failure in name resolution';

    /** libpq's text for a connect timeout (connect_timeout): SQLSTATE class 08, which Laravel's detector does not know. */
    private const POSTGRES_CONNECT_TIMEOUT = 'SQLSTATE[08006] [7] connection to server at "192.0.2.1", port 5432 failed: timeout expired';

    private const CONNECTION = 'orrery_unreachable';

    private const QUEUE = 'orrery_unreachable_queue';

    private int $attempts = 0;

    /**
     * @return array<string, array{string, string}>
     */
    public static function unreachableSessions(): array
    {
        return [
            'mysql mirror' => ['mysql', 'mirror'],
            'mysql queue connection' => ['mysql', 'queue'],
            'mysql bounded unit' => ['mysql', 'bounded'],
            'mysql package transaction' => ['mysql', 'transaction'],
            'mariadb queue connection' => ['mariadb', 'queue'],
            'pgsql mirror' => ['pgsql', 'mirror'],
            'pgsql queue connection' => ['pgsql', 'queue'],
            'pgsql bounded unit' => ['pgsql', 'bounded'],
            'pgsql package transaction' => ['pgsql', 'transaction'],
            'pooled pgsql queue connection' => ['pgsql-pooled', 'queue'],
        ];
    }

    /**
     * Rejects: Laravel's second connect in Connector::createConnection() for a
     * known failure (two attempts), and the reconnect of
     * Connection::tryAgainIfCausedByLostConnection() or
     * handleBeginTransactionException() after a connect that failed in the
     * same statement (up to four attempts, 20 s). Still passes: a session
     * that connects once per statement but tries again in each later
     * statement of one operation (the operations stop at their first
     * failure).
     */
    #[DataProvider('unreachableSessions')]
    public function test_an_unreachable_server_costs_one_connect_timeout(string $driver, string $access): void
    {
        $failure = $driver === 'mysql' || $driver === 'mariadb' ? self::MYSQL_LINUX_TIMEOUT : self::POSTGRES_LOOKUP_FAILURE;
        $this->unreachable($driver);
        $this->connectAttempts(static fn (): PDO => throw new PDOException($failure, 2002));
        $started = Clock::monotonic();

        self::assertStringContainsString($failure, $this->failureOf(fn () => $this->operation($access))->getMessage(), 'The caller gets the connect failure.');

        self::assertSame(1, $this->attempts, 'One connect attempt per statement.');
        self::assertSame((float) PackageSession::CONNECT_TIMEOUT_SECONDS, Clock::monotonic() - $started, 'One connect timeout, not one per attempt.');
    }

    /**
     * Rejects: a guard that never reconnects (the package session would stay
     * dead after a dropped connection until the process ends). Still passes:
     * a reconnect that connects twice when its first connect succeeds.
     */
    public function test_a_dropped_connection_still_reconnects(): void
    {
        $this->unreachable('mysql');
        /** @var list<ServerStandInPdo> $opened */
        $opened = [];
        $this->connectAttempts(static function () use (&$opened): PDO {
            return $opened[] = new ServerStandInPdo;
        });
        $session = $this->sessions()->for(self::CONNECTION);

        self::assertSame(1, (int) $session->scalar('select 1'));
        $opened[0]->loseConnection();

        self::assertSame(2, (int) $session->scalar('select 2'), 'The statement ran again on a new connection.');
        self::assertSame(2, $this->attempts);
        self::assertSame($opened[1], $session->getPdo());
    }

    /**
     * Rejects: a begin that never reconnects after a connection that dropped
     * after its connect (handleBeginTransactionException() that always
     * rethrows): the package transaction fails until the process ends.
     * Still passes: a begin that reconnects twice.
     */
    public function test_a_begin_reconnects_after_a_dropped_connection(): void
    {
        $this->unreachable('mysql');
        /** @var list<ServerStandInPdo> $opened */
        $opened = [];
        $this->connectAttempts(static function () use (&$opened): PDO {
            return $opened[] = new ServerStandInPdo;
        });
        $session = $this->sessions()->for(self::CONNECTION);
        $session->scalar('select 1');
        $opened[0]->loseConnection();

        $value = $this->app->make(PackageTransaction::class)->run($session, static fn (Connection $connection): mixed => $connection->scalar('select 3'));

        self::assertSame(3, (int) $value);
        self::assertSame(2, $this->attempts);
        self::assertSame($opened[1], $session->getPdo());
        self::assertSame(0, $session->transactionLevel());
    }

    /**
     * Rejects: a pooled Postgres begin that does not reconnect after a
     * connection that dropped after its connect. Still passes: a pooled
     * begin that reconnects twice.
     */
    public function test_a_pooled_begin_reconnects_after_a_dropped_connection(): void
    {
        $first = new ServerStandInPdo;
        $second = new ServerStandInPdo;
        $session = new PostgresSession($first, 'orrery_probe', '', ['driver' => 'pgsql', 'name' => PackageSession::NAME_PREFIX.self::CONNECTION], true);
        $session->setReconnector(static function (Connection $connection) use ($second): void {
            $connection->setPdo($second);
        });
        $first->loseConnection();

        $session->beginTransaction();

        self::assertSame(1, $session->transactionLevel());
        self::assertSame($second, $session->getPdo());
        self::assertTrue($second->inTransaction());
        $session->rollBack();
    }

    /**
     * Rejects: Laravel's second connect inside the reconnect of a dropped
     * connection (two connect timeouts after the drop). Still passes: a
     * reconnect that is tried again in the next statement.
     */
    public function test_a_failed_reconnect_costs_one_connect_timeout(): void
    {
        $this->unreachable('mysql');
        $first = new ServerStandInPdo;
        $calls = 0;
        $this->connectAttempts(static function () use ($first, &$calls): PDO {
            if (++$calls === 1) {
                return $first;
            }

            throw new PDOException(self::MYSQL_LINUX_TIMEOUT, 2002);
        });
        $session = $this->sessions()->for(self::CONNECTION);
        $session->scalar('select 1');
        $first->loseConnection();
        $started = Clock::monotonic();

        self::assertStringContainsString(self::MYSQL_LINUX_TIMEOUT, $this->failureOf(fn () => $session->scalar('select 2'))->getMessage());

        self::assertSame(2, $this->attempts, 'The first connect, then one reconnect.');
        self::assertSame((float) PackageSession::CONNECT_TIMEOUT_SECONDS, Clock::monotonic() - $started);
    }

    /**
     * The combined begin of a pooled Postgres session reconnects itself. A
     * pooled session connects when it is built, so this test builds one
     * whose PDO is the lazy resolver of a reconnect, as after a dropped
     * connection, with the package's kind of reconnector.
     *
     * Rejects: the reconnect of the pooled begin after a connect that failed
     * in that begin (two connect attempts). Still passes: a pooled begin
     * that never reconnects after a dropped connection (the statements
     * before it reconnect).
     */
    public function test_a_pooled_begin_costs_one_connect_timeout(): void
    {
        $resolver = function (): PDO {
            $this->attempts++;

            throw new PDOException(self::POSTGRES_LOOKUP_FAILURE, 7);
        };
        $session = new PostgresSession($resolver, 'orrery_probe', '', ['driver' => 'pgsql', 'name' => PackageSession::NAME_PREFIX.self::CONNECTION], true);
        $session->setReconnector(static function (Connection $connection) use ($resolver): void {
            $connection->setPdo($resolver);
        });

        self::assertStringContainsString(self::POSTGRES_LOOKUP_FAILURE, $this->failureOf(fn () => $session->beginTransaction())->getMessage());
        self::assertSame(1, $this->attempts);
        self::assertSame(0, $session->transactionLevel());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function rawConnectFailures(): array
    {
        return [
            'begin of a package transaction (lost-connection text)' => ['mysql', 'transaction', self::MYSQL_LINUX_TIMEOUT],
            'pooled Postgres build (SQLSTATE class 08)' => ['pgsql-pooled', 'queue', self::POSTGRES_CONNECT_TIMEOUT],
            'PackageSession::exec() (lost-connection text)' => ['mysql', 'exec', self::MYSQL_LINUX_TIMEOUT],
            'PackageSession::exec() on Postgres (SQLSTATE class 08)' => ['pgsql', 'exec', self::POSTGRES_CONNECT_TIMEOUT],
        ];
    }

    /**
     * A connect failure that reaches the watcher as a PDOException, not as
     * Laravel's QueryException (the begin, the pooled build and the raw
     * exec() take the PDO outside Connection::run()), is a dependency error
     * of its package session, as the same failure inside a statement is.
     *
     * Rejects: ErrorKinds that classifies only a QueryException (the breaker
     * stays closed on an unreachable server), and a SQLSTATE read that takes
     * only errorInfo or the driver code (libpq's connect failure has neither:
     * code 7, no errorInfo; its SQLSTATE is only in the message). Still
     * passes: a dependency rule that also counts other package-session
     * PDOExceptions of class 08.
     */
    #[DataProvider('rawConnectFailures')]
    public function test_a_raw_connect_failure_opens_the_breaker(string $driver, string $access, string $failure): void
    {
        $this->unreachable($driver);
        $this->connectAttempts(static fn (): PDO => throw new PDOException($failure, $driver === 'mysql' ? 2002 : 7));
        $sessions = $this->sessions();

        $result = $this->watchOnMirror('connect_probe', match ($access) {
            'exec' => static fn (): int => $sessions->exec($sessions->for(self::CONNECTION), 'SET @orrery_probe = 1'),
            default => fn () => $this->operation($access),
        });

        self::assertFalse($result->succeeded());
        self::assertInstanceOf(PDOException::class, $result->error(), 'The failure reached the watcher outside a QueryException.');
        self::assertNotInstanceOf(QueryException::class, $result->error());
        self::assertTrue(Watcher::instance()->breakerOpen(), 'An unreachable server opens the breaker.');
    }

    /**
     * libpq's connect timeout inside a statement: Laravel wraps it in a
     * QueryException whose errorInfo is empty and whose code is libpq's 7.
     *
     * Rejects: a SQLSTATE read from errorInfo or the driver code only (the
     * class 08 of the message is missed, and an unreachable Postgres server
     * never opens the breaker). Still passes: a read that takes the message
     * first.
     */
    public function test_a_postgres_connect_timeout_in_a_statement_opens_the_breaker(): void
    {
        $this->unreachable('pgsql');
        $this->connectAttempts(static fn (): PDO => throw new PDOException(self::POSTGRES_CONNECT_TIMEOUT, 7));

        $result = $this->watchOnMirror('connect_probe', fn () => $this->operation('queue'));

        self::assertInstanceOf(QueryException::class, $result->error());
        self::assertTrue(Watcher::instance()->breakerOpen());
    }

    /**
     * A raw exec() on a connection that dropped after its connect: the
     * driver error reaches the watcher as a PDOException.
     *
     * Rejects: a raw exec() whose error does not name its session (the
     * breaker stays closed on a lost connection). Still passes: an exec()
     * that names its session on every error, the data errors included (the
     * next test rejects their classification).
     */
    public function test_a_dropped_connection_in_a_raw_exec_opens_the_breaker(): void
    {
        $this->unreachable('mysql');
        $pdo = new ServerStandInPdo;
        $this->connectAttempts(static fn (): PDO => $pdo);
        $sessions = $this->sessions();
        $session = $sessions->for(self::CONNECTION);
        $sessions->exec($session, 'SET @orrery_probe = 1');
        $pdo->loseConnection();

        $result = $this->watchOnMirror('lost_probe', static fn (): int => $sessions->exec($session, 'SET @orrery_probe = 2'));

        self::assertInstanceOf(PDOException::class, $result->error());
        self::assertStringContainsString('server has gone away', (string) $result->error()?->getMessage());
        self::assertTrue(Watcher::instance()->breakerOpen());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function macOsConnectTimeouts(): array
    {
        return [
            'in a statement (QueryException)' => ['queue'],
            'in a begin (SessionPdoException)' => ['transaction'],
        ];
    }

    /**
     * mysqlnd's connect timeout on macOS: SQLSTATE HY000 (not class 08) and
     * a text that Laravel's lost-connection detector does not know. Its
     * client error number 2002 makes it a dependency error.
     *
     * Rejects: a rule of SQLSTATE and lost-connection texts only (an
     * unreachable MySQL server never opens the breaker on macOS), and a
     * number read only from errorInfo (a connect failure has none; the
     * number is in the message). Still passes: a rule that also reads the
     * driver code.
     */
    #[DataProvider('macOsConnectTimeouts')]
    public function test_a_mysql_client_connect_error_opens_the_breaker(string $access): void
    {
        $this->unreachable('mysql');
        $this->connectAttempts(static fn (): PDO => throw new PDOException(self::MYSQL_MACOS_TIMEOUT, 2002));

        $result = $this->watchOnMirror('connect_probe', fn () => $this->operation($access));

        self::assertStringContainsString(self::MYSQL_MACOS_TIMEOUT, (string) $result->error()?->getMessage());
        self::assertTrue(Watcher::instance()->breakerOpen());
    }

    /**
     * A deadlock on the mirror whose statement binds a job's text: the
     * QueryException's message holds the SQL with its bindings, the driver's
     * message does not.
     *
     * Rejects: Laravel's lost-connection detector on the QueryException's
     * own message (a binding "… No such file or directory" opens the
     * breaker on a deadlock). Still passes: a detector that reads the
     * driver's message only when the SQLSTATE is not 40001.
     */
    public function test_a_lost_connection_text_in_a_binding_keeps_the_breaker_closed(): void
    {
        $result = $this->failedStatement('mysql', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction', ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'], 'fopen(/var/app/x.json): No such file or directory');

        self::assertInstanceOf(QueryException::class, $result->error());
        self::assertStringContainsString('No such file or directory', (string) $result->error()?->getMessage(), 'The binding is in the message.');
        self::assertFalse(ErrorKinds::isDependency($result->error()));
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: OperationRunner::isBlockingFailure() on the QueryException's
     * own message (a duplicate key whose binding holds "Connection timed
     * out" or "[2002]" defers its connection). Still passes: a check that
     * reads only the driver's message and SQLSTATE.
     */
    public function test_a_connect_text_in_a_binding_is_no_blocking_failure(): void
    {
        $result = $this->failedStatement('mysql', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', ['23000', 1062, 'Duplicate entry'], 'SQLSTATE[HY000] [2002] Connection timed out; Connection refused; timeout expired');

        self::assertInstanceOf(QueryException::class, $result->error());
        self::assertFalse(OperationRunner::isBlockingFailure($result->error()));
    }

    /**
     * Rejects: a dependency rule for every MySQL error number (1062, a
     * duplicate key, is a data error). Still passes: a rule that lists more
     * client errors of the 2000 range.
     */
    public function test_a_mysql_duplicate_key_keeps_the_breaker_closed(): void
    {
        $result = $this->failedStatement('mysql', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', ['23000', 1062, 'Duplicate entry']);

        self::assertInstanceOf(QueryException::class, $result->error());
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: the MySQL client error numbers on another driver: on
     * Postgres, errorInfo[1] is libpq's own code, so a 2006 there is no
     * MySQL connection error. Still passes: a rule that reads the driver of
     * the session from another place.
     */
    public function test_a_mysql_client_error_number_on_postgres_keeps_the_breaker_closed(): void
    {
        $result = $this->failedStatement('pgsql', 'SQLSTATE[22P02]: Invalid text representation: 2006 invalid input', ['22P02', 2006, 'invalid input']);

        self::assertInstanceOf(QueryException::class, $result->error());
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: the MySQL client error numbers for an error that names no
     * package session: an application connection never opens the breaker.
     * Still passes: a rule that never reads the number.
     */
    public function test_a_mysql_client_connect_error_of_the_application_keeps_the_breaker_closed(): void
    {
        $raw = Watcher::instance()->attempt('host_probe', static fn () => throw new PDOException(self::MYSQL_MACOS_TIMEOUT, 2002));
        $query = Watcher::instance()->attempt('host_probe', static fn () => throw new QueryException('testbench', 'select 1', [], new PDOException(self::MYSQL_MACOS_TIMEOUT, 2002)));

        self::assertInstanceOf(PDOException::class, $raw->error());
        self::assertInstanceOf(QueryException::class, $query->error());
        self::assertFalse(ErrorKinds::isDependency($raw->error()), 'A raw PDOException names no package session.');
        self::assertFalse(ErrorKinds::isDependency($query->error()), 'An application connection is no package session.');
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * A refused pooled session (a startup option that changes its
     * namespace) is a dependency error of its connection.
     *
     * Rejects: a refused pooled session of a secondary connection that opens
     * the mirror breaker, and a refused pooled mirror that does not. Still
     * passes: a rule that compares the refused connection with the mirror
     * by another name form.
     */
    public function test_a_pooled_refusal_opens_the_breaker_only_for_the_mirror(): void
    {
        $this->unreachable('pgsql-pooled');
        $this->app['config']->set('database.connections.'.self::CONNECTION.'.server_options', ['search_path' => 'tenant']);
        $sessions = $this->sessions();

        $secondary = Watcher::instance()->attempt('pooled_probe', static fn (): Connection => $sessions->for(self::CONNECTION));

        self::assertInstanceOf(PooledSessionRefused::class, $secondary->error());
        self::assertFalse(Watcher::instance()->breakerOpen(), 'A secondary connection keeps the breaker closed.');

        $mirror = $this->watchOnMirror('pooled_probe', static fn (): Connection => $sessions->mirror());

        self::assertInstanceOf(PooledSessionRefused::class, $mirror->error());
        self::assertTrue(Watcher::instance()->breakerOpen(), 'The mirror opens it.');
    }

    /**
     * Rejects: a dependency rule for every PDOException of a package session:
     * a constraint violation of a raw exec() is a data error. Still passes:
     * a rule that never counts a raw PDOException (the test above rejects
     * it).
     */
    public function test_a_raw_data_error_of_a_package_session_keeps_the_breaker_closed(): void
    {
        $this->unreachable('mysql');
        $this->connectAttempts(static fn (): PDO => new ServerStandInPdo);
        $sessions = $this->sessions();
        $session = $sessions->for(self::CONNECTION);
        $sessions->exec($session, 'create table orrery_probe (id integer primary key)');
        $sessions->exec($session, 'insert into orrery_probe (id) values (1)');

        $result = $this->watchOnMirror('data_probe', static fn (): int => $sessions->exec($session, 'insert into orrery_probe (id) values (1)'));

        self::assertInstanceOf(PDOException::class, $result->error());
        self::assertStringContainsString('SQLSTATE[23000]', (string) $result->error()?->getMessage());
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: a dependency rule for every PDOException with a connection
     * text, whatever raised it: an error of an application connection never
     * opens the breaker (ErrorKinds). Still passes: a rule that never counts
     * a raw PDOException.
     */
    public function test_a_pdo_exception_outside_the_package_sessions_keeps_the_breaker_closed(): void
    {
        $result = Watcher::instance()->attempt('host_probe', static fn () => throw new PDOException(self::POSTGRES_CONNECT_TIMEOUT, 7));

        self::assertInstanceOf(PDOException::class, $result->error());
        self::assertFalse(ErrorKinds::isDependency($result->error()));
        self::assertFalse(Watcher::instance()->breakerOpen());
    }

    /**
     * Rejects: a session factory that replaces a connector that the
     * application binds as db.connector.<driver>. Still passes: a bound
     * connector that tries again itself (the application's choice); the
     * session adds no attempt of its own.
     */
    public function test_an_application_connector_binding_is_kept(): void
    {
        $this->unreachable('mysql');
        $this->bindCountingConnector();

        self::assertStringContainsString(self::MYSQL_LINUX_TIMEOUT, $this->failureOf(fn () => $this->sessions()->for(self::CONNECTION)->scalar('select 1'))->getMessage());

        self::assertSame(2, $this->attempts, "The application's connector connects twice (Laravel's own retry); the session does not reconnect.");
    }

    /**
     * Rejects: a fix that changes Laravel's retry for the application's own
     * connections (a bound detector, a global connector binding). Still
     * passes: a package session that ignores the bound connector.
     */
    public function test_the_application_connection_keeps_laravel_retry(): void
    {
        $this->unreachable('mysql');
        $this->bindCountingConnector();

        self::assertStringContainsString(self::MYSQL_LINUX_TIMEOUT, $this->failureOf(fn () => DB::connection(self::CONNECTION)->scalar('select 1'))->getMessage());

        self::assertSame(4, $this->attempts, "Laravel's connector connects twice, and its reconnect twice more.");
    }

    /**
     * @return array<string, array{string, string, array{string, int, string}}>
     */
    public static function mirrorTimeouts(): array
    {
        return [
            'mysql lock wait timeout' => ['mysql', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']],
            'mariadb lock wait timeout' => ['mariadb', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction', ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction']],
            'pgsql lock timeout' => ['pgsql', 'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout', ['55P03', 7, 'ERROR:  canceling statement due to lock timeout']],
            'sqlite busy statement' => ['sqlite', 'SQLSTATE[HY000]: General error: 5 database is locked', ['HY000', 5, 'database is locked']],
            'sqlite busy package transaction' => ['sqlite-transaction', 'SQLSTATE[HY000]: General error: 5 database is locked', ['HY000', 5, 'database is locked']],
        ];
    }

    /**
     * A mirror whose writes are blocked (a REINDEX, an ALTER that waits for a
     * metadata lock) fails each statement after its lock timeout.
     *
     * Rejects: lock timeouts that never open the breaker (each observation
     * then waits the whole timeout), a breaker that opens at the first
     * timeout (one slow statement is no outage), a count that a success
     * between two timeouts resets (a lock on orrery_jobs leaves the
     * heartbeat writable), a count with no time window (two
     * timeouts far apart are no outage), a SQLite busy error or a busy
     * package transaction of the mirror that does not count, and a breaker
     * that lock timeouts open in the shared breaker or for the degradation
     * flush. Still passes: a window of another length than
     * MIRROR_TIMEOUT_WINDOW_SECONDS.
     *
     * @param  array{string, int, string}  $errorInfo
     */
    #[DataProvider('mirrorTimeouts')]
    public function test_two_timeouts_in_a_row_on_the_mirror_open_the_breaker(string $driver, string $message, array $errorInfo): void
    {
        $timeout = static function () use ($message, $errorInfo): PDOException {
            $error = new PDOException($message);
            $error->errorInfo = $errorInfo;

            return $error;
        };
        [$fail, $succeed] = str_starts_with($driver, 'sqlite') ? $this->sqliteMirror($driver, $timeout) : $this->serverMirror($driver, $timeout);

        Watcher::freezeMonotonicForTesting();

        self::assertFalse($this->watchOnMirror('timeout_probe', $fail)->succeeded());
        self::assertFalse(Watcher::instance()->breakerOpen(), 'One timeout opened the breaker.');

        Watcher::advanceMonotonicForTesting(Watcher::MIRROR_TIMEOUT_WINDOW_SECONDS + 1);
        self::assertFalse($this->watchOnMirror('timeout_probe', $fail)->succeeded());
        self::assertFalse(Watcher::instance()->breakerOpen(), 'Two timeouts far apart opened the breaker.');

        Watcher::advanceMonotonicForTesting(5);
        self::assertTrue($this->watchOnMirror('timeout_probe', $succeed)->succeeded());

        self::assertFalse($this->watchOnMirror('timeout_probe', $fail)->succeeded());
        self::assertTrue(Watcher::instance()->breakerOpen(), 'Two timeouts within the window on the mirror kept the breaker closed (a success between them reset the count).');
        self::assertFalse(Watcher::instance()->dependencyBreakerOpen(), 'A breaker of lock timeouts counts as an unreachable mirror.');
    }

    /**
     * @return array<string, array{string, string, array{string, int, string}}>
     */
    public static function statementTimeouts(): array
    {
        return [
            'mysql max_execution_time' => ['mysql', 'SQLSTATE[HY000]: General error: 3024 Query execution was interrupted, maximum statement execution time exceeded', ['HY000', 3024, 'Query execution was interrupted, maximum statement execution time exceeded']],
            'mariadb max_statement_time' => ['mariadb', 'SQLSTATE[70100]: <<Unknown error>>: 1969 Query execution was interrupted (max_statement_time exceeded)', ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)']],
            'pgsql statement_timeout' => ['pgsql', 'SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout', ['57014', 7, 'ERROR:  canceling statement due to statement timeout']],
        ];
    }

    /**
     * A statement timeout ends a slow read (MySQL applies max_execution_time
     * to reads only): slow tick phases or dashboard reads in a row are no
     * outage.
     *
     * Rejects: a timeout rule that counts statement timeouts, so two slow
     * reads stop every observation for the breaker window.
     *
     * @param  array{string, int, string}  $errorInfo
     */
    #[DataProvider('statementTimeouts')]
    public function test_statement_timeouts_keep_the_breaker_closed(string $driver, string $message, array $errorInfo): void
    {
        [$fail] = $this->serverMirror($driver, static function () use ($message, $errorInfo): PDOException {
            $error = new PDOException($message);
            $error->errorInfo = $errorInfo;

            return $error;
        });

        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($this->watchOnMirror('timeout_probe', $fail)->succeeded());
        }

        self::assertFalse(Watcher::instance()->breakerOpen(), 'Statement timeouts opened the breaker.');
    }

    /**
     * A failing and a succeeding statement on a connected server mirror
     * session of $driver (a ServerStandInPdo).
     *
     * @param  Closure(): PDOException  $timeout
     * @return array{Closure(): mixed, Closure(): mixed}
     */
    private function serverMirror(string $driver, Closure $timeout): array
    {
        $session = $this->standInSession($driver);
        $pdo = $session->getPdo();
        self::assertInstanceOf(ServerStandInPdo::class, $pdo);

        return [
            static function () use ($pdo, $session, $timeout): mixed {
                $pdo->failNextStatement($timeout());

                return $session->scalar('select 1');
            },
            static function () use ($pdo, $session): mixed {
                $pdo->recover();

                return $session->scalar('select 1');
            },
        ];
    }

    /**
     * A failing and a succeeding statement on an in-memory SQLite mirror
     * (the session is the configured connection there): a busy statement of
     * the mirror's session, or a package transaction of it that stayed busy
     * ($driver `sqlite-transaction`).
     *
     * @param  Closure(): PDOException  $timeout
     * @return array{Closure(): mixed, Closure(): mixed}
     */
    private function sqliteMirror(string $driver, Closure $timeout): array
    {
        $this->app['config']->set('database.connections.'.self::CONNECTION, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $sessions = $this->sessions();
        $name = $this->onMirror(static fn (): string => $sessions->mirrorSessionName());

        $fail = $driver === 'sqlite-transaction'
            ? static function () use ($timeout): never {
                $busy = new PackageTransactionBusy('The SQLite package transaction got SQLITE_BUSY in each of its 3 attempts.', 0, $timeout());
                $busy->session = self::CONNECTION;

                throw $busy;
            }
        : static fn (): never => throw new QueryException($name, 'select 1', [], $timeout());

        return [$fail, static fn (): mixed => $sessions->mirror()->scalar('select 1')];
    }

    /**
     * Rejects: timeouts of another package session (a secondary queue
     * database) that open the mirror breaker, and a timeout rule that reads
     * the error number of another driver (MySQL's 1205 on Postgres).
     */
    public function test_timeouts_off_the_mirror_keep_the_breaker_closed(): void
    {
        $session = $this->standInSession('mysql');
        $pdo = $session->getPdo();
        self::assertInstanceOf(ServerStandInPdo::class, $pdo);

        for ($i = 0; $i < 3; $i++) {
            $error = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
            $error->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
            $pdo->failNextStatement($error);
            self::assertFalse(Watcher::instance()->attempt('timeout_probe', static fn (): mixed => $session->scalar('select 1'))->succeeded());
        }

        self::assertFalse(Watcher::instance()->breakerOpen(), 'Timeouts of a secondary session opened the mirror breaker.');

        PackageSession::releaseAll();
        $session = $this->standInSession('pgsql');
        $pdo = $session->getPdo();
        self::assertInstanceOf(ServerStandInPdo::class, $pdo);

        for ($i = 0; $i < 3; $i++) {
            $error = new PDOException('SQLSTATE[HY000]: General error: 1205 not a lock timeout on Postgres');
            $error->errorInfo = ['HY000', 1205, 'not a lock timeout on Postgres'];
            $pdo->failNextStatement($error);
            self::assertFalse($this->watchOnMirror('timeout_probe', static fn (): mixed => $session->scalar('select 1'))->succeeded());
        }

        self::assertFalse(Watcher::instance()->breakerOpen(), "MySQL's 1205 on a Postgres session opened the breaker.");
    }

    /**
     * A pooled Postgres mirror connects when its session is built, and the
     * watcher asks for the mirror session before each watched work.
     *
     * Rejects: a watcher that swallows the failed build of the mirror
     * session, so each watched work pays one connect timeout and the
     * breaker never opens (four attempts of one push: four timeouts). Still
     * passes: a watcher that fails the work of the first attempt although
     * that work needs no mirror (the breaker would skip it next anyway).
     */
    public function test_a_failed_build_of_a_pooled_mirror_costs_one_connect_timeout(): void
    {
        $this->unreachable('pgsql-pooled');
        $this->connectAttempts(static fn (): PDO => throw new PDOException(self::POSTGRES_CONNECT_TIMEOUT, 7));
        $calls = 0;

        for ($i = 0; $i < 4; $i++) {
            $this->watchOnMirror('pooled_build_probe', static function () use (&$calls): string {
                $calls++;

                return 'local';
            });
        }

        self::assertSame(1, $this->attempts, 'Each watched work tried to build the unreachable mirror again.');
        self::assertTrue(Watcher::instance()->breakerOpen(), 'The failed build of the mirror kept the breaker closed.');
        self::assertSame(0, $calls, 'The watched work ran while the mirror could not be built.');
    }

    /**
     * Rejects: a pooled mirror whose build fails after the connect with a plain PDOException:
     * the error names no session, so it is no dependency error, the breaker never opens, and every watched
     * work builds the session again (one pooler connection and one failing query each). The build's own
     * statements name the mirror's session, so the first failed build opens the breaker. Would still pass: a
     * wrap that names the session only for the search_path read (the read here fails first).
     */
    public function test_a_pooled_mirror_whose_build_fails_after_the_connect_opens_the_breaker(): void
    {
        $this->unreachable('pgsql-pooled');
        $failure = new PDOException('SQLSTATE[08006] [7] server closed the connection unexpectedly');
        $failure->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];
        $this->connectAttempts(static fn (): PDO => self::failingQueryPdo($failure));
        $calls = 0;

        for ($i = 0; $i < 4; $i++) {
            $this->watchOnMirror('pooled_query_probe', static function () use (&$calls): string {
                $calls++;

                return 'local';
            });
        }

        self::assertSame(1, $this->attempts, 'Each watched work built the pooled mirror again.');
        self::assertTrue(Watcher::instance()->breakerOpen(), 'The failed build of the mirror kept the breaker closed.');
        self::assertSame(0, $calls, 'The watched work ran while the mirror could not be built.');
    }

    /**
     * Rejects: a relation lookup whose raw BEGIN (with its SET LOCAL statements in one simple query) is outside
     * its try, so a SET LOCAL that fails after the BEGIN leaves the pooled session inside the transaction. The
     * failed setup ends with a rollback, and the error names the session. Would still pass: a rollback that
     * the server would refuse inside an aborted transaction (it accepts ROLLBACK there).
     */
    public function test_a_failed_setup_of_the_relation_lookup_leaves_no_transaction(): void
    {
        $this->unreachable('pgsql-pooled');
        $pdo = new class extends PDO
        {
            public function __construct()
            {
                parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                // The server's default search_path.
                return parent::query("select '\"\$user\", public'");
            }

            public function exec(string $statement): int|false
            {
                if (preg_match('/^\s*begin\b/i', $statement) === 1) {
                    // The BEGIN ran; a SET LOCAL after it failed.
                    parent::beginTransaction();
                    $error = new PDOException('SQLSTATE[42704]: Undefined object: role "none" does not exist');
                    $error->errorInfo = ['42704', 7, 'role "none" does not exist'];

                    throw $error;
                }

                if (preg_match('/^\s*rollback\b/i', $statement) === 1) {
                    return parent::rollBack() ? 0 : false;
                }

                return parent::exec($statement);
            }
        };
        $this->connectAttempts(static fn (): PDO => $pdo);

        try {
            $this->sessions()->for(self::CONNECTION);
            self::fail('the failed setup did not surface');
        } catch (SessionPdoException $error) {
            self::assertStringContainsString(self::CONNECTION, (string) $error->session);
        }

        self::assertFalse($pdo->inTransaction(), 'the failed setup left the session inside its transaction');
    }

    /**
     * A connected PDO whose every query(), prepare() and exec() throws
     * $failure: a transaction pooler that accepts the client but has no
     * backend for its statements.
     */
    private static function failingQueryPdo(PDOException $failure): PDO
    {
        return new class($failure) extends PDO
        {
            public function __construct(private readonly PDOException $failure)
            {
                parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw $this->failure;
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw $this->failure;
            }

            public function exec(string $statement): int|false
            {
                throw $this->failure;
            }
        };
    }

    /**
     * A connected package session of $driver on a ServerStandInPdo.
     */
    private function standInSession(string $driver): Connection
    {
        $this->unreachable($driver);
        $pdo = new ServerStandInPdo;
        $this->connectAttempts(static fn (): PDO => $pdo);
        $session = $this->sessions()->for(self::CONNECTION);
        $session->scalar('select 1');

        return $session;
    }

    /**
     * A watched statement on a connected mirror session of $driver whose PDO
     * throws a driver error with $errorInfo. The statement binds $binding,
     * which Laravel writes into the QueryException's message.
     *
     * @param  array{string, int, string}  $errorInfo
     */
    private function failedStatement(string $driver, string $message, array $errorInfo, string $binding = 'x'): WatchResult
    {
        $this->unreachable($driver);
        $pdo = new ServerStandInPdo;
        $this->connectAttempts(static fn (): PDO => $pdo);
        $session = $this->sessions()->for(self::CONNECTION);
        $session->scalar('select 1');
        $error = new PDOException($message);
        $error->errorInfo = $errorInfo;
        $pdo->failNextStatement($error);

        return $this->watchOnMirror('data_probe', static fn (): mixed => $session->scalar('select ?', [$binding]));
    }

    /**
     * Watcher::attempt() while the unreachable connection is the mirror:
     * only a dependency error of the mirror's session opens the breaker.
     */
    private function watchOnMirror(string $operation, Closure $work): WatchResult
    {
        return $this->onMirror(static fn (): WatchResult => Watcher::instance()->attempt($operation, $work));
    }

    /**
     * The error that $work throws; a $work that returns fails the test.
     */
    private function failureOf(Closure $work): Throwable
    {
        try {
            $work();
        } catch (Throwable $error) {
            return $error;
        }

        self::fail('The statement reached an unreachable server.');
    }

    /**
     * The statement of one access to the unreachable connection.
     */
    private function operation(string $access): void
    {
        $sessions = $this->sessions();

        match ($access) {
            'mirror' => $this->onMirror(static fn () => $sessions->mirror()->scalar('select 1')),
            'queue' => $sessions->forQueue(self::QUEUE)->scalar('select 1'),
            'bounded' => $sessions->bounded($sessions->for(self::CONNECTION), static fn (Connection $session): mixed => $session->scalar('select 1')),
            'transaction' => $this->app->make(PackageTransaction::class)->run($sessions->for(self::CONNECTION), static fn (Connection $session): mixed => $session->scalar('select 1')),
            default => self::fail("Unknown access {$access}."),
        };
    }

    /**
     * The mirror on the unreachable connection while $work runs: the
     * harness rolls the migrations back on the configured mirror later.
     */
    private function onMirror(Closure $work): mixed
    {
        $config = $this->app['config'];
        $previous = $config->get('orrery.database');
        $config->set('orrery.database', self::CONNECTION);

        try {
            return $work();
        } finally {
            $config->set('orrery.database', $previous);
        }
    }

    /**
     * A database connection on a closed local port (the connect seam stands
     * in for the server, and a connect that bypasses it fails at once), and
     * a database queue connection on it.
     */
    private function unreachable(string $driver): void
    {
        $pooled = $driver === 'pgsql-pooled';
        $driver = $pooled ? 'pgsql' : $driver;
        $config = $this->app['config'];
        $config->set('database.connections.'.self::CONNECTION, [
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => 9,
            'database' => 'orrery_probe',
            'username' => 'probe',
            'password' => 'probe',
            'prefix' => '',
        ]);
        $config->set('queue.connections.'.self::QUEUE, ['driver' => 'database', 'connection' => self::CONNECTION, 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $config->set('orrery.sessions.pooled', $pooled ? [self::CONNECTION] : false);
        Clock::freezeMonotonicForTesting();
    }

    /**
     * Each connect attempt of the package's connectors counts, lets the
     * connect timeout of its PDO options pass on the test clock when it
     * fails, and then runs $outcome.
     *
     * @param  Closure(): PDO  $outcome
     */
    private function connectAttempts(Closure $outcome): void
    {
        PackageSession::useConnectAttemptForTesting(function (string $dsn, array $options) use ($outcome): PDO {
            $this->attempts++;
            self::assertSame(PackageSession::CONNECT_TIMEOUT_SECONDS, $options[PDO::ATTR_TIMEOUT] ?? null, 'The session connects with its own timeout.');

            try {
                return $outcome();
            } catch (PDOException $failure) {
                Clock::advanceMonotonicForTesting((float) $options[PDO::ATTR_TIMEOUT]);

                throw $failure;
            }
        });
    }

    /**
     * Binds Laravel's MySQL connector, with a connect that counts and fails
     * with the Linux timeout, as the application's db.connector.mysql.
     */
    private function bindCountingConnector(): void
    {
        $count = function (): void {
            $this->attempts++;
        };

        $this->app->bind('db.connector.mysql', static fn (): LaravelMySqlConnector => new class($count) extends LaravelMySqlConnector
        {
            public function __construct(private readonly Closure $count) {}

            protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                ($this->count)();

                throw new PDOException(ConnectAttemptsTest::linuxTimeout(), 2002);
            }
        });
    }

    public static function linuxTimeout(): string
    {
        return self::MYSQL_LINUX_TIMEOUT;
    }

    private function sessions(): PackageSession
    {
        return $this->app->make(PackageSession::class);
    }
}
