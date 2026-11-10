<?php

namespace Justrau\Orrery\Failed;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Justrau\Orrery\Observation\CommitDeferral;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use PDO;
use Throwable;

/**
 * The package's `database-uuids` failed-job provider:
 * Laravel's provider whose log() also binds the inserted record's id to the
 * occurrence that this failure created.
 *
 * log() runs Laravel's insert (the same columns and values on the same
 * table and connection), returns the uuid as Laravel's does, and lets every
 * insert error through unchanged. The id must belong to this insert
 * statement (the receipt):
 * - SQLite 3.35+ and Postgres: its own `INSERT … RETURNING id` (Laravel's
 *   compiled insert plus `returning id`) through the host connection, first
 *   recordsHaveBeenModified(), then selectFromWriteConnection(), as Laravel's
 *   PostgresProcessor does, so the host keeps its events, a sticky
 *   read/write split reads the writer afterwards, and pretend mode and the
 *   errors stay native. Below SQLite 3.35 (PDO::ATTR_SERVER_VERSION of the
 *   library, no statement): Laravel's exact insert(), and no binding.
 * - Postgres also needs the capability: inside an open
 *   transaction of the failed-record connection, Laravel's exact insert()
 *   and nothing else, no binding; in autocommit one read on
 *   the host connection first (the `id` column exists, the role may SELECT
 *   it, no row security), and false, an error or an open breaker give
 *   Laravel's insert() and no binding. A RETURNING insert that fails with
 *   SQLSTATE 42501 inserted nothing: Laravel's insert() once, no binding.
 * - MySQL and MariaDB: insertGetId(), with a statement counter on the
 *   connection (a beforeExecuting callback, registered once per connection
 *   instance, active only between the insert and the id read of a log()):
 *   when any other statement ran in that window, nothing is bound.
 * The receipt is read inside the watcher, and only an integer greater than
 * 0 is bound.
 *
 * The binding takes the occurrence slot's entry for the uuid (once) and runs
 * one guarded mirror statement, inside the watcher: `UPDATE orrery_jobs SET
 * provider_id = ?, provider_target = ? WHERE uuid = ? AND occurrence_id = ?
 * AND provider_id IS NULL`. Inside a transaction of the failed-record
 * connection (MySQL and SQLite) it is an item of the CommitDeferral: run at
 * the root's commit, dropped and counted on a rollback. Without a slot entry
 * the insert is Laravel's own and nothing is bound.
 *
 * `provider_target` is SqlFailedRecords::targetOf() of the configuration of
 * the connection object that performed the insert and this table.
 *
 * The class adds no property: FailerProvenance compares its (array) cast with
 * a public construction. The statement counters are a container singleton
 * (StatementCounters), and the SQLite version seam is a container binding.
 */
class CapturingUuidFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /** The first SQLite release with RETURNING. */
    public const SQLITE_RETURNING = '3.35.0';

    /** The watched operation of the binding statement. */
    public const OPERATION = 'provider:bind_id';

    /** The watched operation of the slot read. */
    public const SLOT_OPERATION = 'provider:slot';

    /** The container binding of the SQLite version seam (tests only). */
    public const SQLITE_VERSION_FOR_TESTING = 'orrery.testing.sqlite_version';

    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  Throwable  $exception
     * @return string|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $record = [
            'uuid' => $uuid = json_decode($payload, true)['uuid'],
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
            'failed_at' => Date::now(),
        ];

        $query = $this->getTable();
        $host = $query->getConnection();
        $occurrence = is_string($uuid) ? self::takeOccurrence($uuid) : null;

        // No slot entry, pretend mode, or a raw PDO transaction that Laravel does not
        // track (level 0): Laravel's own insert, and nothing is bound.
        if ($occurrence === null || ! $host instanceof Connection || $host->pretending() || self::untrackedTransaction($host)) {
            $query->insert($record);

            return $uuid;
        }

        switch ($host->getDriverName()) {
            case 'sqlite':
                if (! version_compare(self::sqliteVersion(), self::SQLITE_RETURNING, '>=')) {
                    $query->insert($record);

                    break;
                }

                $this->deferral()->around(
                    $host,
                    0,
                    fn (): array => $this->insertReturning($query, $host, $record),
                    fn (array $rows): ?Closure => $this->binding($host, $uuid, $occurrence, self::receipt($rows[0]->id ?? null)),
                    self::OPERATION,
                );

                break;
            case 'pgsql':
                if (PackageTransaction::isOpen($host) || ! $this->postgresCanReturn($query, $host)) {
                    $query->insert($record);

                    break;
                }

                try {
                    $rows = $this->insertReturning($query, $host, $record);
                } catch (QueryException $denied) {
                    if (! in_array($denied->errorInfo[0] ?? null, ['42501', '0A000'], true)) {
                        throw $denied;
                    }

                    // The privilege went between the read and the insert (42501), or a rule
                    // without RETURNING rewrites the insert (0A000): either inserted nothing.
                    $query->insert($record);

                    break;
                }

                Watcher::instance()->run(self::OPERATION, function () use ($host, $uuid, $occurrence, $rows): void {
                    $bind = $this->binding($host, $uuid, $occurrence, self::receipt($rows[0]->id ?? null));

                    if ($bind !== null) {
                        $bind();
                    }
                });

                break;
            case 'mysql':
            case 'mariadb':
                $this->deferral()->around(
                    $host,
                    0,
                    fn (): array => $this->countedInsertGetId($query, $host, $record),
                    fn (array $result): ?Closure => $result['statements'] === 1 ? $this->binding($host, $uuid, $occurrence, self::receipt($result['id'])) : null,
                    self::OPERATION,
                );

                break;
            default:
                $query->insert($record);
        }

        return $uuid;
    }

    /**
     * The version of the SQLite library (the test seam when it is set). The
     * library is the same for every SQLite PDO of the process, so an
     * in-memory PDO reports it without a statement and without a file.
     */
    public static function sqliteVersion(): string
    {
        $container = Container::getInstance();

        if ($container->bound(self::SQLITE_VERSION_FOR_TESTING)) {
            $seam = $container->make(self::SQLITE_VERSION_FOR_TESTING);

            if ($seam instanceof Closure) {
                $version = $seam();

                return is_string($version) ? $version : '0';
            }
        }

        $version = (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) ? $version : '0';
    }

    /**
     * Reports another SQLite version to the provider (null: the real one).
     *
     * @param  (Closure(): string)|null  $version
     */
    public static function useSqliteVersionForTesting(?Closure $version): void
    {
        $container = Container::getInstance();

        if ($version === null) {
            $container->forgetInstance(self::SQLITE_VERSION_FOR_TESTING);

            return;
        }

        $container->instance(self::SQLITE_VERSION_FOR_TESTING, $version);
    }

    /**
     * Only an integer greater than 0 is a receipt: a missing `id` column
     * makes SQLite return the string `id`, MySQL returns 0 without an
     * auto-increment column, and a trigger can give no row.
     */
    public static function receipt(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (is_string($id) && preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1) {
            return (int) $id;
        }

        return null;
    }

    /**
     * The binding statement of a receipt, or null when there is none.
     */
    private function binding(Connection $host, string $uuid, int $occurrence, ?int $id): ?Closure
    {
        if ($id === null) {
            return null;
        }

        // The connection object's own configuration and writer, never the current one.
        $target = SqlFailedRecords::targetOf(PackageSession::writerConfigOf($host->getConfig()), (string) $this->table);

        return static function () use ($uuid, $occurrence, $id, $target): void {
            Watcher::instance()->run(self::OPERATION, static function () use ($uuid, $occurrence, $id, $target): void {
                Container::getInstance()->make(PackageSession::class)->mirror()->table('orrery_jobs')
                    ->where('uuid', $uuid)
                    ->where('occurrence_id', $occurrence)
                    ->whereNull('provider_id')
                    ->update(['provider_id' => $id, 'provider_target' => $target]);
            });
        };
    }

    /**
     * Laravel's compiled insert plus `returning id`, as PostgresProcessor
     * runs an insertGetId().
     *
     * @param  array<string, mixed>  $record
     * @return array<int, mixed>
     */
    private function insertReturning(Builder $query, Connection $host, array $record): array
    {
        $query->applyBeforeQueryCallbacks();
        $grammar = $query->getGrammar();
        $sql = $grammar->compileInsert($query, [$record]).' returning '.$grammar->wrap('id');
        $host->recordsHaveBeenModified();

        return $host->selectFromWriteConnection($sql, $query->cleanBindings(Arr::flatten([$record], 1)));
    }

    /**
     * Whether the role may read the `id` of the row that it inserts: the
     * column exists, the SELECT privilege, and no row security. Any error,
     * and an open breaker, give false.
     */
    private function postgresCanReturn(Builder $query, Connection $host): bool
    {
        if (Watcher::instance()->breakerOpen()) {
            return false;
        }

        try {
            $name = $query->getGrammar()->wrapTable((string) $this->table);
            $row = $host->selectFromWriteConnection(
                "select case when exists (select 1 from pg_attribute where attrelid = to_regclass(?) and attname = 'id' and not attisdropped)"
                ." then has_column_privilege(to_regclass(?), 'id', 'SELECT') and not row_security_active(to_regclass(?)) else false end as orrery_returning",
                [$name, $name, $name],
            )[0] ?? null;

            return is_object($row) && ($row->orrery_returning ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * insertGetId() with the count of the statements of the connection
     * between the insert and the id read.
     *
     * @param  array<string, mixed>  $record
     * @return array{id: mixed, statements: int}
     */
    private function countedInsertGetId(Builder $query, Connection $host, array $record): array
    {
        [$id, $statements] = Container::getInstance()->make(StatementCounters::class)->count($host, static fn (): mixed => $query->insertGetId($record));

        return ['id' => $id, 'statements' => $statements];
    }

    /**
     * Whether the PDO of $host is in a transaction that Laravel does not
     * track (level 0): the deferral cannot see its commit or rollback.
     */
    private static function untrackedTransaction(Connection $host): bool
    {
        try {
            return $host->transactionLevel() === 0 && PackageTransaction::isOpen($host);
        } catch (Throwable) {
            return true;
        }
    }

    private static function takeOccurrence(string $uuid): ?int
    {
        $taken = Watcher::instance()->attempt(self::SLOT_OPERATION, static fn (): ?int => Container::getInstance()->make(OccurrenceSlot::class)->take($uuid));

        return $taken->succeeded() && is_int($taken->value()) ? $taken->value() : null;
    }

    private function deferral(): CommitDeferral
    {
        return Container::getInstance()->make(CommitDeferral::class);
    }
}
