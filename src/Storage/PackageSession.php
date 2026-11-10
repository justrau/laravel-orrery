<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use Justrau\Orrery\Database\Schema\PackageSchema;
use PDO;
use PDOException;
use Throwable;

/**
 * One package session per database connection.
 *
 * for($name) gives the session `orrery.session.<name>` of a database
 * connection name. It is cached per process by the name and its endpoint
 * identity (EndpointIdentity) together, so a name that is retargeted at
 * runtime gets a new session and never an old PDO, and two names that reach
 * one database stay two sessions. The package builds every session itself,
 * never through DatabaseManager::build():
 * - from the writer configuration: the URL resolved, `write` merged in,
 *   `read`, `sticky`, `direct` and `pooled` removed, so no read reaches a
 *   lagging replica;
 * - the application's PDO options are kept, and the package sets
 *   ATTR_PERSISTENT false, ATTR_ERRMODE exception and, on MySQL, MariaDB
 *   and Postgres, ATTR_TIMEOUT 5 s (the connect timeout);
 * - an inherited `isolation_level` is removed; Postgres sets READ COMMITTED
 *   itself (a connection key when direct, the combined begin when pooled);
 * - no transaction manager and no event dispatcher: package statements fire
 *   no events, so they never reach Telescope, Pulse or a test's query count;
 * - its own reconnector, which builds the PDO again through the package's
 *   resolver and so applies the session settings again. It never calls
 *   DatabaseManager::reconnect(), which would reconnect the host's own
 *   connection of that name;
 * - one connect attempt per statement on MySQL, MariaDB and Postgres: the
 *   package's connectors do not connect again after a failed connect
 *   (SessionConnectionFactory), and the session reconnects only after a
 *   connect that succeeded (ReconnectsOnlyAfterAConnect). So an unreachable
 *   server costs one connect timeout, also where Laravel's lost-connection
 *   detector knows the failure (mysqlnd's `[2002] Connection timed out` on
 *   Linux), and a connection that drops later still reconnects once.
 *
 * The settings per engine:
 * - MySQL and MariaDB: PDO::ATTR_AUTOCOMMIT true; the init command is the
 *   application's followed by `SET SESSION autocommit = 1,
 *   innodb_lock_wait_timeout = 5, lock_wait_timeout = 5`; after connect
 *   `SET SESSION max_execution_time = <ms>` (MariaDB: max_statement_time).
 *   When the application's init command names an isolation level, the
 *   package adds `SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ`
 *   after its own statement, so the package session runs at MySQL's default.
 *   orrery.sessions.pooled never applies to them.
 * - Postgres, not pooled: isolation READ COMMITTED, then one exec() of
 *   `SET lock_timeout = '5s'; SET statement_timeout = <ms>; SET
 *   idle_in_transaction_session_timeout = '30s'`.
 * - Postgres, pooled (orrery.sessions.pooled, or Laravel's own `pooled` or
 *   `direct` keys): no session SET at all (PostgresSession). Package
 *   transactions begin with SET LOCAL timeouts, and every autocommit
 *   statement carries `SET LOCAL lock_timeout` and `statement_timeout` in
 *   its own simple query (emulated prepares), so no statement waits without
 *   a bound and the round trips do not change.
 * - SQLite: busy_timeout 5,000 ms. On a host file (HostFiles) only
 *   busy_timeout and foreign_key_constraints, never the inherited pragmas,
 *   journal_mode or synchronous; an automatic session opens the file read
 *   only, an operator session (operator()) read-write.
 * `<ms>` is orrery.sessions.statement_timeout_ms (10,000).
 *
 * In-memory SQLite cannot be cloned: there the session is the configured
 * connection (tests only), and nothing here changes or closes it.
 */
final class PackageSession
{
    public const NAME_PREFIX = 'orrery.session.';

    public const LOCK_WAIT_SECONDS = 5;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    public const IDLE_IN_TRANSACTION_SECONDS = 30;

    public const DEFAULT_STATEMENT_TIMEOUT_MS = 10000;

    /** The name of the sibling mirror file: `<name>.orrery.sqlite`. */
    public const SIBLING_SUFFIX = '.orrery.sqlite';

    /** The name part of the sibling session: `orrery.session.<name>#sibling`. */
    public const SIBLING_MARK = '#sibling';

    /** The access mode of the sibling session. */
    public const SIBLING = 'sibling';

    /** How often a warm process checks that its sibling file is still the same file. */
    /** The suffix of the lock file that guards the creation of a sibling file. */
    public const SIBLING_CREATE_LOCK = '.create.lock';

    public const SIBLING_STAT_SECONDS = 1.0;

    public const FILE_URI_REFUSAL = 'Orrery cannot place its mirror next to a file: URI database; set orrery.database.';

    public const MIRROR_FILE_URI_REFUSAL = 'orrery.database names a SQLite file: URI, which Orrery cannot compare with the files of the application; name the file by its path.';

    /** The recorded kinds of round trip (recordForTesting()). */
    public const STATEMENT = 'statement';

    public const BEGIN = 'begin';

    public const COMMIT = 'commit';

    public const ROLLBACK = 'rollback';

    public const EXEC = 'exec';

    /** Not a round trip: the end of a run() statement (RecordsRoundTrips::logQuery()). */
    public const EXECUTED = 'executed';

    /** The access modes of a session. Only a host SQLite file has two. */
    public const SHARED = 'shared';

    public const AUTOMATIC = 'automatic';

    public const OPERATOR = 'operator';

    /** The settings that a pooled session never sends, its connector's included. */
    private const POOLED_REMOVED_KEYS = ['isolation_level', 'synchronous_commit', 'timezone', 'search_path', 'schema'];

    /** The startup options that would change the namespace of a pooled session. */
    private const POOLED_REFUSED_SERVER_OPTIONS = ['search_path', 'role', 'session_authorization'];

    /** @var array<string, array{connection: Connection, identity: string, name: string, mode: string, inode?: int|false, checked?: float}> the sessions by name and access mode */
    private array $sessions = [];

    /** @var array<string, PooledSessionRefused> the pooled refusals by name, access mode, identity and settings */
    private array $refusals = [];

    /** @var (Closure(string $kind, string $sql, ?string $session, ?Connection $connection, array<mixed> $bindings): void)|null */
    private ?Closure $recorder = null;

    /** @var (Closure(string $sessionName, array<string, mixed> $config): ?PDO)|null */
    private ?Closure $pdoFactory = null;

    private ?int $idleSecondsForTesting = null;

    private ?int $lockWaitSecondsForTesting = null;

    private ?int $connectTimeoutSecondsForTesting = null;

    /** @var (Closure(string $dsn, array<int, mixed> $options): ?PDO)|null */
    private ?Closure $connectAttempt = null;

    private ?OperatorContext $operator = null;

    /** The host files of the application, kept after their first use (both are process singletons): mirror() asks them on every call with orrery.database set. */
    private ?HostFiles $hostFiles = null;

    /** With orrery.database empty: the default connection name and endpoint that the first mirror() of this process followed. */
    private ?string $followedMirror = null;

    /** Whether this process reported that the mirror followed database.default to another target (once per process). */
    private bool $mirrorMoveReported = false;

    public function __construct(private readonly Application $app)
    {
    }

    /**
     * The session of a database connection name; null is database.default.
     * A PDO alias (`x::read`, `x::write`) gives the session of its base `x`.
     *
     * @throws HostFileAccessNotAllowed for an automatic session on a host SQLite file that allows no automatic reads
     * @throws PooledSessionRefused
     */
    public function for(?string $connection, ?OperatorContext $context = null): Connection
    {
        $name = $this->base($connection ?? $this->defaultConnection());
        $writer = $this->writerConfig($name);

        if (self::isInMemory($writer)) {
            /** @var Connection $configured */
            $configured = $this->db()->connection($name);

            return $configured;
        }

        $mode = $this->modeFor($name, $writer, $context);
        // The endpoint, the session settings and the whole writer configuration: a change of any
        // gives a new session (a rotated password included; the reconnector of a
        // session keeps the configuration that built it).
        $identity = EndpointIdentity::of($writer)->hash().':'.$this->settingsKey($name).':'.self::configDigest($writer);
        $key = $name."\0".$mode;
        $entry = $this->sessions[$key] ?? null;

        if ($entry !== null && $entry['identity'] === $identity) {
            return $entry['connection'];
        }

        if ($entry !== null) {
            // The name reaches another endpoint, or has other settings, now: never its old PDO.
            unset($this->sessions[$key]);
            self::close($entry['connection']);
        }

        // A refused pooled session is not connected again for the same endpoint and settings.
        $refusal = $this->refusals[$key."\0".$identity] ?? null;

        if ($refusal !== null) {
            throw $refusal;
        }

        try {
            $session = $this->build($name, $writer, $mode);
        } catch (PooledSessionRefused $refused) {
            $this->refusals[$key."\0".$identity] = $refused;

            throw $refused;
        }

        $this->sessions[$key] = ['connection' => $session, 'identity' => $identity, 'name' => $name, 'mode' => $mode];

        return $session;
    }

    /**
     * The session of the package tables: orrery.database, else
     * database.default.
     */
    public function mirror(): Connection
    {
        $configured = $this->app->make('config')->get('orrery.database');

        if (is_string($configured) && $configured !== '') {
            $this->refuseSharedMirror($configured);

            return $this->for($configured);
        }

        $name = $this->base($this->defaultConnection());
        $writer = $this->writerConfig($name);
        $this->followDefault($name, $writer);

        if (($writer['driver'] ?? null) !== 'sqlite' || self::isInMemory($writer)) {
            return $this->for($name);
        }

        return $this->sibling($name, $writer);
    }

    /**
     * With orrery.database empty, the mirror follows database.default on
     * every call. An application that switches its default connection or
     * database at runtime (a multi-tenant package) would scatter the
     * package rows over its databases, so the first change inside a process
     * is reported to the application's exception handler (MirrorMoved; it
     * names the connections only), once per process. The rows of that
     * process are not moved back: set orrery.database to a fixed connection.
     *
     * @param  array<string, mixed>  $writer
     */
    private function followDefault(string $name, array $writer): void
    {
        if ($this->mirrorMoveReported) {
            return;
        }

        try {
            $target = $name."\0".EndpointIdentity::of($writer)->hash();
        } catch (Throwable) {
            // The session build reports a configuration that names no endpoint.
            return;
        }

        if ($this->followedMirror === null) {
            $this->followedMirror = $target;

            return;
        }

        if ($this->followedMirror === $target) {
            return;
        }

        $this->mirrorMoveReported = true;
        $first = explode("\0", $this->followedMirror, 2)[0];

        try {
            $this->app->make(ExceptionHandler::class)->report(new MirrorMoved($first, $name));
        } catch (Throwable) {
            // The report never reaches the host's work.
        }
    }

    /**
     * The connection name of the mirror session, from the configuration
     * alone (no session opens).
     */
    public function mirrorName(): string
    {
        $configured = $this->app->make('config')->get('orrery.database');
        $name = $this->base(is_string($configured) && $configured !== '' ? $configured : $this->defaultConnection());

        if ($this->isInMemoryConnection($name)) {
            return $name;
        }

        return self::NAME_PREFIX.$name.($this->mirrorIsSibling() ? self::SIBLING_MARK : '');
    }

    /**
     * The package session name that the mirror's errors carry
     * (ErrorKinds::isMirrorDependency()): mirrorName(), prefixed also for
     * in-memory SQLite (tests only), where the mirror is the configured
     * connection and a test names its package session.
     */
    public function mirrorSessionName(): string
    {
        $name = $this->mirrorName();

        return self::isPackageSession($name) ? $name : self::NAME_PREFIX.$name;
    }

    /**
     * The path of the sibling SQLite mirror file at this moment: with
     * orrery.database empty and a default connection on a SQLite file,
     * `<dir>/<name>.orrery.sqlite` next to that file's real path. Null when
     * the mirror is not a sibling.
     */
    public function siblingPath(): ?string
    {
        $configured = $this->app->make('config')->get('orrery.database');

        if (is_string($configured) && $configured !== '') {
            return null;
        }

        $writer = $this->writerConfig($this->base($this->defaultConnection()));

        if (($writer['driver'] ?? null) !== 'sqlite' || self::isInMemory($writer)) {
            return null;
        }

        $database = (string) ($writer['database'] ?? '');

        if (str_starts_with($database, 'file:')) {
            return null;
        }

        return self::siblingPathOf($database);
    }

    /**
     * The sibling file of a SQLite database path: its real path's directory
     * and `<name>.orrery.sqlite`, where <name> is the file name without a
     * SQLite extension (.sqlite, .sqlite3, .db, .db3), else the whole file
     * name (so `database.sqlite_test_1` of Laravel's parallel testing gets
     * its own sibling). A missing file is resolved like Laravel's connector
     * resolves it (the path, else against base_path()).
     */
    public static function siblingPathOf(string $database): string
    {
        $real = EndpointIdentity::realFile($database);

        if ($real === $database && ! str_starts_with($database, '/') && function_exists('base_path')) {
            $real = base_path($database);
        }

        return dirname($real).'/'.self::siblingNameOf(basename($real));
    }

    /**
     * The file name of the sibling of a SQLite file name (siblingPathOf()
     * without the path): a pure function, which the Workbench uses too.
     */
    public static function siblingNameOf(string $base): string
    {
        $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $name = in_array($extension, ['sqlite', 'sqlite3', 'db', 'db3'], true) ? pathinfo($base, PATHINFO_FILENAME) : $base;

        return ($name === '' ? 'database' : $name).self::SIBLING_SUFFIX;
    }

    /**
     * Whether the mirror is a sibling file at this moment.
     */
    public function mirrorIsSibling(): bool
    {
        return $this->siblingPath() !== null;
    }

    /**
     * The reason why the configured mirror is refused (D17), or null:
     * an explicit orrery.database on the SQLite file of a connection that the
     * package uses, or a `file:` URI default connection without
     * orrery.database.
     */
    public function mirrorRefusal(): ?string
    {
        try {
            $configured = $this->app->make('config')->get('orrery.database');

            if (is_string($configured) && $configured !== '') {
                $this->refuseSharedMirror($configured);

                return null;
            }

            $writer = $this->writerConfig($this->base($this->defaultConnection()));

            if (($writer['driver'] ?? null) === 'sqlite' && ! self::isInMemory($writer) && str_starts_with((string) ($writer['database'] ?? ''), 'file:')) {
                return self::FILE_URI_REFUSAL;
            }
        } catch (MirrorRefused $refused) {
            return $refused->getMessage();
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The session of a queue connection: its `connection`, else
     * database.default.
     */
    public function forQueue(string $queueConnection, ?OperatorContext $context = null): Connection
    {
        $connection = $this->app->make('config')->get("queue.connections.{$queueConnection}.connection");

        return $this->for(is_string($connection) && $connection !== '' ? $connection : null, $context);
    }

    /**
     * The database connection name of a queue connection:
     * queue.connections.<queue>.connection, else database.default, as its
     * base name (a PDO alias gives its base, ConnectionName).
     */
    public function databaseConnectionFor(string $queueConnection): string
    {
        $connection = $this->app->make('config')->get("queue.connections.{$queueConnection}.connection");

        return $this->base(is_string($connection) && $connection !== '' ? $connection : $this->defaultConnection());
    }

    /**
     * Discards a session and gives the current one of its connection name, in
     * the same access mode. The application's own connection (in-memory
     * SQLite) is returned unchanged.
     */
    public function reopen(Connection $session): Connection
    {
        foreach ($this->sessions as $key => $entry) {
            if ($entry['connection'] !== $session) {
                continue;
            }

            unset($this->sessions[$key]);
            self::close($session);

            if ($entry['mode'] === self::SIBLING) {
                return $this->mirror();
            }

            return $this->for($entry['name'], $entry['mode'] === self::OPERATOR ? $this->operatorContext() : null);
        }

        return $session;
    }

    /**
     * Whether a database connection name is in-memory SQLite: then its
     * session is the configured connection itself (tests only).
     */
    public function isInMemoryConnection(?string $connection): bool
    {
        try {
            return self::isInMemory($this->writerConfig($this->base($connection ?? $this->defaultConnection())));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether a connection name is a package session.
     */
    public static function isPackageSession(?string $connectionName): bool
    {
        return $connectionName !== null && str_starts_with($connectionName, self::NAME_PREFIX);
    }

    /**
     * The driver of a package session's connection name (`orrery.session.
     * <name>`, a sibling `…#sibling`), from the configuration alone (no
     * session opens). Null for another name or a connection that is not
     * configured.
     */
    public function sessionDriver(string $connectionName): ?string
    {
        if (! self::isPackageSession($connectionName)) {
            return null;
        }

        $name = explode('::', substr($connectionName, strlen(self::NAME_PREFIX)))[0];

        if (str_ends_with($name, self::SIBLING_MARK)) {
            return 'sqlite';
        }

        try {
            $driver = $this->writerConfig($name)['driver'] ?? null;
        } catch (InvalidArgumentException) {
            return null;
        }

        return is_string($driver) ? $driver : null;
    }

    /**
     * The operator context of the running operator action, or null. A write
     * site of an operator action passes it to for(), so the write reaches a
     * host SQLite file only inside an operator action; any other caller gets
     * the automatic (read-only) session there.
     */
    public function operatorContext(): ?OperatorContext
    {
        $context = $this->operator;

        return $context !== null && $context->isActive() ? $context : null;
    }

    /**
     * The session of the current action: inside an operator action
     * (operator()) the operator session, elsewhere the automatic one. The
     * write sites of the operator actions use it, so a host SQLite file gets
     * a write only inside an operator action.
     *
     * @throws HostFileAccessNotAllowed
     */
    public function forAction(?string $connection): Connection
    {
        return $this->for($connection, $this->operatorContext());
    }

    /**
     * Ends a unit of automatic work (a tick phase, a request, a job): the
     * automatic sessions of host SQLite files close, so an idle package
     * reader never keeps the application from changing its journal mode,
     * and the next unit confirms the journal mode again (D18).
     */
    public static function releaseAutomatic(): void
    {
        self::bound()->releaseMode(self::AUTOMATIC);
        HostFiles::endUnit();
    }

    /**
     * Runs an operator action: $work gets the operator context, which opens
     * the read-write operator sessions of host SQLite files. Nested calls
     * share the context; the outermost call ends it and releases the
     * operator sessions, also after an exception.
     *
     * @template T
     *
     * @param  Closure(OperatorContext): T  $work
     * @return T
     */
    public function operator(Closure $work): mixed
    {
        $context = $this->operator ??= new OperatorContext;
        $context->enter();

        try {
            return $work($context);
        } finally {
            if ($context->leave() === 0) {
                $this->operator = null;
                $this->releaseMode(self::OPERATOR);
            }
        }
    }

    /**
     * operator() on the application's PackageSession, for the classes that
     * have no instance.
     *
     * @template T
     *
     * @param  Closure(OperatorContext): T  $work
     * @return T
     */
    public static function asOperator(Closure $work): mixed
    {
        /** @var self $sessions */
        $sessions = \Illuminate\Container\Container::getInstance()->make(self::class);

        return $sessions->operator($work);
    }

    /**
     * The timeouts for one unit of maintenance or dashboard work (one read,
     * or one batch write). Pooled Postgres: a package transaction with the
     * statement timeout. Elsewhere, and inside an open transaction, $work
     * runs as is: the session already has its timeouts.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     */
    public function bounded(Connection $session, Closure $work): mixed
    {
        $timeout = $this->statementTimeoutMs();

        if (! $session instanceof PostgresSession || ! $session->isPooled() || $session->transactionLevel() > 0 || $timeout === null) {
            return $work($session);
        }

        $session->withStatementTimeoutOnNextBegin($timeout);

        try {
            // Inside the try: an alarm's exception right after the begin
            // returned (Worker::$killOnTimeout false) must end it too.
            $session->beginTransaction();
            $result = $work($session);
            $session->commit();

            return $result;
        } catch (Throwable $error) {
            try {
                $session->rollBack();
            } catch (Throwable) {
                $this->disconnect($session);
            }

            if ($session->transactionLevel() > 0 || PackageTransaction::rawInTransaction($session)) {
                // Nothing at Laravel's level ended the server's transaction.
                $this->disconnect($session);
            }

            throw $error;
        }
    }

    /**
     * A raw statement on a session, reported to the test recorder.
     */
    public function exec(Connection $session, string $sql): int
    {
        $this->recordTrip(self::EXEC, $sql, $session->getName());

        try {
            $session->reconnectIfMissingConnection();
            $affected = $session->getPdo()->exec($sql);
        } catch (PDOException $error) {
            // Laravel does not wrap a raw exec(): the error names its session (ErrorKinds).
            throw SessionPdoException::of((string) $session->getName(), $error);
        }

        return $affected === false ? 0 : $affected;
    }

    /**
     * Discards a session after an ambiguous commit or a failed publication:
     * its PDO closes (the server discards an open transaction), and the next
     * for() builds a new Connection at level 0. The application's own
     * connection (in-memory SQLite) is never closed.
     */
    public function disconnect(Connection $session): void
    {
        foreach ($this->sessions as $key => $entry) {
            if ($entry['connection'] === $session) {
                unset($this->sessions[$key]);
                self::close($session);
            }
        }
    }

    /**
     * The key of the mirror, from the configuration alone (no session
     * opens): the hash of the mirror's session name and the endpoint
     * identity of its connection. The shared breaker files use it.
     */
    public function mirrorKey(): string
    {
        $configured = $this->app->make('config')->get('orrery.database');

        return hash('sha256', $this->mirrorSessionName()."\0".$this->fingerprint(is_string($configured) && $configured !== '' ? $configured : null));
    }

    /**
     * The hash of the endpoint identity of a database connection: the
     * target fingerprint. The access mode never changes it.
     */
    public function fingerprint(?string $connection): string
    {
        return EndpointIdentity::of($this->writerConfig($this->base($connection ?? $this->defaultConnection())))->hash();
    }

    /**
     * The writer configuration of a database connection name.
     *
     * @return array<string, mixed>
     */
    public function writerConfig(string $connection): array
    {
        $config = Arr::get((array) $this->app->make('config')->get('database.connections'), $connection);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Database connection [{$connection}] not configured.");
        }

        return self::writerConfigOf($config);
    }

    /**
     * The writer configuration: the URL is resolved, `write` is merged into
     * the base (a list of writers gives its first entry), and `read`,
     * `sticky` and `name` are removed.
     *
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    public static function writerConfigOf(array $config): array
    {
        /** @var array<string, mixed> $config */
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        $write = $config['write'] ?? null;

        if (is_array($write)) {
            $write = array_is_list($write) ? ($write[0] ?? []) : $write;
            $config = array_merge($config, is_array($write) ? $write : []);
        }

        unset($config['read'], $config['write'], $config['sticky'], $config['name']);

        return $config;
    }

    /**
     * @param  array<string, mixed>  $writer
     */
    public static function isInMemory(array $writer): bool
    {
        if (($writer['driver'] ?? null) !== 'sqlite') {
            return false;
        }

        $database = (string) ($writer['database'] ?? '');

        return $database === ':memory:' || str_contains($database, 'mode=memory') || str_starts_with($database, 'file::memory:');
    }

    /**
     * Whether a Postgres connection goes through a transaction pooler:
     * orrery.sessions.pooled is true or names it, or Laravel's own `pooled`
     * or `direct` key marks it. Never for MySQL, MariaDB or SQLite.
     *
     * @param  array<string, mixed>  $config  the raw connection configuration
     */
    public function isPooled(string $connection, array $config): bool
    {
        if (($config['driver'] ?? null) !== 'pgsql') {
            return false;
        }

        if (($config['pooled'] ?? false) === true || ! empty($config['direct'])) {
            return true;
        }

        $pooled = self::pooledSetting($this->app->make('config')->get('orrery.sessions.pooled', false));

        return $pooled === true || in_array($connection, $pooled, true);
    }

    /**
     * orrery.sessions.pooled as it comes from the configuration or the
     * environment: true for every connection, else the listed connection
     * names (none: an empty list). env() leaves `1`, `on` or a list of names
     * as a string: a boolean word (filter_var()) is that boolean, any other
     * string a comma-separated list of names.
     *
     * @return true|list<string>
     */
    public static function pooledSetting(mixed $value): true|array
    {
        if (is_string($value)) {
            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $value = $flag ?? explode(',', $value);
        }

        if ($value === true || $value === 1) {
            return true;
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $name): string => is_string($name) ? trim($name) : '',
            $value,
        ), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Closes the PDO of every cached session and forgets them
     * (setPdo(null)->setReadPdo(null), never disconnect(), which rolls the
     * transactions manager back on Laravel 13). The test teardown calls it.
     */
    public static function releaseAll(): void
    {
        self::bound()->releaseSessions();
    }

    /**
     * releaseAll() on this instance: the test teardown takes the instance
     * before the application is flushed, and releases its sessions after.
     */
    public function releaseSessions(): void
    {
        $sessions = $this->sessions;
        $this->sessions = [];
        $this->refusals = [];

        foreach ($sessions as $entry) {
            self::close($entry['connection']);
        }
    }

    /**
     * Reports every round trip of a package session to $recorder: each
     * run() statement, each begin (the combined Postgres begin counts once),
     * savepoint, commit, rollback and raw exec(). Null removes it. The
     * session's Connection and the bindings are passed where a session class
     * reports the round trip (run() statements and the transaction methods
     * of the session classes); PackageTransaction's raw SQLite statements and
     * the raw exec() report null.
     *
     * @param  (Closure(string $kind, string $sql, ?string $session, ?Connection $connection, array<mixed> $bindings): void)|null  $recorder
     */
    public static function recordForTesting(?Closure $recorder): void
    {
        self::bound()->recorder = $recorder;
    }

    /**
     * One round trip, for the classes that send SQL on a package session
     * without Connection::run() (PostgresSession, PackageTransaction).
     */
    /**
     * @param  array<mixed>  $bindings
     */
    public static function record(string $kind, string $sql, ?string $session, ?Connection $connection = null, array $bindings = []): void
    {
        self::bound()->recordTrip($kind, $sql, $session, $connection, $bindings);
    }

    /**
     * record() on this instance: the session classes keep their owning
     * PackageSession (RecordsRoundTrips::ownedBy()), so a round trip needs
     * no container lookup.
     *
     * @param  array<mixed>  $bindings
     */
    public function recordTrip(string $kind, string $sql, ?string $session, ?Connection $connection = null, array $bindings = []): void
    {
        $recorder = $this->recorder;

        if ($recorder !== null) {
            $recorder($kind, $sql, $session, $connection, $bindings);
        }
    }

    /**
     * Replaces the PDO creation of every new session PDO in tests. A PDO
     * from the factory gets every session setting as explicit statements; a
     * null result connects through Laravel's connector.
     *
     * @param  (Closure(string $sessionName, array<string, mixed> $config): ?PDO)|null  $factory
     */
    public static function usePdoFactoryForTesting(?Closure $factory): void
    {
        self::bound()->pdoFactory = $factory;
    }

    /**
     * Shortens the idle-in-transaction timeout of new sessions in tests.
     */
    public static function useIdleInTransactionSecondsForTesting(?int $seconds): void
    {
        self::bound()->idleSecondsForTesting = $seconds;
    }

    /**
     * Shortens the lock wait bound of new MySQL, MariaDB and Postgres
     * sessions in tests (innodb_lock_wait_timeout and lock_wait_timeout;
     * lock_timeout, also in the pooled begin and autocommit bounds). One
     * test per engine keeps the production value. Null removes it.
     */
    public static function useLockWaitSecondsForTesting(?int $seconds): void
    {
        self::bound()->lockWaitSecondsForTesting = $seconds;
    }

    /**
     * Shortens the connect timeout (PDO::ATTR_TIMEOUT) of new MySQL, MariaDB
     * and Postgres sessions in tests. Null removes it.
     */
    public static function useConnectTimeoutSecondsForTesting(?int $seconds): void
    {
        self::bound()->connectTimeoutSecondsForTesting = $seconds;
    }

    /**
     * Replaces each connect attempt of the package's MySQL, MariaDB and
     * Postgres connectors in tests (SessionConnectionFactory): the closure
     * gets the DSN and the PDO options and returns the PDO, throws the
     * connect failure, or returns null for a real connect. Laravel's
     * connector logic around it runs unchanged. Null removes it.
     *
     * @param  (Closure(string $dsn, array<int, mixed> $options): ?PDO)|null  $attempt
     */
    public static function useConnectAttemptForTesting(?Closure $attempt): void
    {
        self::bound()->connectAttempt = $attempt;
    }

    /**
     * The cached sessions, for the tests' count of live PDOs.
     *
     * @return list<array{name: string, mode: string, connection: Connection}>
     */
    public static function sessionsForTesting(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => ['name' => $entry['name'], 'mode' => $entry['mode'], 'connection' => $entry['connection']],
            self::bound()->sessions,
        ));
    }

    /**
     * Releases every session and removes the test seams (a test that starts
     * again inside its own application).
     */
    public static function resetForTesting(): void
    {
        $sessions = self::bound();
        $sessions->releaseSessions();
        $sessions->recorder = null;
        $sessions->pdoFactory = null;
        $sessions->idleSecondsForTesting = null;
        $sessions->lockWaitSecondsForTesting = null;
        $sessions->connectTimeoutSecondsForTesting = null;
        $sessions->connectAttempt = null;
        $sessions->operator = null;
        HostFiles::resetForTesting();
    }

    /**
     * MySQL's init-command attribute: PHP 8.4 moved the driver constants to
     * Pdo\Mysql, and PHP 8.5 deprecates the old PDO constant. Both have the
     * same value.
     */
    public static function mysqlInitCommandAttribute(): int
    {
        return defined('Pdo\Mysql::ATTR_INIT_COMMAND')
            ? (int) constant('Pdo\Mysql::ATTR_INIT_COMMAND')
            : (int) constant('PDO::MYSQL_ATTR_INIT_COMMAND');
    }

    /**
     * The MySQL and MariaDB statement that every package session runs at
     * connect, after the application's init command.
     */
    /** Added after an application init command that names an isolation level (MySQL, MariaDB). */
    public const MYSQL_ISOLATION_STATEMENT = 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ';

    public static function mysqlSessionStatement(int $lockWaitSeconds = self::LOCK_WAIT_SECONDS): string
    {
        return sprintf(
            'SET SESSION autocommit = 1, innodb_lock_wait_timeout = %d, lock_wait_timeout = %d',
            $lockWaitSeconds,
            $lockWaitSeconds,
        );
    }

    /**
     * The sibling session: a SQLite file next to the default
     * connection's file, which the package owns. The path is derived now,
     * from the default connection's current `database`, so a retargeted
     * default (for example Laravel's parallel testing) gets its own sibling.
     * The session creates the file and its directory, and installs or
     * upgrades the schema (PackageSchema) the first time it opens the file in
     * this process. A warm session checks at most once a second that the
     * file is the same file (its inode): a deleted or replaced file gives a
     * new session, which installs the schema again.
     *
     * @param  array<string, mixed>  $writer
     */
    private function sibling(string $name, array $writer): Connection
    {
        $database = (string) ($writer['database'] ?? '');

        if (str_starts_with($database, 'file:')) {
            throw new MirrorRefused(self::FILE_URI_REFUSAL);
        }

        $path = self::siblingPathOf($database);
        $prefix = (string) ($writer['prefix'] ?? '');
        $identity = hash('sha256', $path."\0".$prefix."\0".$this->settingsKey($name));
        $key = $name.self::SIBLING_MARK."\0".self::SIBLING;
        $entry = $this->sessions[$key] ?? null;

        if ($entry !== null && $entry['identity'] === $identity) {
            $now = Clock::monotonic();

            if ($now - ($entry['checked'] ?? 0.0) < self::SIBLING_STAT_SECONDS) {
                return $entry['connection'];
            }

            clearstatcache(true, $path);
            $inode = @fileinode($path);
            $this->sessions[$key]['checked'] = $now;

            if ($inode !== false && $inode === ($entry['inode'] ?? null)) {
                return $entry['connection'];
            }
        }

        if ($entry !== null) {
            unset($this->sessions[$key]);
            self::close($entry['connection']);
        }

        // The real file, as Laravel's connector resolves a relative path (a web request runs in public/).
        $hostFile = EndpointIdentity::realFile($database);
        $this->createSiblingFile($path, $hostFile);
        $config = [
            'driver' => 'sqlite',
            'url' => null,
            'database' => $path,
            'prefix' => $prefix,
            'foreign_key_constraints' => $writer['foreign_key_constraints'] ?? null,
            'busy_timeout' => self::LOCK_WAIT_SECONDS * 1000,
            'journal_mode' => 'wal',
            // The application's file, whose mode a re-created sibling gets (createSiblingFile()).
            'sibling_of' => $hostFile,
            'name' => self::NAME_PREFIX.$name.self::SIBLING_MARK,
            'options' => [PDO::ATTR_PERSISTENT => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        ];
        $session = $this->connect($config, 'sqlite', false, true);
        clearstatcache(true, $path);
        // Cached before the schema check, so a caller that the check reaches
        // (for example a test recorder) gets this session, not a second one.
        $this->sessions[$key] = [
            'connection' => $session,
            'identity' => $identity,
            'name' => $name,
            'mode' => self::SIBLING,
            'inode' => @fileinode($path),
            'checked' => Clock::monotonic(),
        ];

        try {
            PackageSchema::ensureInstalled($session);
        } catch (Throwable $error) {
            unset($this->sessions[$key]);
            self::close($session);

            throw $error;
        }

        return $session;
    }

    /**
     * Creates the sibling file and its directory when they are missing.
     * Laravel's SQLite connector cannot create a file. A file that cannot be
     * created is a dependency error (SiblingFileUnavailable), so the
     * breaker opens instead of every observation retrying.
     *
     * The creation runs under an exclusive flock() on the package's own
     * lock file (<sibling>.create.lock), with the file checked again under
     * the lock. A sibling whose main file was deleted can leave its -wal,
     * -shm or -journal file behind; SQLite would apply that old journal to
     * the new file, so the creation removes them first. Only the creator of
     * a missing main file removes them, so a live journal is never removed.
     * The new file (and the lock file) get the permission bits of the
     * application's file, not the umask of the creating process, so a web
     * server and a worker of another user in the same group can both open
     * it. SQLite gives its -wal and -shm files the
     * bits of the main file.
     */
    private function createSiblingFile(string $path, string $hostFile): void
    {
        if (is_file($path)) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new SiblingFileUnavailable($path, 'its directory cannot be created');
        }

        $lockPath = $path.self::SIBLING_CREATE_LOCK;
        $existed = is_file($lockPath);
        $lock = @fopen($lockPath, 'c');
        $lock = $lock === false ? @fopen($lockPath, 'r') : $lock;

        if ($lock === false) {
            throw new SiblingFileUnavailable($path, 'its lock file cannot be created');
        }

        $mode = @fileperms($hostFile);

        if (! $existed && $mode !== false) {
            @chmod($lockPath, $mode & 0666);
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new SiblingFileUnavailable($path, 'its lock file cannot be locked');
            }

            clearstatcache(true, $path);

            if (is_file($path)) {
                return;
            }

            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                if (file_exists($path.$suffix)) {
                    @unlink($path.$suffix);
                }
            }

            if (! @touch($path) || ! is_file($path)) {
                throw new SiblingFileUnavailable($path, 'the file cannot be created');
            }

            if ($mode !== false) {
                @chmod($path, $mode & 0666);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * An explicit orrery.database on the real SQLite file of a connection
     * that the package uses is refused (D17): the mirror's writes are
     * automatic, and the package makes no automatic write to a host file.
     * The configured name itself counts: the queue's own SQLite connection
     * is a host file too.
     */
    private function refuseSharedMirror(string $configured): void
    {
        $file = $this->hostFiles()->realFile($configured);

        if ($file === null) {
            return;
        }

        if (str_starts_with($file, 'file:')) {
            // A URI has no real path to compare: it may name the queue's own file.
            throw new MirrorRefused(self::MIRROR_FILE_URI_REFUSAL);
        }

        $owner = $this->hostFiles()->connectionOfFile($file);

        if ($owner !== null) {
            throw new MirrorRefused("orrery.database shares the SQLite file of connection [{$owner}]; leave orrery.database empty (a sibling file) or name a file of its own.");
        }
    }

    private function hostFiles(): HostFiles
    {
        return $this->hostFiles ??= $this->app->make(HostFiles::class);
    }

    /**
     * A digest of a writer configuration (its password included, never
     * stored or shown). A value that JSON cannot hold (a closure) counts as
     * null.
     *
     * @param  array<string, mixed>  $writer
     */
    private static function configDigest(array $writer): string
    {
        return hash('sha256', (string) json_encode($writer, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The session settings that the configuration and the test seams
     * decide: the pooled mode, the statement timeout, the
     * idle-in-transaction timeout, the lock wait bound and the connect
     * timeout. A change of any of them gives a new session.
     */
    private function settingsKey(string $name): string
    {
        $raw = (array) $this->app->make('config')->get("database.connections.{$name}");

        return sprintf('%s:%s:%d:%d:%d', $this->isPooled($name, $raw) ? 'pooled' : 'direct', $this->statementTimeoutMs() ?? 'none', $this->idleSeconds(), $this->lockWaitSeconds(), $this->connectTimeoutSeconds());
    }

    /**
     * @param  array<string, mixed>  $writer
     */
    private function modeFor(string $name, array $writer, ?OperatorContext $context): string
    {
        if (($writer['driver'] ?? null) !== 'sqlite') {
            return self::SHARED;
        }

        $files = $this->hostFiles();

        if (! $files->isHost($name)) {
            return self::SHARED;
        }

        if ($context !== null) {
            $context->assertActive();

            return self::OPERATOR;
        }

        if (! $files->allowsReads($name)) {
            throw new HostFileAccessNotAllowed($name, 'the file holds the application\'s data, and its journal mode or busy timeout does not allow automatic reads.');
        }

        return self::AUTOMATIC;
    }

    /**
     * @param  array<string, mixed>  $writer
     */
    private function build(string $name, array $writer, string $mode): Connection
    {
        $raw = (array) $this->app->make('config')->get("database.connections.{$name}");
        $driver = (string) ($writer['driver'] ?? '');
        $pooled = $this->isPooled($name, $raw);
        $sessionName = self::NAME_PREFIX.$name;
        $config = $this->sessionConfig($name, $writer, $driver, $pooled, $mode);
        $config['name'] = $sessionName;

        if ($driver === 'pgsql' && $pooled) {
            $resolver = $this->pdoResolver($sessionName, $config, $driver, true);

            return $this->prepare($this->pooledPostgres($name, $writer, $resolver, (string) ($config['database'] ?? ''), (string) ($config['prefix'] ?? ''), $config), $sessionName, $config, $driver, true, false);
        }

        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb', 'sqlite'], true)) {
            throw new InvalidArgumentException("Orrery has no package session for the driver [{$driver}] of connection [{$name}].");
        }

        return $this->connect($config, $driver, false);
    }

    /**
     * A session Connection of a complete session configuration (its `name`
     * included), not pooled.
     *
     * @param  array<string, mixed>  $config
     */
    private function connect(array $config, string $driver, bool $pooled, bool $sibling = false): Connection
    {
        $sessionName = (string) $config['name'];
        $resolver = $this->pdoResolver($sessionName, $config, $driver, $pooled, $sibling);
        $database = (string) ($config['database'] ?? '');
        $prefix = (string) ($config['prefix'] ?? '');

        $session = match ($driver) {
            'pgsql' => new PostgresSession($resolver, $database, $prefix, $config, false, [], '', $this->idleSeconds(), $this->postgresLockTimeout()),
            'mysql' => new MySqlSession($resolver, $database, $prefix, $config),
            'mariadb' => new MariaDbSession($resolver, $database, $prefix, $config),
            'sqlite' => new SQLiteSession($resolver, $database, $prefix, $config),
            default => throw new InvalidArgumentException("Orrery has no package session for the driver [{$driver}] of [{$sessionName}]."),
        };

        return $this->prepare($session, $sessionName, $config, $driver, $pooled, $sibling);
    }

    /**
     * No transaction manager, no event dispatcher, the package's own
     * reconnector and the test recorder.
     *
     * @param  array<string, mixed>  $config
     */
    private function prepare(Connection $session, string $sessionName, array $config, string $driver, bool $pooled, bool $sibling): Connection
    {
        $session->unsetTransactionManager();
        $session->unsetEventDispatcher();
        $session->setReconnector(function (Connection $connection) use ($sessionName, $config, $driver, $pooled, $sibling): void {
            $connection->setPdo($this->pdoResolver($sessionName, $config, $driver, $pooled, $sibling))->setReadPdo(null);
        });
        $session->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            $this->recordTrip(self::STATEMENT, $query, $connection->getName(), $connection, $bindings);
        });

        if (method_exists($session, 'ownedBy')) {
            $session->ownedBy($this);
        }

        return $session;
    }

    /**
     * The connection configuration of a session.
     *
     * @param  array<string, mixed>  $writer
     * @return array<string, mixed>
     */
    private function sessionConfig(string $name, array $writer, string $driver, bool $pooled, string $mode): array
    {
        $config = $writer;
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $options[PDO::ATTR_PERSISTENT] = false;
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        unset($config['isolation_level'], $config['direct'], $config['pooled']);

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $options[PDO::ATTR_TIMEOUT] = $this->connectTimeoutSeconds();
                $options[PDO::ATTR_AUTOCOMMIT] = true;
                $attribute = self::mysqlInitCommandAttribute();
                $existing = trim((string) ($options[$attribute] ?? ''), " \t\n\r;");
                // A line break first: an application command that ends in a
                // `-- comment` must not swallow the package settings.
                $options[$attribute] = ($existing === '' ? '' : $existing."\n;\n").self::mysqlSessionStatement($this->lockWaitSeconds());

                // An application init command that sets an isolation level
                // (for example READ UNCOMMITTED) must not reach the package
                // session: it gets MySQL's own default level back, which is
                // safe with every binary log format.
                if (preg_match('/isolation/i', $existing) === 1) {
                    $options[$attribute] .= ";\n".self::MYSQL_ISOLATION_STATEMENT;
                }
                break;

            case 'pgsql':
                $options[PDO::ATTR_TIMEOUT] = $this->connectTimeoutSeconds();

                if ($pooled) {
                    $this->refusePooledServerOptions($name, $config);

                    foreach (self::POOLED_REMOVED_KEYS as $key) {
                        unset($config[$key]);
                    }

                    // Emulated prepares on every pooled session: a transaction
                    // pooler needs them, and the bounds of an autocommit
                    // statement travel in the same simple query
                    // (PostgresSession::autocommitBounds()).
                    $options[PDO::ATTR_EMULATE_PREPARES] = true;
                } else {
                    $config['isolation_level'] = 'read committed';
                }
                break;

            case 'sqlite':
                if ($mode === self::SHARED) {
                    $config['busy_timeout'] = self::LOCK_WAIT_SECONDS * 1000;
                    break;
                }

                // A host file: only busy_timeout and foreign_key_constraints.
                $config = array_intersect_key($config, array_flip(['driver', 'url', 'database', 'prefix', 'foreign_key_constraints', 'busy_timeout']));
                $config['busy_timeout'] ??= self::LOCK_WAIT_SECONDS * 1000;
                $config['options'] = [];
                $options = [PDO::ATTR_PERSISTENT => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

                if ($mode === self::AUTOMATIC) {
                    $options[self::sqliteOpenFlagsAttribute()] = self::sqliteReadOnlyFlag();
                }
                break;
        }

        $config['options'] = $options;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $writer
     * @param  Closure(): PDO  $resolver
     * @param  array<string, mixed>  $config
     */
    private function pooledPostgres(string $name, array $writer, Closure $resolver, string $database, string $prefix, array $config): PostgresSession
    {
        // One read, no session SET: the server's default from the server, the
        // database and the role, the same on every backend of the pool. Its
        // timeouts are SET LOCAL in the same simple query (an implicit
        // transaction), as every autocommit statement of a pooled session
        // carries them, so a pooler without a backend cannot hold it longer.
        $pdo = $resolver();
        $timeout = $this->statementTimeoutMs();
        $read = sprintf("SET LOCAL lock_timeout = '%s'; ", $this->postgresLockTimeout())
            .($timeout === null ? '' : sprintf('SET LOCAL statement_timeout = %d; ', $timeout))
            ."select reset_val from pg_settings where name = 'search_path'";

        try {
            $statement = $pdo->query($read);
            $this->recordTrip(self::STATEMENT, $read, self::NAME_PREFIX.$name);
            $default = $statement === false ? '' : (string) $statement->fetchColumn();
        } catch (PDOException $error) {
            // Laravel does not wrap this read: the error names the session, so
            // a lost backend after the connect is a dependency error (ErrorKinds).
            throw SessionPdoException::of(self::NAME_PREFIX.$name, $error);
        }
        $configured = $writer['search_path'] ?? $writer['schema'] ?? null;

        if ($configured !== null && $configured !== '' && $configured !== [] && self::searchPath($configured) !== self::searchPath($default)) {
            $shown = is_array($configured) ? implode(', ', array_map(strval(...), $configured)) : (string) (is_scalar($configured) ? $configured : '');

            throw new PooledSessionRefused($name, "Orrery cannot use the pooled connection [{$name}]: its search_path {$shown} is not the server default {$default}; use a direct connection.");
        }

        $session = new PostgresSession($pdo, $database, $prefix, $config, true, $this->relationsOf($name, $prefix), $default, $this->idleSeconds(), $this->postgresLockTimeout());
        $session->useAutocommitStatementTimeout($this->statementTimeoutMs());
        $session->resolveRelations();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function refusePooledServerOptions(string $name, array $config): void
    {
        $options = is_array($config['server_options'] ?? null) ? $config['server_options'] : [];

        foreach (array_keys($options) as $option) {
            if (in_array(strtolower((string) $option), self::POOLED_REFUSED_SERVER_OPTIONS, true)) {
                throw new PooledSessionRefused($name, "Orrery cannot use the pooled connection [{$name}]: its server_options set {$option}, which changes the namespace of the pooled backend; use a direct connection.");
            }
        }
    }

    /**
     * The physical names of the application relations that the package uses
     * on a connection: the jobs tables of its database queue connections, the
     * failed-job table and the batch table. A schema-qualified name is used
     * as configured.
     *
     * @return list<string>
     */
    private function relationsOf(string $name, string $prefix): array
    {
        $config = $this->app->make('config');
        $tables = [];

        foreach ((array) $config->get('queue.connections', []) as $queue) {
            if (is_array($queue) && ($queue['driver'] ?? null) === 'database' && $this->connectionOf($queue['connection'] ?? null) === $name) {
                $tables[] = is_string($queue['table'] ?? null) ? $queue['table'] : 'jobs';
            }
        }

        if (in_array($config->get('queue.failed.driver'), ['database', 'database-uuids'], true) && $this->connectionOf($config->get('queue.failed.database')) === $name) {
            $table = $config->get('queue.failed.table');
            $tables[] = is_string($table) && $table !== '' ? $table : 'failed_jobs';
        }

        if ($this->connectionOf($config->get('queue.batching.database')) === $name) {
            $table = $config->get('queue.batching.table');
            $tables[] = is_string($table) && $table !== '' ? $table : 'job_batches';
        }

        $physical = [];

        foreach ($tables as $table) {
            if (! str_contains($table, '.')) {
                $physical[] = $prefix.$table;
            }
        }

        return array_values(array_unique($physical));
    }

    private function connectionOf(mixed $connection): string
    {
        return $this->base(is_string($connection) && $connection !== '' ? $connection : $this->defaultConnection());
    }

    /**
     * A lazy PDO for the session: the test factory's PDO with every setting
     * as explicit statements, else Laravel's connector (several hosts too)
     * followed by the settings that it cannot apply.
     *
     * @param  array<string, mixed>  $config
     * @return Closure(): PDO
     */
    private function pdoResolver(string $sessionName, array $config, string $driver, bool $pooled, bool $sibling = false): Closure
    {
        return function () use ($sessionName, $config, $driver, $pooled, $sibling): PDO {
            try {
                return $this->resolvePdo($sessionName, $config, $driver, $pooled, $sibling);
            } catch (PDOException $error) {
                // A connect failure outside a statement (a begin, a pooled
                // build, a raw exec()) reaches the caller as this
                // PDOException, not as a QueryException: it names its
                // session, so ErrorKinds classifies it (SessionPdoException).
                throw SessionPdoException::of($sessionName, $error);
            }
        };
    }

    /**
     * One new PDO of a session (pdoResolver()).
     *
     * @param  array<string, mixed>  $config
     */
    private function resolvePdo(string $sessionName, array $config, string $driver, bool $pooled, bool $sibling): PDO
    {
        $factory = $this->pdoFactory;
        $pdo = $factory === null ? null : $factory($sessionName, $config);

        if ($pdo instanceof PDO) {
            $this->applyToFactoryPdo($pdo, $driver, $pooled);
            $this->afterConnect($pdo, $driver, $pooled, $sibling);

            return $pdo;
        }

        if ($sibling && ! is_file((string) $config['database'])) {
            // The sibling went away under a warm process: create it again.
            $this->createSiblingFile((string) $config['database'], (string) ($config['sibling_of'] ?? ''));
        }

        // Laravel's factory builds the PDO as for the application, but its
        // server connectors connect once (SessionConnectionFactory). Its
        // temporary connection must not keep the PDO: a disconnect()
        // must close it, so that the server discards an open transaction.
        $connections = new SessionConnectionFactory($this->app, $this->connectAttempt);
        $temporary = $connections->make($config, $sessionName);
        /** @var PDO|null $pdo Laravel declares a PDO, but a resolver can return none. */
        $pdo = $temporary->getPdo();
        $temporary->setPdo(null)->setReadPdo(null);

        if ($pdo === null) {
            throw new InvalidArgumentException("Laravel's connector returned no PDO for the package session [{$sessionName}].");
        }

        $this->afterConnect($pdo, $driver, $pooled, $sibling);

        return $pdo;
    }

    private function afterConnect(PDO $pdo, string $driver, bool $pooled, bool $sibling = false): void
    {
        $timeout = $this->statementTimeoutMs();

        switch ($driver) {
            case 'sqlite':
                if ($sibling) {
                    // The sibling's own settings, on every Laravel line.
                    $pdo->exec(sprintf('PRAGMA busy_timeout = %d', self::LOCK_WAIT_SECONDS * 1000));
                    $pdo->exec('PRAGMA journal_mode = wal');
                }
                break;

            case 'mysql':
            case 'mariadb':
                if ($timeout !== null) {
                    $pdo->exec($this->isMariaDb($pdo, $driver)
                        ? sprintf('SET SESSION max_statement_time = %.3F', $timeout / 1000)
                        : sprintf('SET SESSION max_execution_time = %d', $timeout));
                }
                break;

            case 'pgsql':
                if ($pooled) {
                    break;
                }

                $statements = [sprintf("SET lock_timeout = '%ds'", $this->lockWaitSeconds())];

                if ($timeout !== null) {
                    $statements[] = sprintf('SET statement_timeout = %d', $timeout);
                }

                $statements[] = sprintf("SET idle_in_transaction_session_timeout = '%ds'", $this->idleSeconds());
                $pdo->exec(implode('; ', $statements));
                break;
        }
    }

    /**
     * The settings that Laravel's connector would apply, for a PDO from the
     * test factory.
     */
    private function applyToFactoryPdo(PDO $pdo, string $driver, bool $pooled): void
    {
        // Exception mode first: a failed setting must never pass unnoticed.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        switch ($driver) {
            case 'mysql':
            case 'mariadb':
                $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
                $pdo->exec(self::mysqlSessionStatement($this->lockWaitSeconds()));
                break;

            case 'pgsql':
                if (! $pooled) {
                    $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
                } else {
                    // The bounds of a pooled autocommit statement travel in its simple query.
                    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                }
                break;

            case 'sqlite':
                $pdo->exec(sprintf('PRAGMA busy_timeout = %d', self::LOCK_WAIT_SECONDS * 1000));
                break;
        }
    }

    private function isMariaDb(PDO $pdo, string $driver): bool
    {
        return $driver === 'mariadb' || stripos((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'mariadb') !== false;
    }

    private function statementTimeoutMs(): ?int
    {
        $value = $this->app->make('config')->get('orrery.sessions.statement_timeout_ms', self::DEFAULT_STATEMENT_TIMEOUT_MS);
        $timeout = is_numeric($value) ? (int) $value : self::DEFAULT_STATEMENT_TIMEOUT_MS;

        return $timeout > 0 ? $timeout : null;
    }

    private function idleSeconds(): int
    {
        return $this->idleSecondsForTesting ?? self::IDLE_IN_TRANSACTION_SECONDS;
    }

    private function lockWaitSeconds(): int
    {
        return $this->lockWaitSecondsForTesting ?? self::LOCK_WAIT_SECONDS;
    }

    private function connectTimeoutSeconds(): int
    {
        return $this->connectTimeoutSecondsForTesting ?? self::CONNECT_TIMEOUT_SECONDS;
    }

    /** The lock_timeout value of a Postgres session, as PostgresSession::LOCK_TIMEOUT writes it. */
    private function postgresLockTimeout(): string
    {
        return sprintf('%ds', $this->lockWaitSeconds());
    }

    /**
     * A search path as a list of names, without quotes and blanks, as
     * Laravel's connector parses it.
     *
     * @return list<string>
     */
    private static function searchPath(mixed $path): array
    {
        $names = [];

        foreach (is_array($path) ? $path : [$path] as $part) {
            preg_match_all('/[^\s,"\']+/', is_scalar($part) ? (string) $part : '', $matches);
            array_push($names, ...$matches[0]);
        }

        return $names;
    }

    private static function close(Connection $session): void
    {
        // A caller that still holds the Connection gets a refusal, never a
        // new PDO through the package's reconnector.
        $session->setReconnector(static function (Connection $released): never {
            throw new PackageSessionReleased((string) $released->getName());
        });

        try {
            $session->setPdo(null)->setReadPdo(null);
        } catch (Throwable) {
            // The next session still gets its release.
        }
    }

    private function releaseMode(string $mode): void
    {
        foreach ($this->sessions as $key => $entry) {
            if ($entry['mode'] === $mode) {
                unset($this->sessions[$key]);
                self::close($entry['connection']);
            }
        }
    }

    private static function sqliteOpenFlagsAttribute(): int
    {
        return defined('Pdo\Sqlite::ATTR_OPEN_FLAGS')
            ? (int) constant('Pdo\Sqlite::ATTR_OPEN_FLAGS')
            : (int) constant('PDO::SQLITE_ATTR_OPEN_FLAGS');
    }

    private static function sqliteReadOnlyFlag(): int
    {
        return defined('Pdo\Sqlite::OPEN_READONLY')
            ? (int) constant('Pdo\Sqlite::OPEN_READONLY')
            : (int) constant('PDO::SQLITE_OPEN_READONLY');
    }

    private function base(string $connection): string
    {
        return ConnectionName::base($this->db(), $connection);
    }

    private function defaultConnection(): string
    {
        return (string) $this->app->make('config')->get('database.default');
    }

    private function db(): DatabaseManager
    {
        return $this->app->make('db');
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
