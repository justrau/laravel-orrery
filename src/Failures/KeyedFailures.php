<?php

namespace Justrau\Orrery\Failures;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\EscapedValue;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\TrustedSql;

/**
 * The reads of failed rows through their stored key:
 * orrery_jobs.failure_key, which JobStore writes with every
 * failure. Every failed row has its key (the unkeyed
 * fallback scan is gone); FailureScan holds the projection, the caps and the message heads.
 *
 * - The scope. Every read compares the status, the key, the window
 *   [from, to) and the queue in SQL, before any limit or aggregate. The
 *   queue comparison is byte for byte: on MySQL and MariaDB also as
 *   CAST(... AS BINARY), because their column collation folds case and
 *   accents. Postgres and SQLite compare bytes under their deterministic
 *   collations already.
 * - The indexes. Without a queue scope the per-key reads seek on
 *   `failure` (status, failure_key, failed_at, id); with one, on
 *   `failure_scope` (status, connection, queue, failure_key, failed_at, id).
 * - The bound. Every per-key read is bounded by cap()
 *   (orrery.dashboard.group_count_cap): it reads the newest cap + 1 ids of
 *   the key in scope (failed_at desc, id desc), never all of them. `count`
 *   is min(that count, cap), and `capped` is true when it was larger. Only
 *   first_failed_at and last_failed_at are MIN and MAX over the key in
 *   scope: scalar seeks on the index (status, failure_key, failed_at).
 * - The values. The per-key statements of many keys are one UNION ALL, so
 *   every value is written into the SQL as a connection-escaped literal
 *   (TrustedSql, EscapedValue), never as a binding: 200 keys would pass the
 *   999 bindings of old SQLite builds. The keys are validated (64 lowercase
 *   hexadecimal characters) before they reach here.
 */
final class KeyedFailures
{
    /** The default of orrery.dashboard.group_count_cap. */
    public const DEFAULT_CAP = 5000;

    /** The most pairs of a group detail's `selection` (GroupReads::detail()). */
    public const SELECTION = 200;

    /**
     * The per-key bound: orrery.dashboard.group_count_cap when it is a whole
     * number of 1 or more, else DEFAULT_CAP. It is at least SELECTION, so a
     * key's count is never smaller than the pairs that a selection takes of it.
     */
    public static function cap(): int
    {
        $value = config('orrery.dashboard.group_count_cap', self::DEFAULT_CAP);

        if (is_string($value) && preg_match('/^[1-9]\d{0,8}$/D', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 1 ? max($value, self::SELECTION) : self::DEFAULT_CAP;
    }

    /**
     * The head scan of the keyed rows in scope: the newest FailureScan::CAP
     * rows, newest first, projected to id, failed_at, failure_key,
     * connection and queue. `truncated` is true when more rows exist; then
     * `oldest` is the failed_at of the oldest scanned row.
     *
     * @return array{rows: list<array{id: int, failed_at: float, key: string, queue: string}>, truncated: bool, oldest: float|null}
     */
    public static function scan(FailureScope $scope): array
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $raw = self::newest(self::scoped($connection, $scope, null))
            ->limit(FailureScan::CAP + 1)
            ->get(['id', 'failed_at', 'failure_key', 'connection', 'queue'])
            ->all();
        $truncated = count($raw) > FailureScan::CAP;
        $rows = [];
        $oldest = null;

        foreach (array_slice($raw, 0, FailureScan::CAP) as $row) {
            $oldest = (float) $row->failed_at;

            // The byte-exact check of FailureScope::contains(), as in FailureScan.
            if ($scope->connection !== null && ((string) $row->connection !== $scope->connection || (string) $row->queue !== $scope->queue)) {
                continue;
            }

            $rows[] = [
                'id' => (int) $row->id,
                'failed_at' => (float) $row->failed_at,
                'key' => (string) $row->failure_key,
                'queue' => $row->connection.':'.$row->queue,
            ];
        }

        return ['rows' => $rows, 'truncated' => $truncated, 'oldest' => $truncated ? $oldest : null];
    }

    /**
     * The per-key figures of the keys in scope, in one statement: `count`
     * (bounded by the cap), `capped`, `first_failed_at` and
     * `last_failed_at` (MIN and MAX over the key in scope), and, for a
     * capped key only, `counted_from`, the failed_at of the oldest counted
     * row (one more statement, only when a key is capped). With
     * $attempts, also the min and max attempts over the counted rows. A key
     * without keyed rows in scope has count 0 and null times.
     *
     * @param  list<string>  $keys
     * @return array<string, array{count: int, capped: bool, first_failed_at: float|null, last_failed_at: float|null, counted_from: float|null, attempts_min: int|null, attempts_max: int|null}>
     */
    public static function stats(FailureScope $scope, array $keys, bool $attempts = false): array
    {
        $figures = [];

        foreach ($keys as $key) {
            $figures[$key] = ['count' => 0, 'capped' => false, 'first_failed_at' => null, 'last_failed_at' => null, 'counted_from' => null, 'attempts_min' => null, 'attempts_max' => null];
        }

        if ($keys === []) {
            return $figures;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $cap = self::cap();
        $union = null;

        foreach ($keys as $key) {
            $counted = static fn (string $column): Builder => self::newest(self::scoped($connection, $scope, $key))->select($column)->limit($cap);
            $branch = $connection->query()
                ->select(self::literalAs($connection, $key, 'failure_key'))
                ->selectSub($connection->query()->fromSub(self::newest(self::scoped($connection, $scope, $key))->select('id')->limit($cap + 1), 'c')->selectRaw('count(*)'), 'total')
                ->selectSub(self::scoped($connection, $scope, $key)->selectRaw('min(failed_at)'), 'first_failed_at')
                ->selectSub(self::scoped($connection, $scope, $key)->selectRaw('max(failed_at)'), 'last_failed_at');

            if ($attempts) {
                $branch->selectSub($connection->query()->fromSub($counted('attempts'), 'a')->selectRaw('min(attempts)'), 'attempts_min')
                    ->selectSub($connection->query()->fromSub($counted('attempts'), 'b')->selectRaw('max(attempts)'), 'attempts_max');
            }

            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        foreach ($union->get() as $row) {
            $total = (int) $row->total;
            $figures[(string) $row->failure_key] = [
                'count' => min($total, $cap),
                'capped' => $total > $cap,
                'first_failed_at' => self::time($row->first_failed_at),
                'last_failed_at' => self::time($row->last_failed_at),
                'counted_from' => null,
                'attempts_min' => is_numeric($row->attempts_min ?? null) ? (int) $row->attempts_min : null,
                'attempts_max' => is_numeric($row->attempts_max ?? null) ? (int) $row->attempts_max : null,
            ];
        }

        $capped = array_keys(array_filter($figures, static fn (array $figure): bool => $figure['capped']));
        $union = null;

        foreach ($capped as $key) {
            $branch = $connection->query()
                ->select(self::literalAs($connection, (string) $key, 'failure_key'))
                ->selectSub($connection->query()->fromSub(self::newest(self::scoped($connection, $scope, (string) $key))->select('failed_at')->limit($cap), 'f')->selectRaw('min(failed_at)'), 'counted_from');
            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        foreach ($union?->get() ?? [] as $row) {
            $key = (string) $row->failure_key;

            if (isset($figures[$key])) {
                $figures[$key]['counted_from'] = self::time($row->counted_from);
            }
        }

        return $figures;
    }

    /**
     * The bucket counts of the counted rows of each key (the newest cap
     * rows in scope), in one statement grouped per key and bucket: key =>
     * [bucket start => count].
     *
     * @param  list<string>  $keys
     * @return array<string, array<int, int>>
     */
    public static function buckets(FailureScope $scope, array $keys, int $size): array
    {
        $counts = array_fill_keys($keys, []);

        if ($keys === []) {
            return $counts;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $cap = self::cap();
        // SQLite has no FLOOR() without its math extension; its CAST truncates, which is the floor of the positive times.
        $bucket = $connection->getDriverName() === 'sqlite'
            ? TrustedSql::expression('CAST(failed_at / %s AS INTEGER) AS bucket', $size)
            : TrustedSql::expression('FLOOR(failed_at / %s) AS bucket', $size);
        $union = null;

        foreach ($keys as $key) {
            $branch = $connection->query()
                ->fromSub(self::newest(self::scoped($connection, $scope, $key))->select('failed_at')->limit($cap), 'b')
                ->select([self::literalAs($connection, $key, 'failure_key'), $bucket])
                ->selectRaw('count(*) as n')
                ->groupBy('bucket');

            $union = $union === null ? $branch : $union->unionAll($branch);
        }

        foreach ($union->get() as $row) {
            $counts[(string) $row->failure_key][(int) $row->bucket * $size] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * The display rows (class, message head, name) of these ids, keyed by id.
     *
     * @param  list<int>  $ids
     * @return array<int, FailedRow>
     */
    public static function rowsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach (FailureScan::rows(FailureScan::project(Container::getInstance()->make(PackageSession::class)->mirror()->table(JobStore::TABLE))->whereIn('id', $ids)->get()->all()) as $row) {
            $rows[$row->id] = $row;
        }

        return $rows;
    }

    /**
     * The members of one key in scope, newest first (failed_at desc, id
     * desc): $limit rows from $offset.
     *
     * @return list<FailedRow>
     */
    public static function members(FailureScope $scope, string $key, int $limit, int $offset = 0): array
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $query = FailureScan::project(self::newest(self::scoped($connection, $scope, $key)))->limit($limit);

        if ($offset > 0) {
            $query->offset($offset);
        }

        return FailureScan::rows($query->get()->all());
    }

    /**
     * The failed rows in scope: with this key, or with any key ($key null).
     */
    private static function scoped(Connection $connection, FailureScope $scope, ?string $key): Builder
    {
        $query = $connection->table(JobStore::TABLE)->where('status', '=', self::literal($connection, 'failed'));

        if ($key === null) {
            $query->whereNotNull('failure_key');
        } else {
            $query->where('failure_key', '=', self::literal($connection, $key));
        }

        $query->where('failed_at', '>=', self::literal($connection, Clock::toDecimal($scope->from)))
            ->where('failed_at', '<', self::literal($connection, Clock::toDecimal($scope->to)));

        if ($scope->connection !== null) {
            $query->where('connection', '=', self::literal($connection, $scope->connection))
                ->where('queue', '=', self::literal($connection, (string) $scope->queue));

            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                // The column collation folds case and accents: compare the bytes too.
                $query->where(TrustedSql::expression('CAST(connection AS BINARY)'), '=', TrustedSql::expression('CAST(%s AS BINARY)', EscapedValue::of($connection, $scope->connection)))
                    ->where(TrustedSql::expression('CAST(queue AS BINARY)'), '=', TrustedSql::expression('CAST(%s AS BINARY)', EscapedValue::of($connection, (string) $scope->queue)));
            }
        }

        return $query;
    }

    private static function newest(Builder $query): Builder
    {
        return $query->orderByDesc('failed_at')->orderByDesc('id');
    }

    /**
     * @return Expression<literal-string>
     */
    private static function literal(Connection $connection, string $value): Expression
    {
        return TrustedSql::expression('%s', EscapedValue::of($connection, $value));
    }

    /**
     * @param  literal-string  $alias
     * @return Expression<literal-string>
     */
    private static function literalAs(Connection $connection, string $value, string $alias): Expression
    {
        return TrustedSql::expression('%s AS '.$alias, EscapedValue::of($connection, $value));
    }

    private static function time(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
