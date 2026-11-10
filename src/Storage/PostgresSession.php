<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\PostgresBuilder;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * A package session on Postgres. PackageSession constructs it itself,
 * never through Connection::resolverFor().
 *
 * Not pooled (a direct connection): PackageSession sets the isolation level,
 * the lock, statement and idle-in-transaction timeouts at connect, and this
 * class behaves as Laravel's PostgresConnection.
 *
 * Pooled (orrery.sessions.pooled, a transaction pooler such as PgBouncer):
 * the session sends no session SET at all. Instead:
 * - each package transaction begins with one exec(): `BEGIN ISOLATION LEVEL
 *   READ COMMITTED; SET LOCAL lock_timeout = '5s'; SET LOCAL
 *   idle_in_transaction_session_timeout = '30s'; SET LOCAL
 *   statement_timeout = <ms>` (the configured statement timeout, or the one
 *   of a bounded() unit). PDO's pgsql driver reads the
 *   transaction state from libpq, so PDO and Laravel's commit() and
 *   rollBack() see the transaction (PackageSessionTest proves it);
 * - every relation that the package uses is named with its schema: the
 *   session resolves the schema of each one under the server's default
 *   search path, in one transaction with `SET LOCAL ROLE NONE` and a local
 *   search path, and its query grammar and schema builder use that map. So
 *   a statement reaches the intended relation whatever search path or role
 *   another client left on the pooled backend.
 * An autocommit statement on a pooled session carries its own `SET LOCAL`
 * timeouts in the same simple query (autocommitBounds()).
 */
class PostgresSession extends PostgresConnection
{
    use ReconnectsOnlyAfterAConnect;
    use RecordsRoundTrips;

    public const LOCK_TIMEOUT = '5s';

    private readonly bool $pooled;

    private ?int $nextStatementTimeoutMs = null;

    /** The statement timeout of an autocommit statement on a pooled session, in milliseconds; null is none. */
    private ?int $autocommitStatementTimeoutMs = null;

    /** @var array<string, string> the schema of each resolved relation, by its physical name */
    private array $schemas = [];

    private ?string $defaultSchema = null;

    private bool $resolved = false;

    /**
     * @param  PDO|Closure(): PDO  $pdo
     * @param  array<string, mixed>  $config
     * @param  list<string>  $relations  the physical names of the application relations that the package uses on this connection
     * @param  string  $defaultSearchPath  the server's default search path (pg_settings.reset_val); pooled only
     * @param  string  $lockTimeout  the lock_timeout of a pooled begin and autocommit statement (LOCK_TIMEOUT; shorter only in tests)
     */
    public function __construct(
        PDO|Closure $pdo,
        string $database,
        string $prefix,
        array $config,
        bool $pooled,
        private readonly array $relations = [],
        private string $defaultSearchPath = '',
        private readonly int $idleInTransactionSeconds = 30,
        private readonly string $lockTimeout = self::LOCK_TIMEOUT,
    ) {
        // The grammar depends on the mode, and the parent builds it.
        $this->pooled = $pooled;

        parent::__construct($pdo, $database, $prefix, $config);
    }

    public function isPooled(): bool
    {
        return $this->pooled;
    }

    /**
     * The statement timeout that each autocommit statement of a pooled
     * session gets with its lock timeout (autocommitBounds()).
     */
    public function useAutocommitStatementTimeout(?int $milliseconds): void
    {
        $this->autocommitStatementTimeoutMs = $milliseconds !== null && $milliseconds > 0 ? $milliseconds : null;
    }

    /**
     * The bounds of an autocommit statement on a pooled session: `SET LOCAL
     * lock_timeout` and `SET LOCAL statement_timeout` sent in the same simple
     * query as the statement. Postgres runs a multi-statement query as one
     * implicit transaction, so the settings bound that statement and end
     * with it: nothing stays on the pooled backend, and the statement is
     * still one round trip (the hot-path budget does not change). It needs
     * emulated prepares, which the package sets on every pooled session.
     */
    public function autocommitBounds(): string
    {
        $bounds = sprintf("SET LOCAL lock_timeout = '%s'", $this->lockTimeout);

        if ($this->autocommitStatementTimeoutMs !== null) {
            $bounds .= sprintf('; SET LOCAL statement_timeout = %d', $this->autocommitStatementTimeoutMs);
        }

        return $bounds.'; ';
    }

    /**
     * A pooled session outside a transaction sends each statement with its
     * bounds (autocommitBounds()). The recorder and the query log see the
     * statement itself; the bounds travel in the same round trip.
     *
     * @param  string  $query
     * @param  array<mixed>  $bindings
     * @return mixed
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if ($this->pooled && $this->transactions === 0 && ! $this->pdoInTransaction()) {
            $query = $this->autocommitBounds().$query;
        }

        return parent::runQueryCallback($query, $bindings, $callback);
    }

    /**
     * A pooled session emulates its prepares, so PDO writes each binding
     * into the SQL text, and Postgres refuses an integer for a boolean
     * (42804, 42883). Laravel 13 sends 'true' and 'false' with emulated
     * prepares; Laravel 12's PostgresConnection casts every bool to an
     * integer. So a pooled session turns each bool into its literal before
     * the parent sees it, on both versions.
     *
     * @param  array<mixed>  $bindings
     * @return array<mixed>
     */
    public function prepareBindings(array $bindings)
    {
        if ($this->pooled) {
            foreach ($bindings as $key => $value) {
                if (is_bool($value)) {
                    $bindings[$key] = $value ? 'true' : 'false';
                }
            }
        }

        return parent::prepareBindings($bindings);
    }

    private function pdoInTransaction(): bool
    {
        $pdo = $this->getRawPdo();

        return $pdo instanceof PDO && $pdo->inTransaction();
    }

    /**
     * The statement that begins a package transaction: the combined begin on
     * a pooled session, else Laravel's BEGIN. A pooled transaction gets the
     * statement timeout of its autocommit statements, or the
     * one of a bounded() unit.
     */
    public function beginStatement(?int $statementTimeoutMs = null): string
    {
        if (! $this->pooled) {
            return 'BEGIN';
        }

        $statementTimeoutMs ??= $this->autocommitStatementTimeoutMs;

        $sql = sprintf(
            "BEGIN ISOLATION LEVEL READ COMMITTED; SET LOCAL lock_timeout = '%s'; SET LOCAL idle_in_transaction_session_timeout = '%ds'",
            $this->lockTimeout,
            $this->idleInTransactionSeconds,
        );

        if ($statementTimeoutMs !== null && $statementTimeoutMs > 0) {
            $sql .= sprintf('; SET LOCAL statement_timeout = %d', $statementTimeoutMs);
        }

        return $sql;
    }

    /**
     * The next package transaction at level 0 also gets the statement
     * timeout (PackageSession::bounded()). Pooled sessions only.
     */
    public function withStatementTimeoutOnNextBegin(int $milliseconds): void
    {
        if (! $this->pooled) {
            throw new LogicException('Only a pooled session takes its statement timeout per transaction.');
        }

        $this->nextStatementTimeoutMs = $milliseconds;
    }

    /**
     * The server's default search path that the pooled session resolves its
     * relations under.
     */
    public function defaultSearchPath(): string
    {
        return $this->defaultSearchPath;
    }

    /**
     * The schema of a relation under the server's default search path, from
     * the map only. Null when the map does not hold it.
     */
    public function knownSchemaOf(string $physical): ?string
    {
        return $this->schemas[$physical] ?? null;
    }

    /**
     * The schema of a relation that a statement names (pooled sessions).
     * A relation of the package (a table whose name starts with the prefix
     * and `orrery_`, or a configured jobs, failed-job or batch table) that
     * the map does not hold makes the session resolve once more; when it
     * still does not resolve, the statement fails. Any other name (an alias,
     * a subquery name, a table that the package does not use) gives null and
     * stays unqualified.
     *
     * @throws PooledRelationUnresolved
     */
    public function schemaFor(string $physical): ?string
    {
        if (isset($this->schemas[$physical])) {
            return $this->schemas[$physical];
        }

        if (! $this->isPackageRelation($physical)) {
            return null;
        }

        $this->resolveRelations([$physical]);

        return $this->schemas[$physical] ?? throw new PooledRelationUnresolved((string) $this->getName(), $physical);
    }

    /**
     * The schema of a relation for an existence check: from the map, else
     * after one more resolution that includes the name. Null when the
     * relation does not resolve under the default search path.
     */
    public function lookUpSchema(string $physical): ?string
    {
        if (! isset($this->schemas[$physical])) {
            $this->resolveRelations([$physical]);
        }

        return $this->schemas[$physical] ?? null;
    }

    /**
     * The schema that new relations go to under the server's default search
     * path (its current_schema()).
     */
    public function defaultSchema(): ?string
    {
        if (! $this->resolved) {
            $this->resolveRelations();
        }

        return $this->defaultSchema;
    }

    /**
     * A table name of raw package SQL, wrapped and qualified as the query
     * grammar does it.
     */
    public function qualifiedTable(string $table): string
    {
        return $this->getQueryGrammar()->wrapTable($table);
    }

    /**
     * Resolves the schema of every relation that the package uses on this
     * connection under the server's default search path and the session's
     * own role, in one transaction (a savepoint inside an open one), with one
     * to_regclass() read. No session setting stays behind.
     *
     * A driver error of its raw statements becomes a SessionPdoException of
     * this session, so the watcher sees a lost backend as a dependency error
     * (Laravel does not wrap these statements).
     *
     * @param  list<string>  $extra  more physical names to resolve
     */
    public function resolveRelations(array $extra = []): void
    {
        try {
            $this->resolveRelationsOnPdo($extra);
        } catch (PDOException $error) {
            throw SessionPdoException::of((string) $this->getName(), $error);
        }
    }

    /**
     * @param  list<string>  $extra
     */
    private function resolveRelationsOnPdo(array $extra): void
    {
        $this->reconnectIfMissingConnection();
        $pdo = $this->getPdo();
        $inTransaction = $this->transactionLevel() > 0;
        $begin = $inTransaction ? 'SAVEPOINT orrery_relations' : 'BEGIN ISOLATION LEVEL READ COMMITTED';
        $setup = sprintf("%s; SET LOCAL ROLE NONE; SET LOCAL lock_timeout = '%s'", $begin, $this->lockTimeout);

        try {
            // Inside the try: a SET LOCAL that fails after the BEGIN of the
            // same query leaves the transaction open, and the rollback ends it.
            $this->recordRoundTrip(PackageSession::EXEC, $setup, $this->getName());
            $pdo->exec($setup);

            $path = $pdo->prepare("select set_config('search_path', ?, true)");
            $path->execute([$this->defaultSearchPath]);
            $this->recordRoundTrip(PackageSession::STATEMENT, "select set_config('search_path', ?, true)", $this->getName());

            $current = $pdo->query('select current_schema()');
            $default = $current === false ? null : $current->fetchColumn();
            $this->recordRoundTrip(PackageSession::STATEMENT, 'select current_schema()', $this->getName());

            $names = array_values(array_unique(array_merge($this->relations, $extra)));
            $lookup = $pdo->prepare(
                'select c.relname, n.nspname from pg_catalog.pg_class c '
                .'join pg_catalog.pg_namespace n on n.oid = c.relnamespace '
                ."where c.relkind in ('r', 'p', 'v', 'm', 'f') "
                ."and (c.relname like ? escape '\\' or c.relname in (select json_array_elements_text(?::json))) "
                .'and c.oid = to_regclass(quote_ident(c.relname))'
            );
            $lookup->execute([$this->likePattern(), (string) json_encode($names, JSON_THROW_ON_ERROR)]);
            $this->recordRoundTrip(PackageSession::STATEMENT, 'select c.relname, n.nspname from pg_catalog.pg_class c …', $this->getName());

            $schemas = [];

            foreach ($lookup->fetchAll(PDO::FETCH_NUM) as $row) {
                $schemas[(string) $row[0]] = (string) $row[1];
            }
        } catch (Throwable $error) {
            $this->endResolution($pdo, $inTransaction, false);

            throw $error;
        }

        $this->endResolution($pdo, $inTransaction, true);
        $this->schemas = $schemas;
        $this->defaultSchema = is_string($default) && $default !== '' ? $default : null;
        $this->resolved = true;
    }

    /**
     * A query grammar that qualifies the package's relations on a pooled
     * session.
     *
     * @return PostgresGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->pooled ? new PooledPostgresGrammar($this) : parent::getDefaultQueryGrammar();
    }

    /**
     * @return PostgresBuilder
     */
    public function getSchemaBuilder()
    {
        // The parent sets the default schema grammar when it has none.
        $builder = parent::getSchemaBuilder();

        return $this->pooled ? new PooledPostgresBuilder($this) : $builder;
    }

    /**
     * Pooled: the combined begin at level 0, in one exec(). A begin that
     * fails after BEGIN (for example in a SET LOCAL) leaves no open
     * transaction behind.
     *
     * @return void
     */
    protected function createTransaction()
    {
        if (! $this->pooled || $this->transactions !== 0) {
            $this->recordBegin('BEGIN');
            parent::createTransaction();

            return;
        }

        $sql = $this->beginStatement($this->nextStatementTimeoutMs);
        $this->nextStatementTimeoutMs = null;
        $this->recordBegin($sql);
        $this->reconnectIfMissingConnection();

        try {
            $this->sendBegin($sql);
        } catch (Throwable $error) {
            // A connect that failed in this begin is not tried again
            // (ReconnectsOnlyAfterAConnect).
            if (! $this->causedByLostConnection($error) || $this->connectFailed()) {
                throw $error;
            }

            $this->reconnect();
            $this->sendBegin($sql);
        }
    }

    private function sendBegin(string $sql): void
    {
        $pdo = $this->getPdo();

        try {
            $pdo->exec($sql);
        } catch (Throwable $error) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // The begin's own error is the one to report.
            }

            throw $error;
        }
    }

    private function endResolution(PDO $pdo, bool $inTransaction, bool $success): void
    {
        // The local settings end with the transaction; inside an open one a
        // rollback to the savepoint undoes them. The resolution only reads,
        // so it always ends with a rollback: nothing to commit.
        $end = $inTransaction ? 'ROLLBACK TO SAVEPOINT orrery_relations; RELEASE SAVEPOINT orrery_relations' : 'ROLLBACK';

        try {
            $this->recordRoundTrip(PackageSession::EXEC, $end, $this->getName());
            $pdo->exec($end);
        } catch (Throwable $error) {
            if ($success) {
                throw $error;
            }
        }
    }

    private function isPackageRelation(string $physical): bool
    {
        return str_starts_with($physical, $this->getTablePrefix().'orrery_') || in_array($physical, $this->relations, true);
    }

    private function likePattern(): string
    {
        return addcslashes($this->getTablePrefix().'orrery_', '\\%_').'%';
    }
}
