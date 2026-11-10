<?php

namespace Justrau\Orrery\Http\Bulk;

use Illuminate\Database\Connection;
use Justrau\Orrery\Database\Schema\PackageSchema;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\TrustedSql;
use stdClass;

/**
 * The audit rows of the bulk runs, `orrery_bulk_runs` on the mirror:
 * one row per run, created by the run's first
 * request with an insert-or-ignore, compared by every later request, and
 * counted by every page.
 *
 * - The services add the claims (Operations::countBulkClaim(), inside their
 *   claim transactions); this class adds the outcome counters of a page in
 *   one statement, with `last_run_at`.
 * - The counters sum the answers given, so a replayed page or a second
 *   client counts a member again; the ledger states of the run's operations
 *   (operationStates(), through the index (bulk_ref, state)) are the
 *   authority.
 * - The tick phase operation_retention deletes the runs whose last_run_at
 *   is older than orrery.operations.retention_days (deleteOlderThan(), at
 *   most RETENTION_ROWS per run, through the index (last_run_at, ref)).
 */
final class BulkRuns
{
    public const TABLE = PackageSchema::BULK_RUNS;

    /** The outcome counters, in the order of the responses. */
    public const COUNTERS = ['accepted', 'refused', 'uncertain', 'not_member', 'already_done', 'not_confirmed'];

    /** The runs of GET /api/bulk. */
    public const RECENT = 20;

    /** The most runs that one retention run deletes. */
    public const RETENTION_ROWS = 1000;

    /** The most refs that one delete binds: below SQLite's old limit of 999 variables. */
    public const DELETE_CHUNK = 900;

    /**
     * Whether the mirror has the objects of step 8: the table and
     * orrery_operations.bulk_ref. A schema before step 8 gives
     * `schema_outdated`.
     */
    public static function installed(Connection $mirror): bool
    {
        return self::outdated($mirror) === null;
    }

    /**
     * The SchemaOutdated of a mirror before step 8 (its missing object and
     * the upgrade path), or null when the table and the column exist.
     */
    public static function outdated(Connection $mirror): ?SchemaOutdated
    {
        $schema = $mirror->getSchemaBuilder();

        if (! $schema->hasTable(self::TABLE)) {
            return SchemaOutdated::column(self::TABLE, 'ref');
        }

        return $schema->hasColumn(Operations::TABLE, 'bulk_ref') ? null : SchemaOutdated::column(Operations::TABLE, 'bulk_ref');
    }

    /**
     * Creates the run's row unless it exists (a second client may have
     * created it first).
     */
    public static function create(Connection $mirror, FrozenToken $token, ?int $confirmed, ?string $actor, float $now): void
    {
        $at = Clock::toDecimal($now);
        $mirror->table(self::TABLE)->insertOrIgnore([
            'ref' => $token->ref,
            'action' => $token->action,
            'source' => $token->source->json(),
            'source_hash' => $token->sourceHash,
            'max_occurrence' => $token->maxOccurrence,
            'total' => $token->total['count'],
            'total_at_least' => $token->total['at_least'],
            'confirmed_total' => $confirmed,
            'actor' => $actor,
            'created_at' => $at,
            'last_run_at' => $at,
        ]);
    }

    public static function find(Connection $mirror, string $ref): ?stdClass
    {
        $row = $mirror->table(self::TABLE)->where('ref', $ref)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Whether a stored run is the run of this token and confirmation: the
     * action, the source hash, the occurrence bound and the confirmed total.
     */
    public static function matches(stdClass $row, FrozenToken $token, ?int $confirmed): bool
    {
        return $row->action === $token->action
            && hash_equals((string) $row->source_hash, $token->sourceHash)
            && (int) $row->max_occurrence === $token->maxOccurrence
            && ($row->confirmed_total === null ? null : (int) $row->confirmed_total) === $confirmed;
    }

    /**
     * Adds a page's outcome counts to the run's counters and sets
     * last_run_at, in one statement.
     *
     * @param  array<string, int>  $totals
     */
    public static function addCounters(Connection $mirror, string $ref, array $totals, float $now): void
    {
        $set = ['last_run_at' => Clock::toDecimal($now)];

        foreach (self::COUNTERS as $counter) {
            $add = (int) ($totals[$counter] ?? 0);

            if ($add > 0) {
                $set[$counter] = match ($counter) {
                    'accepted' => TrustedSql::expression('accepted + %s', $add),
                    'refused' => TrustedSql::expression('refused + %s', $add),
                    'uncertain' => TrustedSql::expression('uncertain + %s', $add),
                    'not_member' => TrustedSql::expression('not_member + %s', $add),
                    'already_done' => TrustedSql::expression('already_done + %s', $add),
                    default => TrustedSql::expression('not_confirmed + %s', $add),
                };
            }
        }

        $mirror->table(self::TABLE)->where('ref', $ref)->update($set);
    }

    /**
     * The runs whose last request is the newest, through the index
     * (last_run_at, ref) backwards.
     *
     * @return list<stdClass>
     */
    public static function recent(Connection $mirror, int $limit = self::RECENT): array
    {
        return array_values($mirror->table(self::TABLE)->orderByDesc('last_run_at')->orderByDesc('ref')->limit($limit)->get()->all());
    }

    /**
     * The run's operations by ledger state.
     *
     * @return array{claimed: int, published: int, done: int, aborting: int, aborted: int}
     */
    public static function operationStates(Connection $mirror, string $ref): array
    {
        $states = ['claimed' => 0, 'published' => 0, 'done' => 0, 'aborting' => 0, 'aborted' => 0];

        foreach ($mirror->table(Operations::TABLE)->where('bulk_ref', $ref)->groupBy('state')->selectRaw('state, count(*) as n')->get() as $row) {
            if (array_key_exists((string) $row->state, $states)) {
                $states[(string) $row->state] = (int) $row->n;
            }
        }

        return $states;
    }

    /**
     * Whether operationStates() can still hold every operation of the run:
     * false once the run started more than the ledger's retention ago, when
     * the operation retention may have deleted finished operations of it
     * (the counts are then lower bounds). $now is the mirror's database
     * clock, the clock of that retention (OperationRetentionPhase).
     */
    public static function operationsComplete(stdClass $row, float $retentionDays, float $now): bool
    {
        return (float) $row->created_at >= $now - $retentionDays * 86400;
    }

    /**
     * Deletes at most RETENTION_ROWS runs whose last_run_at is before the
     * cutoff, oldest first; the number deleted.
     */
    public static function deleteOlderThan(Connection $mirror, float $cutoff): int
    {
        $refs = $mirror->table(self::TABLE)
            ->where('last_run_at', '<', Clock::toDecimal($cutoff))
            ->orderBy('last_run_at')
            ->orderBy('ref')
            ->limit(self::RETENTION_ROWS)
            ->pluck('ref')
            ->all();

        if ($refs === []) {
            return 0;
        }

        $deleted = 0;

        // At most 900 refs per statement: SQLite before 3.32 takes at most 999 variables.
        foreach (array_chunk($refs, self::DELETE_CHUNK) as $chunk) {
            $deleted += $mirror->table(self::TABLE)->whereIn('ref', $chunk)->where('last_run_at', '<', Clock::toDecimal($cutoff))->delete();
        }

        return $deleted;
    }

    /**
     * The response form of a stored run.
     *
     * @return array<string, mixed>
     */
    public static function present(stdClass $row): array
    {
        $source = json_decode((string) $row->source, true);
        $counters = [];

        foreach (self::COUNTERS as $counter) {
            $counters[$counter] = (int) $row->{$counter};
        }

        return [
            'ref' => (string) $row->ref,
            'action' => (string) $row->action,
            'source' => is_array($source) ? $source : null,
            'max_occurrence' => (int) $row->max_occurrence,
            'total' => ['count' => (int) $row->total, 'at_least' => (bool) $row->total_at_least],
            'confirmed_total' => $row->confirmed_total === null ? null : (int) $row->confirmed_total,
            'claimed' => (int) $row->claimed,
            'counters' => $counters,
            'actor' => $row->actor === null ? null : (string) $row->actor,
            'created_at' => (float) $row->created_at,
            'last_run_at' => (float) $row->last_run_at,
        ];
    }
}
