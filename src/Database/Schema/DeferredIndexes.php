<?php

namespace Justrau\Orrery\Database\Schema;

use Illuminate\Database\Connection;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Storage\Clock;

/**
 * Which deferred indexes (PackageSchema::DEFERRED_INDEXES)
 * the mirror lacks. A process singleton (StorageModule).
 *
 * - missing() reads the index catalog of orrery_jobs at most once per
 *   CACHE_SECONDS (60 s, on the monotonic clock) per session name, as
 *   IncidentCleanup caches hasTable(). A Postgres index that an interrupted
 *   `CREATE INDEX CONCURRENTLY` left INVALID (or not ready) counts as
 *   missing: the planner never uses it.
 * - recordOnce() records `index_missing` in the degradation record once per
 *   process, not as a degradation every tick: the readers that need
 *   an index (the stuck lists, the incident creation) answer `index_missing`
 *   with HINT instead.
 * - forget() drops the cache, for the build command of this process.
 *
 * The constructor opens no connection (BindingLifetimeTest).
 */
final class DeferredIndexes
{
    public const CACHE_SECONDS = 60.0;

    /** The command that builds the missing indexes. */
    public const COMMAND = 'php artisan orrery:install --indexes';

    /** The hint of a missing deferred index (/api/health `missing_indexes`, `index_missing`). */
    public const HINT = 'Run '.self::COMMAND.' to build the missing orrery_jobs indexes.';

    /** The degradation operation that recordOnce() records. */
    public const OPERATION = 'index_missing';

    /** @var array<string, array{missing: list<string>, at: float}> the catalog reads per session name and prefix */
    private array $reads = [];

    /** recordOnce() recorded `index_missing` in this process. */
    private bool $recorded = false;

    /**
     * The suffixes of PackageSchema::DEFERRED_INDEXES that the session's
     * orrery_jobs lacks (or holds as an invalid index), cached 60 s.
     *
     * @return list<string>
     */
    public function missing(Connection $session): array
    {
        $key = $session->getName()."\0".$session->getTablePrefix();
        $now = Clock::monotonic();
        $cached = $this->reads[$key] ?? null;

        if ($cached !== null && $now - $cached['at'] < self::CACHE_SECONDS) {
            return $cached['missing'];
        }

        $missing = self::read($session);
        $this->reads[$key] = ['missing' => $missing, 'at' => $now];

        return $missing;
    }

    /**
     * Whether the deferred index $suffix exists and is valid (cached as missing()).
     */
    public function has(Connection $session, string $suffix): bool
    {
        return ! in_array($suffix, $this->missing($session), true);
    }

    /**
     * Records `index_missing` once per process; later calls do nothing.
     * Returns whether this call recorded it.
     *
     * @param  list<string>  $missing
     */
    public function recordOnce(array $missing): bool
    {
        // Once per process: orrery:tick is a new process every minute, so a missing index is
        // recorded at most once per tick there; the incident phase dedups its own records.
        if ($this->recorded || $missing === []) {
            return false;
        }

        $this->recorded = true;
        Degradation::error(self::OPERATION, 'The orrery_jobs indexes '.implode(', ', $missing).' are missing. '.self::HINT);

        return true;
    }

    public function forget(): void
    {
        $this->reads = [];
    }

    /**
     * The missing suffixes from the catalog, without the cache. $schema
     * names the Postgres schema of orrery_jobs (else the search path's).
     * Each call reads the catalog again.
     *
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function read(Connection $session, ?string $schema = null): array
    {
        $valid = self::validIndexes($session, $schema);
        $missing = [];

        foreach (array_keys(PackageSchema::DEFERRED_INDEXES) as $suffix) {
            if (! in_array(strtolower(IndexName::for($session, 'orrery_jobs', $suffix)), $valid, true)) {
                $missing[] = $suffix;
            }
        }

        return $missing;
    }

    /**
     * The names (lower case) of the valid indexes of orrery_jobs. On
     * Postgres an index is valid when pg_index says `indisvalid` and
     * `indisready`; the other engines have no invalid index.
     *
     * @return list<string>
     */
    public static function validIndexes(Connection $session, ?string $schema = null): array
    {
        $builder = $session->getSchemaBuilder();

        if ($session->getDriverName() !== 'pgsql') {
            return array_map(static fn (array $index): string => strtolower((string) $index['name']), $builder->getIndexes('orrery_jobs'));
        }

        $schema ??= $builder->parseSchemaAndTable('orrery_jobs')[0];
        $table = $session->getTablePrefix().'orrery_jobs';
        $sql = 'select ic.relname as name from pg_index i '
            .'join pg_class tc on tc.oid = i.indrelid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'where i.indisvalid and i.indisready and ';
        // Without a schema, the table that the search path resolves (to_regclass), not current_schema(): an empty
        // schema first in the search path holds no orrery_jobs.
        $rows = $schema === null
            ? $session->select($sql.'tc.oid = to_regclass(?)', ['"'.str_replace('"', '""', $table).'"'])
            : $session->select($sql.'tc.relname = ? and tn.nspname = ?', [$table, $schema]);
        $names = [];

        foreach ($rows as $row) {
            $names[] = strtolower((string) (((array) $row)['name'] ?? ''));
        }

        return $names;
    }
}
