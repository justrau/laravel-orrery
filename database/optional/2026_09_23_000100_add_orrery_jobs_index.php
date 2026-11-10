<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\ConfigurationUrlParser;
use Justrau\Orrery\Database\Schema\IndexName;
use Justrau\Orrery\Storage\ConnectionName;

/*
 * The optional index for the application's `jobs` table: (`queue`, `reserved_at`,
 * `available_at`). It helps large backlogs: Laravel's pop() selects the ready and the expired
 * rows of one queue through it, and the package's grouped wait-time read visits its entries
 * instead of the table rows.
 *
 * The package never runs this migration. Publish it with
 * `php artisan vendor:publish --tag=orrery-jobs-index`, then run `php artisan migrate`.
 *
 * Which tables. Every `database` queue connection names a jobs table (its `table`, else `jobs`,
 * maybe qualified, for example `tenant.jobs`) on a database connection (its `connection` key,
 * else the default connection; a `::read`, `::write` or `::direct` alias is its base connection).
 * The migration resolves the actual relation on that connection's writer, as the queue reaches
 * it:
 * - Postgres: `to_regclass()` of the table under the connection's own search path, so a jobs
 *   table that exists only in a later schema of the path is found, and a qualified name is
 *   resolved in its schema;
 * - MySQL and MariaDB: the qualifier, else `database()`;
 * - SQLite: the qualifier, else `main`.
 * The relation is the endpoint of the connection (host, port and database, or the file), its
 * schema and its table name with the table prefix. Queue connections that reach one relation
 * get one DDL; connections whose relations differ each get their own.
 *
 * The index name is one unqualified identifier: <table name with prefix>_orrery_backlog
 * (IndexName::compose(), which shortens a long name with a hash of the whole name). An index
 * name is unique per table on MySQL and per schema on Postgres and SQLite, and the table name is
 * unique in its schema, so two relations never share an index name. up(), down() and the
 * existence check use the same name, and every DDL names the resolved schema and table, so the
 * search path never decides where it runs.
 *
 * Where the DDL runs: through the migration connection when the relation is on its endpoint, else
 * through the first queue connection of the relation. Under `migrate --pretend` that connection
 * pretends too, so the DDL never runs. up() skips a missing table and an existing index; down()
 * drops only this index.
 *
 * Postgres: the migration runs outside a transaction ($withinTransaction), so the index is built
 * with CREATE INDEX CONCURRENTLY and dropped with DROP INDEX CONCURRENTLY: the queue's pushes and
 * reservations go on while the index builds. An index that a failed concurrent build left INVALID
 * is dropped and built again. Only when a connection is already inside a transaction (a caller's
 * own) does it build a plain index, then on another connection in a transaction of its own with a
 * lock timeout of 5 s, which blocks writes to the table for the time of the build.
 */
return new class extends Migration
{
    public const SUFFIX = 'orrery_backlog';

    public const COLUMNS = ['queue', 'reserved_at', 'available_at'];

    public const LOCK_TIMEOUT_SECONDS = 5;

    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction block. */
    public $withinTransaction = false;

    public function up(): void
    {
        foreach ($this->relations() as $relation) {
            $this->onRelation($relation, function (Connection $connection, array $relation): void {
                $concurrently = self::concurrently($connection);

                if ($concurrently && $this->hasInvalidIndex($connection, $relation)) {
                    // A failed concurrent build leaves an INVALID index behind: build it again.
                    $connection->statement($this->dropSql($connection->getDriverName(), $relation, true));
                }

                if (! $this->hasIndex($connection, $relation)) {
                    $connection->statement($this->createSql($connection->getDriverName(), $relation, $concurrently));
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->relations() as $relation) {
            $this->onRelation($relation, function (Connection $connection, array $relation): void {
                if ($this->hasIndex($connection, $relation)) {
                    $connection->statement($this->dropSql($connection->getDriverName(), $relation, self::concurrently($connection)));
                }
            });
        }
    }

    /**
     * Whether the DDL on this connection uses CONCURRENTLY: Postgres outside a transaction.
     */
    private static function concurrently(Connection $connection): bool
    {
        return $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0;
    }

    /**
     * One entry per existing jobs relation.
     *
     * @return list<array{key: string, endpoint: string, schema: string, table: string, index: string, names: list<string>}>
     */
    private function relations(): array
    {
        $db = app('db');
        $default = ConnectionName::base($db, (string) $db->getDefaultConnection());
        $relations = [];

        foreach ((array) config('queue.connections', []) as $queue) {
            if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
                continue;
            }

            $name = $queue['connection'] ?? null;
            $name = is_string($name) && $name !== '' ? ConnectionName::base($db, $name) : $default;
            $table = $queue['table'] ?? null;
            $table = is_string($table) && $table !== '' ? $table : 'jobs';

            /** @var Connection $connection */
            $connection = $db->connection($name);
            $resolved = $this->resolve($connection, $table);

            if ($resolved === null) {
                continue;
            }

            $endpoint = $this->endpoint($name);
            $key = $endpoint."\0".$resolved['schema']."\0".$resolved['table'];

            $relations[$key] ??= $resolved + [
                'key' => $key,
                'endpoint' => $endpoint,
                'index' => IndexName::compose('', $resolved['table'], self::SUFFIX),
                'names' => [],
            ];
            $relations[$key]['names'][] = $name;
        }

        return array_values($relations);
    }

    /**
     * The schema and the table name (with the prefix) of an existing relation, read on the
     * connection's writer, or null when it does not exist.
     *
     * @return array{schema: string, table: string}|null
     */
    private function resolve(Connection $connection, string $table): ?array
    {
        $position = strrpos($table, '.');
        $qualifier = $position === false ? null : substr($table, 0, $position);
        $name = $connection->getTablePrefix().($position === false ? $table : substr($table, $position + 1));

        if ($qualifier !== null && str_contains($qualifier, '.')) {
            // database.schema.table: the schema is the last qualifier.
            $qualifier = substr($qualifier, (int) strrpos($qualifier, '.') + 1);
        }

        switch ($connection->getDriverName()) {
            case 'pgsql':
                $text = ($qualifier === null ? '' : $this->quote('pgsql', $qualifier).'.').$this->quote('pgsql', $name);
                $row = $connection->selectOne(
                    "select n.nspname as schema_name, c.relname as table_name from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid = c.relnamespace where c.oid = to_regclass(?) and c.relkind in ('r', 'p')",
                    [$text],
                    false,
                );

                return $row === null ? null : ['schema' => (string) $row->schema_name, 'table' => (string) $row->table_name];

            case 'mysql':
            case 'mariadb':
                $schema = $qualifier ?? (string) $connection->selectOne('select database() as schema_name', [], false)?->schema_name;
                $row = $connection->selectOne('select table_name as table_name from information_schema.tables where table_schema = ? and table_name = ?', [$schema, $name], false);

                return $row === null ? null : ['schema' => $schema, 'table' => $name];

            case 'sqlite':
                $schema = $qualifier ?? 'main';
                $row = $connection->selectOne('select name from '.$this->quote('sqlite', $schema).".sqlite_master where type = 'table' and name = ?", [$name], false);

                return $row === null ? null : ['schema' => $schema, 'table' => $name];
        }

        return null;
    }

    /**
     * The physical endpoint of a connection: its driver and writer endpoints without the table
     * prefix (the prefix is part of the table name).
     */
    private function endpoint(string $name): string
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration((array) config('database.connections.'.$name, []));
        $driver = strtolower((string) ($config['driver'] ?? ''));
        $driver = $driver === 'mariadb' ? 'mysql' : $driver;
        $endpoints = [];

        foreach ($this->writers($config) as $writer) {
            foreach ($this->writerEndpoints($name, $driver, $writer) as $endpoint) {
                $endpoints[(string) json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = $endpoint;
            }
        }

        ksort($endpoints);

        return (string) json_encode(['driver' => $driver, 'endpoints' => array_values($endpoints)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The writer configurations, as Laravel's connection factory builds them: without a `read`
     * entry the base itself, else the base merged with each `write` entry.
     *
     * @param  array<string, mixed>  $config
     * @return non-empty-list<array<string, mixed>>
     */
    private function writers(array $config): array
    {
        $split = isset($config['read']);
        $write = $config['write'] ?? null;
        unset($config['read'], $config['write'], $config['sticky']);

        if (! $split || ! is_array($write) || $write === []) {
            return [$config];
        }

        $entries = array_is_list($write) ? $write : [$write];

        return array_map(
            static fn (mixed $entry): array => array_merge($config, is_array($entry) ? $entry : []),
            $entries,
        );
    }

    /**
     * The endpoints of one writer: the host, port and database, the socket and database, or the
     * SQLite file (its real path; a private in-memory database is its own per connection name).
     *
     * @param  array<string, mixed>  $writer
     * @return list<array<string, mixed>>
     */
    private function writerEndpoints(string $name, string $driver, array $writer): array
    {
        $database = (string) ($writer['database'] ?? '');

        if ($driver === 'sqlite') {
            $memory = $database === ':memory:' || str_contains($database, 'mode=memory');

            if ($memory) {
                return [str_contains($database, 'cache=shared') ? ['shared_memory' => $database] : ['memory' => $name]];
            }

            $path = str_starts_with($database, 'file:') ? (string) preg_replace('~^file:(//)?~', '', explode('?', $database, 2)[0]) : $database;
            $real = realpath($path);

            if ($real === false) {
                $real = realpath(base_path($path));
            }

            return [['file' => $real === false ? $path : $real]];
        }

        if ($driver === 'mysql' && ($writer['unix_socket'] ?? '') !== '') {
            return [['socket' => (string) $writer['unix_socket'], 'database' => $database]];
        }

        $port = (int) ($writer['port'] ?? match ($driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            'sqlsrv' => 1433,
            default => 0,
        });

        $endpoints = [];

        foreach ((array) ($writer['host'] ?? '') as $host) {
            $endpoints[] = ['host' => strtolower(trim((string) $host)), 'port' => $port, 'database' => $database];
        }

        return $endpoints;
    }

    /**
     * Runs $ddl for one relation: on the migration connection when the relation is on its
     * endpoint, else on the relation's first connection; there on Postgres in a transaction
     * of its own with a lock timeout.
     *
     * @param  array{key: string, endpoint: string, schema: string, table: string, index: string, names: list<string>}  $relation
     * @param  Closure(Connection, array{key: string, endpoint: string, schema: string, table: string, index: string, names: list<string>}): void  $ddl
     */
    private function onRelation(array $relation, Closure $ddl): void
    {
        $db = app('db');
        $migration = (string) $db->getDefaultConnection();

        if ($this->endpoint(ConnectionName::base($db, $migration)) === $relation['endpoint']) {
            $ddl($db->connection($migration), $relation);

            return;
        }

        /** @var Connection $connection */
        $connection = $db->connection($relation['names'][0]);

        // `migrate --pretend` pretends only on the migration connection: the other connection
        // pretends too, so its DDL never runs.
        if ($db->connection($migration)->pretending()) {
            $connection->pretend(static fn () => $ddl($connection, $relation));

            return;
        }

        // Outside every transaction the migration connection holds no lock on the table, so the
        // concurrent build on the other connection cannot wait for it.
        if ($connection->getDriverName() !== 'pgsql' || (self::concurrently($connection) && $db->connection($migration)->transactionLevel() === 0)) {
            $ddl($connection, $relation);

            return;
        }

        try {
            $connection->transaction(static function (Connection $connection) use ($ddl, $relation): void {
                $connection->statement(sprintf("set local lock_timeout = '%ds'", self::LOCK_TIMEOUT_SECONDS));
                $ddl($connection, $relation);
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '55P03') {
                throw $exception;
            }

            throw new RuntimeException(sprintf(
                'The optional jobs index waited more than %d s for a lock on %s.%s through the connection [%s]. Another connection with another configuration probably reaches the same table inside the migration. Give the connections the same host, port and database.',
                self::LOCK_TIMEOUT_SECONDS,
                $relation['schema'],
                $relation['table'],
                $relation['names'][0],
            ), 0, $exception);
        }
    }

    /**
     * @param  array{schema: string, table: string, index: string}  $relation
     */
    private function hasIndex(Connection $connection, array $relation): bool
    {
        $driver = $connection->getDriverName();

        $row = match ($driver) {
            'pgsql' => $connection->selectOne('select indexname from pg_catalog.pg_indexes where schemaname = ? and tablename = ? and indexname = ?', [$relation['schema'], $relation['table'], $relation['index']], false),
            'mysql', 'mariadb' => $connection->selectOne('select index_name from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1', [$relation['schema'], $relation['table'], $relation['index']], false),
            default => $connection->selectOne('select name from '.$this->quote('sqlite', $relation['schema']).".sqlite_master where type = 'index' and name = ? and tbl_name = ?", [$relation['index'], $relation['table']], false),
        };

        return $row !== null;
    }

    /**
     * Whether the relation has this index in the INVALID state of a failed concurrent build (Postgres).
     *
     * @param  array{schema: string, table: string, index: string}  $relation
     */
    private function hasInvalidIndex(Connection $connection, array $relation): bool
    {
        return $connection->selectOne(
            'select 1 as invalid from pg_catalog.pg_index i join pg_catalog.pg_class c on c.oid = i.indexrelid join pg_catalog.pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relname = ? and not i.indisvalid',
            [$relation['schema'], $relation['index']],
            false,
        ) !== null;
    }

    /**
     * @param  array{schema: string, table: string, index: string}  $relation
     */
    private function createSql(string $driver, array $relation, bool $concurrently = false): string
    {
        $columns = implode(', ', array_map(fn (string $column): string => $this->quote($driver, $column), self::COLUMNS));
        $table = $this->quote($driver, $relation['schema']).'.'.$this->quote($driver, $relation['table']);
        $index = $this->quote($driver, $relation['index']);

        return match ($driver) {
            'mysql', 'mariadb' => "alter table {$table} add index {$index} ({$columns})",
            'sqlite' => 'create index '.$this->quote($driver, $relation['schema']).".{$index} on ".$this->quote($driver, $relation['table'])." ({$columns})",
            default => 'create index '.($concurrently ? 'concurrently ' : '')."{$index} on {$table} ({$columns})",
        };
    }

    /**
     * @param  array{schema: string, table: string, index: string}  $relation
     */
    private function dropSql(string $driver, array $relation, bool $concurrently = false): string
    {
        $schema = $this->quote($driver, $relation['schema']);
        $index = $this->quote($driver, $relation['index']);

        return match ($driver) {
            'mysql', 'mariadb' => "alter table {$schema}.".$this->quote($driver, $relation['table'])." drop index {$index}",
            default => 'drop index '.($concurrently ? 'concurrently ' : '')."{$schema}.{$index}",
        };
    }

    private function quote(string $driver, string $identifier): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true)
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
};
