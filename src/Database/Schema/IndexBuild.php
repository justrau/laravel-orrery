<?php

namespace Justrau\Orrery\Database\Schema;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\EndpointIdentity;
use Justrau\Orrery\Storage\PackageSession;
use PDO;
use Throwable;

/**
 * `php artisan orrery:install --indexes`: builds the
 * missing deferred indexes of orrery_jobs (PackageSchema::DEFERRED_INDEXES)
 * on an existing mirror.
 *
 * The build never runs on a package session and never on a request path.
 * It runs on a **dedicated connection** to the mirror database, built from
 * the configuration (Laravel's connection factory, no event dispatcher), in
 * autocommit, without the package's statement timeout:
 *
 * - **A pooled Postgres mirror** (PackageSession::isPooled()): session
 *   settings and advisory locks leak through a transaction pooler, so the
 *   build uses the configuration's `direct` endpoint when it has one;
 *   otherwise it refuses with POOLED_HINT unless `--connection` names a
 *   direct connection.
 * - **`--connection=<name>`** replaces the mirror's configuration. It must
 *   reach the same database: the same driver and table prefix (and on
 *   Postgres the same search_path), and at run time the same database (on
 *   Postgres current_database() and pg_postmaster_start_time(), on MySQL
 *   and MariaDB DATABASE(), @@hostname and @@port, on SQLite the real path
 *   of the file). Else it refuses.
 * - **Postgres:** `SET statement_timeout` (`--timeout` seconds, default 0:
 *   none) and `SET lock_timeout = '10min'`; builds are serialized by
 *   pg_advisory_lock(ADVISORY_KEY), so a second build waits; each index is
 *   `CREATE INDEX CONCURRENTLY IF NOT EXISTS`; an index that an earlier
 *   build left INVALID is dropped with `DROP INDEX CONCURRENTLY` only while
 *   the lock is held, then built again; a build that fails drops its own
 *   invalid index and reports the error.
 * - **MySQL and MariaDB:** GET_LOCK('orrery_indexes:<database>', 0), cut to
 *   64 characters (another build running: refused); `ALTER TABLE … ADD
 *   INDEX …, ALGORITHM=INPLACE, LOCK=NONE` with `lock_wait_timeout` 5 s,
 *   tried again up to MYSQL_ATTEMPTS times on a metadata-lock timeout. Each
 *   such wait also queues the package's own hot-path writes to orrery_jobs,
 *   which time out and count as dropped observations. MariaDB applies
 *   `--timeout` as max_statement_time; MySQL has no statement timeout for
 *   DDL.
 * - **SQLite:** a flock() on `<mirror file>.orrery-indexes.lock` (another
 *   build running: refused), then `CREATE INDEX IF NOT EXISTS` with a 60 s
 *   busy timeout. It blocks the package's own writes to the file while it
 *   runs (counted as dropped observations). In-memory SQLite (tests only)
 *   builds on the mirror connection itself.
 *
 * Afterwards it forgets the DeferredIndexes cache of this process. Other
 * processes see the indexes within DeferredIndexes::CACHE_SECONDS.
 */
final class IndexBuild
{
    /** The fixed key of pg_advisory_lock(): crc32('orrery:install --indexes'). */
    public const ADVISORY_KEY = 1_633_181_517;

    /** Postgres: the lock wait of the build's statements. */
    public const PG_LOCK_TIMEOUT = '10min';

    /** MySQL and MariaDB: the metadata-lock wait of one ALTER TABLE attempt, in seconds. */
    public const MYSQL_LOCK_WAIT_SECONDS = 5;

    /** MySQL and MariaDB: the attempts of one ALTER TABLE on a metadata-lock timeout. */
    public const MYSQL_ATTEMPTS = 12;

    /** SQLite: the busy timeout of the build connection, in milliseconds. */
    public const SQLITE_BUSY_MS = 60000;

    /** The name of the dedicated build connection. */
    public const CONNECTION = 'orrery.index_build';

    public const POOLED_HINT = 'The Orrery mirror is a pooled Postgres connection without a `direct` endpoint, so the index build cannot run through it. '
        .'Add a `direct` endpoint to the connection, or run php artisan orrery:install --indexes --connection=<a direct connection to the same database>.';

    /** @var (Closure(Connection, string): void)|null tests only: runs before each index statement */
    private ?Closure $beforeStatement = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Builds the missing indexes. $line gets ('info'|'warn'|'error', text).
     * Returns the command's exit code: 0 when every deferred index exists
     * afterwards, 1 otherwise.
     *
     * @param  Closure(string, string): void  $line
     */
    public function run(?string $connection, int $timeoutSeconds, Closure $line): int
    {
        try {
            [$build, $lockFile, $probe, $pooled] = $this->connection($connection);
        } catch (IndexBuildRefused $refused) {
            $line('error', $refused->getMessage());

            return 1;
        }

        // The mirror as its own configuration sees it decides what is missing, before and after; a pooled
        // mirror through its package session (the server's default search path, as the readers see it),
        // else, when the package refuses that session, through the probe.
        $mirror = $probe ?? $build;

        if ($pooled) {
            try {
                $mirror = $this->app->make(PackageSession::class)->mirror();
            } catch (Throwable $refused) {
                $line('warn', 'The package session of the mirror is not usable ('.$refused->getMessage().'); the build checks the indexes through the mirror\'s configuration.');
            }
        }

        try {
            if (DeferredIndexes::read($mirror) === []) {
                $line('info', 'Every deferred orrery_jobs index exists ('.implode(', ', array_keys(PackageSchema::DEFERRED_INDEXES)).').');

                return 0;
            }

            $failed = match ($build->getDriverName()) {
                'pgsql' => $this->postgres($build, $timeoutSeconds, $line),
                'mysql', 'mariadb' => $this->mysql($build, $timeoutSeconds, $line),
                default => $this->sqlite($build, $lockFile, $line),
            };
            $missing = DeferredIndexes::read($mirror);

            if ($missing !== []) {
                $line('error', 'These orrery_jobs indexes are still missing on the mirror: '.implode(', ', $missing).'.');
            }

            return $failed || $missing !== [] ? 1 : 0;
        } catch (IndexBuildRefused $refused) {
            $line('error', $refused->getMessage());

            return 1;
        } finally {
            $this->app->make(DeferredIndexes::class)->forget();

            if ($build->getName() === self::CONNECTION) {
                $build->disconnect();
            }

            $probe?->disconnect();
        }
    }

    /**
     * Tests only: $hook runs on the build connection before each CREATE
     * INDEX or ALTER TABLE (with the index suffix); null removes it.
     *
     * @param  (Closure(Connection, string): void)|null  $hook
     */
    public function beforeStatementForTesting(?Closure $hook): void
    {
        $this->beforeStatement = $hook;
    }

    /**
     * Tests only: the build connection that run() would use.
     *
     * @throws IndexBuildRefused
     */
    public function connectionForTesting(?string $option): Connection
    {
        [$build, , $probe] = $this->connection($option);
        $probe?->disconnect();

        return $build;
    }

    /**
     * The configuration of the probe: a connection of the mirror's own
     * configuration (through a pooler when the mirror is pooled, with
     * emulated prepares as Laravel gives a pooled connection), never a
     * package session.
     *
     * @param  array<string, mixed>  $raw  the mirror's raw configuration
     * @return array<string, mixed>
     */
    public static function probeConfig(array $raw, bool $pooled): array
    {
        $config = Arr::except(PackageSession::writerConfigOf($raw), ['direct', 'pooled', 'name']);
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        unset($options[PDO::ATTR_PERSISTENT]);

        if ($pooled) {
            $options[PDO::ATTR_EMULATE_PREPARES] ??= true;
        }

        $config['options'] = $options;

        return $config;
    }

    /**
     * The dedicated build connection, the lock file of a SQLite mirror, and
     * the probe of a server mirror (its own configuration).
     *
     * @return array{0: Connection, 1: string|null, 2: Connection|null, 3: bool}
     *
     * @throws IndexBuildRefused
     */
    private function connection(?string $option): array
    {
        $sessions = $this->app->make(PackageSession::class);
        $refusal = $sessions->mirrorRefusal();

        if ($refusal !== null) {
            throw new IndexBuildRefused($refusal);
        }

        $base = $this->mirrorBase($sessions);
        $raw = $this->rawConfig($base);
        $sibling = $sessions->siblingPath();

        if ($option === null && PackageSession::isInMemory(PackageSession::writerConfigOf($raw))) {
            // In-memory SQLite (tests only): the mirror is the configured connection.
            return [$sessions->mirror(), null, null, false];
        }

        if ($option !== null) {
            $optionRaw = $this->rawConfig($option);
            $listed = PackageSession::pooledSetting($this->app->make('config')->get('orrery.sessions.pooled'));

            if ($option === $base && $sessions->isPooled($base, $raw) && empty($raw['direct'])) {
                throw new IndexBuildRefused("The connection [{$option}] is the Orrery mirror's own pooled connection. ".self::POOLED_HINT);
            }

            if (is_array($listed) && in_array($option, $listed, true)) {
                throw new IndexBuildRefused("The connection [{$option}] is listed in orrery.sessions.pooled, so it goes through a transaction pooler. ".self::POOLED_HINT);
            }

            // The operator names a direct connection; its own `direct` endpoint wins, and
            // Laravel's `pooled` without one is refused (orrery.sessions.pooled names the mirror's
            // package sessions, not this connection).
            if (is_array($optionRaw['direct'] ?? null) && $optionRaw['direct'] !== []) {
                $config = array_merge(PackageSession::writerConfigOf($optionRaw), $optionRaw['direct']);
            } elseif (($optionRaw['pooled'] ?? false) === true) {
                throw new IndexBuildRefused("The connection [{$option}] is a pooled Postgres connection without a `direct` endpoint. ".self::POOLED_HINT);
            } else {
                $config = PackageSession::writerConfigOf($optionRaw);
            }

            $this->assertSameConfiguredTarget($sibling, $raw, $config, $option);
        } elseif ($sibling !== null) {
            $writer = PackageSession::writerConfigOf($raw);
            $config = ['driver' => 'sqlite', 'database' => $sibling, 'prefix' => (string) ($writer['prefix'] ?? ''), 'foreign_key_constraints' => false];
        } elseif ($sessions->isPooled($base, $raw)) {
            if (! is_array($raw['direct'] ?? null) || $raw['direct'] === []) {
                throw new IndexBuildRefused(self::POOLED_HINT);
            }

            $config = array_merge(PackageSession::writerConfigOf($raw), $raw['direct']);
        } else {
            $config = PackageSession::writerConfigOf($raw);
        }

        $config = Arr::except($config, ['read', 'write', 'sticky', 'direct', 'pooled', 'connect_via_database', 'connect_via_port', 'name']);
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        unset($options[PDO::ATTR_PERSISTENT]);
        $config['options'] = $options;

        if ($config['driver'] === 'pgsql' && $option === null && $sessions->isPooled($base, $raw)) {
            // The direct endpoint: Laravel's direct config turns emulated prepares off.
            $config['options'][PDO::ATTR_EMULATE_PREPARES] = false;
        }

        /** @var Connection $build */
        $build = $this->app->make('db.factory')->make($config, self::CONNECTION);
        $pooled = $sessions->isPooled($base, $raw);
        $probe = $this->assertSameDatabase($raw, $pooled, $build, $option);

        $lockFile = null;

        if ($build->getDriverName() === 'sqlite') {
            $lockFile = EndpointIdentity::realFile((string) $build->getDatabaseName()).'.orrery-indexes.lock';
        }

        return [$build, $lockFile, $probe, $pooled && $build->getDriverName() === 'pgsql'];
    }

    /**
     * The base connection name of the mirror (orrery.database, else the
     * default connection), from PackageSession::mirrorName().
     */
    private function mirrorBase(PackageSession $sessions): string
    {
        $name = $sessions->mirrorName();

        if (str_starts_with($name, PackageSession::NAME_PREFIX)) {
            $name = substr($name, strlen(PackageSession::NAME_PREFIX));
        }

        return str_ends_with($name, PackageSession::SIBLING_MARK) ? substr($name, 0, -strlen(PackageSession::SIBLING_MARK)) : $name;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IndexBuildRefused
     */
    private function rawConfig(string $name): array
    {
        $config = $this->app->make('config')->get('database.connections.'.$name);

        if (! is_array($config)) {
            throw new IndexBuildRefused("The database connection [{$name}] is not configured.");
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * The configured part of the `--connection` check: the same driver, the
     * same table prefix, the same search_path (Postgres), and for a SQLite
     * file the same real path.
     *
     * @param  array<string, mixed>  $raw  the mirror's raw configuration
     * @param  array<string, mixed>  $config  the writer configuration of the option
     *
     * @throws IndexBuildRefused
     */
    private function assertSameConfiguredTarget(?string $sibling, array $raw, array $config, string $option): void
    {
        $mirror = PackageSession::writerConfigOf($raw);

        if ($sibling !== null) {
            $mirror = ['driver' => 'sqlite', 'database' => $sibling, 'prefix' => (string) ($mirror['prefix'] ?? '')];
        }

        $expected = EndpointIdentity::of($mirror)->fields;
        $given = EndpointIdentity::of($config)->fields;
        $keys = ($expected['driver'] ?? null) === 'sqlite' ? ['driver', 'database', 'prefix'] : ['driver', 'prefix', 'search_path'];

        foreach ($keys as $key) {
            if (($expected[$key] ?? null) !== ($given[$key] ?? null)) {
                throw new IndexBuildRefused("The connection [{$option}] does not reach the Orrery mirror database ({$key} differs). Name a direct connection to the same database.");
            }
        }

        if (PackageSession::isInMemory($config)) {
            throw new IndexBuildRefused("The connection [{$option}] is an in-memory SQLite database, not the Orrery mirror.");
        }
    }

    /**
     * The run-time part of the check: the build connection and the mirror
     * reach the same database (a SQLite file was compared by path already):
     * on Postgres current_database() and pg_postmaster_start_time(), on
     * MySQL DATABASE() and @@server_uuid, on MariaDB DATABASE(),
     *
     * @@server_id, @@hostname and @@port. The mirror side is read on a
     * probe of its own configuration (probeConfig()), never on the package
     * session, whose statement timeout and pooled-session rules do not apply
     * to an operator's build. Returns the probe (null for SQLite).
     *
     * @param  array<string, mixed>  $raw  the mirror's raw configuration
     *
     * @throws IndexBuildRefused
     */
    private function assertSameDatabase(array $raw, bool $pooled, Connection $build, ?string $option): ?Connection
    {
        if ($build->getDriverName() === 'sqlite') {
            return null;
        }

        $probe = null;

        try {
            /** @var Connection $probe */
            $probe = $this->app->make('db.factory')->make(self::probeConfig($raw, $pooled), self::CONNECTION.'_probe');
            $sql = self::identitySql($build);
            $mirror = $probe->selectOne($sql);
            $built = $build->selectOne($sql);
        } catch (Throwable $error) {
            $probe?->disconnect();

            throw new IndexBuildRefused('Could not compare the build connection with the Orrery mirror: '.$error->getMessage());
        }

        if (! is_object($mirror) || ! is_object($built) || (array) $mirror !== (array) $built) {
            $probe->disconnect();
            $name = $option ?? 'the direct endpoint';

            throw new IndexBuildRefused("The connection [{$name}] does not reach the Orrery mirror database. Name a direct connection to the same database.");
        }

        return $probe;
    }

    /**
     * The identity read of a server: the database and the server instance.
     */
    public static function identitySql(Connection $connection): string
    {
        if ($connection->getDriverName() === 'pgsql') {
            return 'select current_database()::text as db, pg_postmaster_start_time()::text as instance';
        }

        $mariadb = $connection->getDriverName() === 'mariadb' || str_contains(strtolower((string) $connection->scalar('select version()')), 'mariadb');

        return $mariadb
            ? 'select DATABASE() as db, concat(@@server_id, \':\', @@hostname, \':\', @@port) as instance'
            : 'select DATABASE() as db, @@server_uuid as instance';
    }

    /**
     * @param  Closure(string, string): void  $line
     */
    private function postgres(Connection $build, int $timeoutSeconds, Closure $line): bool
    {
        $build->statement('SET statement_timeout = '.max(0, $timeoutSeconds) * 1000);
        $build->statement("SET lock_timeout = '".self::PG_LOCK_TIMEOUT."'");

        $table = $build->getTablePrefix().'orrery_jobs';
        $schema = $build->scalar('select n.nspname from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.oid = to_regclass(?)', ['"'.str_replace('"', '""', $table).'"']);

        if (! is_string($schema)) {
            throw new IndexBuildRefused('The table orrery_jobs does not exist on the mirror. Run php artisan migrate first.');
        }

        $line('info', 'Waiting for the index build lock (pg_advisory_lock) ...');
        $build->select('select pg_advisory_lock(?)', [self::ADVISORY_KEY]);
        $failed = false;

        try {
            // The indexes of the schema that holds orrery_jobs, not of the first schema of the search path.
            foreach (DeferredIndexes::read($build, $schema) as $suffix) {
                $name = IndexName::for($build, 'orrery_jobs', $suffix);
                $qualified = self::pgQuote($schema).'.'.self::pgQuote($name);
                $state = $build->selectOne(
                    'select i.indisvalid and i.indisready as valid from pg_class ic join pg_index i on i.indexrelid = ic.oid join pg_namespace n on n.oid = ic.relnamespace where ic.relname = ? and n.nspname = ?',
                    [$name, $schema],
                );

                if (is_object($state) && ($state->valid ?? false) === true) {
                    continue;
                }

                if (is_object($state)) {
                    $line('warn', "The index {$name} is invalid (an interrupted build): dropping it to build it again.");
                    $build->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$qualified);
                }

                $columns = implode(', ', array_map(self::pgQuote(...), PackageSchema::DEFERRED_INDEXES[$suffix]));
                $started = Clock::monotonic();

                try {
                    ($this->beforeStatement)?->__invoke($build, $suffix);
                    $build->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::pgQuote($name).' ON '.self::pgQuote($schema).'.'.self::pgQuote($table).' ('.$columns.')');
                } catch (Throwable $error) {
                    $failed = true;
                    $line('error', "The build of {$name} failed: ".$error->getMessage());

                    try {
                        // The failure can be the statement timeout: the cleanup runs without it.
                        $build->statement('SET statement_timeout = 0');
                        $build->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$qualified);
                        $line('warn', "Dropped the invalid index {$name} that the failed build left.");
                    } catch (Throwable $drop) {
                        $line('error', "Could not drop the invalid index {$name}: ".$drop->getMessage());
                    }

                    break;
                }

                $line('info', sprintf('Built %s in %.1f s.', $name, Clock::monotonic() - $started));
            }
        } finally {
            $build->select('select pg_advisory_unlock(?)', [self::ADVISORY_KEY]);
        }

        return $failed;
    }

    /**
     * @param  Closure(string, string): void  $line
     */
    private function mysql(Connection $build, int $timeoutSeconds, Closure $line): bool
    {
        $lock = mb_strcut('orrery_indexes:'.$build->getDatabaseName(), 0, 64, 'UTF-8');

        if ((int) $build->scalar('select GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new IndexBuildRefused('Another php artisan orrery:install --indexes runs on this database. Run it again when that build has finished.');
        }

        $failed = false;

        try {
            $build->statement('SET SESSION lock_wait_timeout = '.self::MYSQL_LOCK_WAIT_SECONDS);

            if ($build->getDriverName() === 'mariadb' || str_contains(strtolower((string) $build->scalar('select version()')), 'mariadb')) {
                $build->statement('SET SESSION max_statement_time = '.max(0, $timeoutSeconds));
            } else {
                $build->statement('SET SESSION max_execution_time = 0');
            }

            $grammar = $build->getQueryGrammar();

            foreach (DeferredIndexes::read($build) as $suffix) {
                $name = IndexName::for($build, 'orrery_jobs', $suffix);
                $columns = implode(', ', array_map(static fn (string $column): string => $grammar->wrap($column), PackageSchema::DEFERRED_INDEXES[$suffix]));
                $sql = 'ALTER TABLE '.$grammar->wrapTable('orrery_jobs').' ADD INDEX '.$grammar->wrap($name).' ('.$columns.'), ALGORITHM=INPLACE, LOCK=NONE';
                $started = Clock::monotonic();

                for ($attempt = 1; ; $attempt++) {
                    try {
                        ($this->beforeStatement)?->__invoke($build, $suffix);
                        $build->statement($sql);

                        break;
                    } catch (QueryException $error) {
                        if (self::isMysqlLockTimeout($error) && $attempt < self::MYSQL_ATTEMPTS) {
                            $line('warn', "The table orrery_jobs is locked by another statement: attempt {$attempt} of ".self::MYSQL_ATTEMPTS.' timed out; trying again.');

                            continue;
                        }

                        $failed = true;
                        $line('error', "The build of {$name} failed: ".$error->getMessage());

                        break 2;
                    }
                }

                $line('info', sprintf('Built %s in %.1f s.', $name, Clock::monotonic() - $started));
            }
        } finally {
            $build->select('select RELEASE_LOCK(?)', [$lock]);
        }

        return $failed;
    }

    /**
     * @param  Closure(string, string): void  $line
     */
    private function sqlite(Connection $build, ?string $lockFile, Closure $line): bool
    {
        $handle = null;

        if ($lockFile !== null) {
            $handle = fopen($lockFile, 'c');

            if ($handle === false) {
                throw new IndexBuildRefused("Could not open the build lock file {$lockFile}.");
            }

            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);

                throw new IndexBuildRefused('Another php artisan orrery:install --indexes runs on this SQLite file. Run it again when that build has finished.');
            }
        }

        $failed = false;

        try {
            if ($build->getName() === self::CONNECTION) {
                $build->statement('PRAGMA busy_timeout = '.self::SQLITE_BUSY_MS);
            }

            $grammar = $build->getQueryGrammar();

            foreach (DeferredIndexes::read($build) as $suffix) {
                $name = IndexName::for($build, 'orrery_jobs', $suffix);
                $columns = implode(', ', array_map(static fn (string $column): string => $grammar->wrap($column), PackageSchema::DEFERRED_INDEXES[$suffix]));
                $started = Clock::monotonic();

                try {
                    ($this->beforeStatement)?->__invoke($build, $suffix);
                    $build->statement('CREATE INDEX IF NOT EXISTS '.$grammar->wrap($name).' ON '.$grammar->wrapTable('orrery_jobs').' ('.$columns.')');
                } catch (Throwable $error) {
                    $failed = true;
                    $line('error', "The build of {$name} failed: ".$error->getMessage());

                    break;
                }

                $line('info', sprintf('Built %s in %.1f s.', $name, Clock::monotonic() - $started));
            }
        } finally {
            if ($handle !== null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return $failed;
    }

    private static function isMysqlLockTimeout(QueryException $error): bool
    {
        $info = $error->errorInfo;

        return is_array($info) && (int) ($info[1] ?? 0) === 1205;
    }

    private static function pgQuote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
