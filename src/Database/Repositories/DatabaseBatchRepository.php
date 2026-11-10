<?php

namespace Justrau\Orrery\Database\Repositories;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository as LaravelDatabaseBatchRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\TrustedSql;
use stdClass;

/**
 * The data-only reader of the application's batch table (D18).
 *
 * - It reads the `job_batches` columns of COLUMNS, and `options` bounded
 *   by JobProjection::OPTIONS_BYTES (`CASE WHEN <bytes> <= <limit> THEN
 *   options END`, with `options_bytes`), on the package session of
 *   queue.batching.database, never on the application's connection. It
 *   never reads `failed_job_ids`.
 * - It never calls unserialize() and never builds a Laravel Batch:
 *   `options` is decoded only by Laravel's Postgres base64 rule
 *   (decodeOptions()); JobProjection::batch() parses it data-only.
 * - Only a bound repository of exactly Laravel's DatabaseBatchRepository
 *   class is read. Any other one (the DynamoDB repository, which
 *   unserializes `options` itself; a subclass; a wrapper) gives
 *   UNSUPPORTED_REASON, and is never called.
 * - On an application SQLite file that allows no automatic reads (D18),
 *   the batches are unknown (UNKNOWN_REASON), unless an operator action
 *   reads them.
 *
 * unknownReason() is null when the batches can be read. A missing table
 * and every other database error reach the caller as the QueryException
 * (or PooledRelationUnresolved) of the read.
 */
final class DatabaseBatchRepository
{
    public const UNKNOWN_REASON = 'sqlite_reads_not_allowed';

    /** The bound repository is not exactly Laravel's DatabaseBatchRepository. */
    public const UNSUPPORTED_REASON = 'unsupported_repository';

    /** The batches of one page, newest first (by id, as Laravel's repository orders them). */
    public const PAGE = 50;

    /** The batch columns that a viewer read selects, besides the bounded `options`. */
    public const COLUMNS = ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'created_at', 'cancelled_at', 'finished_at'];

    /** The escape character of the search's LIKE patterns (`ESCAPE '!'`): SQLite has no default one. */
    private const ESCAPE = '!';

    public function __construct(
        private readonly BatchRepository $bound,
        private readonly PackageSession $sessions,
        private readonly HostFiles $files,
        private readonly Repository $config,
    ) {}

    /**
     * Why the batches cannot be read (UNSUPPORTED_REASON or
     * UNKNOWN_REASON), or null when they can.
     */
    public function unknownReason(): ?string
    {
        if (! $this->supported()) {
            return self::UNSUPPORTED_REASON;
        }

        if ($this->sessions->operatorContext() === null && ! $this->files->allowsReads($this->connectionName())) {
            return self::UNKNOWN_REASON;
        }

        return null;
    }

    /**
     * Whether the bound repository is exactly Laravel's database repository.
     */
    public function supported(): bool
    {
        return $this->bound::class === LaravelDatabaseBatchRepository::class;
    }

    /**
     * One page of batches, newest first (id desc), before $beforeId when it
     * is given; with $search, the batches whose name or id contains it
     * literally (`%` and `_` are not wildcards). Call it only when
     * unknownReason() is null.
     *
     * @return list<stdClass>
     */
    public function page(?string $search, ?string $beforeId): array
    {
        return $this->sessions->bounded($this->session(), function (Connection $session) use ($search, $beforeId): array {
            $query = $this->select($session)->orderByDesc('id')->limit(self::PAGE);

            if ($beforeId !== null && $beforeId !== '') {
                $query->where('id', '<', $beforeId);
            }

            if ($search !== null && $search !== '') {
                $pattern = '%'.str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $search).'%';
                $mysql = in_array($session->getDriverName(), ['mysql', 'mariadb'], true);
                $query->where(static function (Builder $where) use ($mysql, $pattern): void {
                    $where->whereRaw($mysql ? "`name` LIKE ? ESCAPE '!'" : "\"name\" LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw($mysql ? "`id` LIKE ? ESCAPE '!'" : "\"id\" LIKE ? ESCAPE '!'", [$pattern]);
                });
            }

            return array_values(array_map(fn (object $row): stdClass => $this->decoded($session, $row), $query->get()->all()));
        });
    }

    /**
     * The batch with the exact id, or null. Call it only when
     * unknownReason() is null.
     */
    public function find(string $id): ?stdClass
    {
        return $this->sessions->bounded($this->session(), function (Connection $session) use ($id): ?stdClass {
            $row = $this->select($session)->where('id', $id)->first();

            // A byte-exact id: a collation's match of another letter case is another batch.
            return is_object($row) && (string) ($row->id ?? '') === $id ? $this->decoded($session, $row) : null;
        });
    }

    /**
     * The package session of the batch table, for the job detail's batch
     * name; null when the batches are unknown.
     */
    public function searchSession(): ?Connection
    {
        return $this->unknownReason() === null ? $this->session() : null;
    }

    public function table(): string
    {
        $table = $this->config->get('queue.batching.table');

        return is_string($table) && $table !== '' ? $table : 'job_batches';
    }

    /**
     * The package session of the batch connection: the operator session
     * inside an operator action, else the automatic one.
     */
    public function session(): Connection
    {
        return $this->sessions->forAction($this->connectionName());
    }

    public function connectionName(): string
    {
        $connection = $this->config->get('queue.batching.database');

        return is_string($connection) && $connection !== '' ? $connection : (string) $this->config->get('database.default');
    }

    /**
     * The stored options after Laravel's Postgres rule
     * (Illuminate\Bus\DatabaseBatchRepository::unserialize()): on Postgres a
     * text without `:` and `;` is base64 and is decoded; everything else is
     * the serialized text itself. Never unserialized.
     */
    public static function decodeOptions(?string $stored, string $driver): ?string
    {
        if ($stored === null || $driver !== 'pgsql' || str_contains($stored, ':') || str_contains($stored, ';')) {
            return $stored;
        }

        $decoded = base64_decode($stored, true);

        return $decoded === false ? $stored : $decoded;
    }

    private function select(Connection $session): Builder
    {
        return $session->table($this->table())->select([...self::COLUMNS, self::boundedOptions($session), self::optionsBytes($session)]);
    }

    private function decoded(Connection $session, object $row): stdClass
    {
        $decoded = new stdClass;

        foreach (self::COLUMNS as $column) {
            $decoded->{$column} = $row->{$column} ?? null;
        }

        $options = $row->options ?? null;
        $decoded->options = self::decodeOptions(is_string($options) ? $options : null, $session->getDriverName());
        $bytes = $row->options_bytes ?? null;
        $decoded->options_bytes = is_numeric($bytes) ? (int) $bytes : null;

        return $decoded;
    }

    /**
     * `CASE WHEN <bytes of options> <= OPTIONS_BYTES THEN options END AS
     * options`: the size decision and the value come from one row version,
     * and larger options are never loaded.
     *
     * @return Expression<literal-string>
     */
    private static function boundedOptions(Connection $session): Expression
    {
        return match ($session->getDriverName()) {
            'pgsql' => TrustedSql::expression('CASE WHEN OCTET_LENGTH("options") <= %s THEN "options" END AS "options"', JobProjection::OPTIONS_BYTES),
            'sqlite' => TrustedSql::expression('CASE WHEN LENGTH(CAST("options" AS BLOB)) <= %s THEN "options" END AS "options"', JobProjection::OPTIONS_BYTES),
            default => TrustedSql::expression('CASE WHEN LENGTH(`options`) <= %s THEN `options` END AS `options`', JobProjection::OPTIONS_BYTES),
        };
    }

    /**
     * @return Expression<literal-string>
     */
    private static function optionsBytes(Connection $session): Expression
    {
        return match ($session->getDriverName()) {
            'pgsql' => TrustedSql::expression('OCTET_LENGTH("options") AS "options_bytes"'),
            'sqlite' => TrustedSql::expression('LENGTH(CAST("options" AS BLOB)) AS "options_bytes"'),
            default => TrustedSql::expression('LENGTH(`options`) AS `options_bytes`'),
        };
    }
}
