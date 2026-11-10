<?php

namespace Justrau\Orrery\Monitoring;

use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Justrau\Orrery\Workers\LiveWorkers;

/**
 * The queue state of the current minute in orrery_samples
 * (before: orrery_queue_samples), and the retention of every
 * sample row.
 *
 * Writing (the tick phase `samples`, 405):
 * - One `queue` row per measured queue at the minute boundary of the
 *   measurement: the series of the primary connection of the measured row
 *   (WaitTime groups the aliases of one jobs table) and the queue (the
 *   byte-exact digest of SampleCounts::series()), with `ready` (the
 *   executable backlog), `oldest_age`, `workers` (the live workers
 *   subscribed to the queue on any alias) and `over_limit`
 *   (WaitTime::overLimit()). Only these state columns are written: the
 *   counts of the minute come later from the aggregation (`sample_counts`),
 *   which writes only the count columns. A row whose state columns are null
 *   was not sampled.
 * - A row with an unknown backlog (`backlog_unknown`) writes no
 *   state: its minute stays unsampled.
 * - `over_limit_since` and `over_limit_since_censored`: the
 *   start of the over-limit run, carried from the previous minute's state of
 *   the same series. For a row over its limit: the previous minute sampled
 *   over its limit gives its two values; sampled under its limit gives this
 *   minute, not censored; not sampled (a gap, or the first sample) gives
 *   this minute, censored. A row under its limit has null and false. The
 *   previous minute is read once per run, before the first chunk.
 * - An upsert on the primary key (scope, series, minute): a repeated minute
 *   overwrites its state. Missed minutes are never backfilled.
 * - At most ROW_CAP rows per run, in chunks of CHUNK rows (at most 980
 *   bindings per statement), each chunk in one package transaction with the
 *   cursor, so a deadline stop after a chunk still moves the cursor. The
 *   budget is checked before each chunk.
 * - Fairness: the rows are ordered by the bytes of (connection, queue). The
 *   cursor in orrery_settings (CURSOR_KEY) holds the last written queue. A
 *   run starts after it and wraps around, so a cap or deadline stop never
 *   starves the queues at the end of the order.
 * - Locks: a chunk takes no INVALID lock, and it touches only rows of the
 *   current minute. The aggregation (SampleCounts) changes only the rows of
 *   earlier minutes: it zeroes and deletes them by primary key (point
 *   lookups, so no range or gap lock over a minute) and upserts the rest.
 *   On MySQL the upsert of an existing row also locks the gap before that
 *   row, which holds a current-minute key only when the row is the oldest
 *   one of its series; so a current-minute write waits on a minute
 *   transaction at most in that case, for the length of that transaction
 *   (a new series, short). SampleCountsTest proves the common case on MySQL
 *   (by design).
 *
 * Retention (the tick phase `sample_retention`, 870; retire()): the minutes
 * older than `samples.retention_hours`, oldest first, at most
 * RETENTION_MAX_ROWS rows per run. Each minute is retired first: one
 * transaction, under the INVALID lock that every count writer takes, deletes
 * its `total` row and raises RETIRED_THROUGH to it, so every reader treats it
 * as unknown from then on. Its other rows are then deleted in batches; a
 * minute with more rows than one run deletes is finished by the next runs
 * before the next minute is retired (the durable cursor is RETIRED_THROUGH).
 * Retention never raises the version of INVALID, so a window far from the
 * retention edge never reads as unknown.
 *
 * Reading: latestRuns() for the workload (one statement for all rows), and
 * history() for the history endpoint.
 */
final class Samples
{
    public const TABLE = SampleCounts::TABLE;

    public const ROW_CAP = 1000;

    /** 89 rows x 11 columns = 979 bindings: at most 999 per statement (old SQLite builds). */
    public const CHUNK = 89;

    public const RETENTION_MAX_ROWS = 5000;

    /** A latest sample older than this is stale: latestRuns() leaves it out. */
    public const FRESH_SECONDS = 180;

    public const BUCKET_SECONDS = SampleCounts::MINUTE;

    public const CURSOR_KEY = 'queue_samples_cursor';

    /** The most primary keys of one retention delete. */
    private const KEY_CHUNK = 500;

    /** After a minute was retired, before its other rows are deleted (tests only). */
    public const AFTER_RETIRE = 'samples.after_retire';

    private const STATE_COLUMNS = ['ready', 'oldest_age', 'workers', 'over_limit', 'over_limit_since', 'over_limit_since_censored'];

    private ?int $rowCap = null;

    private ?int $chunk = null;

    private ?int $retentionRows = null;

    public function __construct(
        private readonly PackageTransaction $transaction,
        private readonly SampleCounts $counts,
        private readonly WaitTime $waits,
    ) {
    }

    /**
     * Lowers ROW_CAP (tests only). null restores it.
     */
    public static function useRowCapForTesting(?int $cap): void
    {
        self::bound()->rowCap = $cap;
    }

    /**
     * Lowers CHUNK (tests only). null restores it.
     */
    public static function useChunkForTesting(?int $chunk): void
    {
        self::bound()->chunk = $chunk;
    }

    /**
     * Lowers RETENTION_MAX_ROWS (tests only). null restores it.
     */
    public static function useRetentionRowsForTesting(?int $rows): void
    {
        self::bound()->retentionRows = $rows;
    }

    /**
     * Writes the queue state of one measurement. Returns the number of rows
     * written. $session is the package session (null); a test passes
     * another session of the same database to model a second process.
     *
     * @param  list<array{connection: string, connections: list<string>, queue: string, backlog: ?int, oldest_age: ?float, threshold: int, backlog_unknown?: bool}>  $rows
     * @param  list<object{connection: string, subscribed_queues: list<string>}>  $workers
     */
    public function write(array $rows, array $workers, float $measuredAt, TickBudget $budget, ?Connection $session = null): int
    {
        $session ??= Container::getInstance()->make(PackageSession::class)->mirror();
        $known = [];

        foreach ($rows as $row) {
            if (! ($row['backlog_unknown'] ?? false) && $row['backlog'] !== null) {
                $known[] = [...$row, 'backlog' => $row['backlog']];
            }
        }

        $rows = $known;

        if ($rows === [] || $budget->exhausted()) {
            return 0;
        }

        usort($rows, static fn (array $a, array $b): int => self::compare($a['connection'], $a['queue'], $b['connection'], $b['queue']));

        $ordered = $this->fromCursor($session, $rows);
        $ordered = array_slice($ordered, 0, max(0, $this->rowCap ?? self::ROW_CAP));
        $minute = SampleCounts::minuteOf($measuredAt);
        $previous = $this->previousMinute($session, $minute - self::BUCKET_SECONDS);
        $written = 0;

        foreach (array_chunk($ordered, max(1, $this->chunk ?? self::CHUNK)) as $chunk) {
            if ($budget->exhausted()) {
                break;
            }

            $records = [];

            foreach ($chunk as $row) {
                $series = SampleCounts::series('queue', $row['connection'], $row['queue']);
                $overLimit = WaitTime::overLimit($row);
                [$since, $censored] = $overLimit ? self::carried($previous[$series] ?? null, $minute) : [null, false];

                $records[] = [
                    'scope' => 'queue',
                    'series' => $series,
                    'connection' => $row['connection'],
                    'queue' => $row['queue'],
                    'minute' => $minute,
                    'ready' => $row['backlog'],
                    'oldest_age' => $row['oldest_age'] === null ? null : Clock::toDecimal($row['oldest_age']),
                    'workers' => LiveWorkers::subscribed($workers, $row['connections'], $row['queue']),
                    'over_limit' => $overLimit,
                    'over_limit_since' => $since,
                    'over_limit_since_censored' => $censored,
                ];
            }

            $last = $chunk[count($chunk) - 1];
            $cursor = self::cursorValue($last['connection'], $last['queue']);

            $this->transaction->run($session, static function (Connection $tx) use ($records, $cursor): void {
                $tx->table(self::TABLE)->upsert($records, ['scope', 'series', 'minute'], self::STATE_COLUMNS);
                $tx->table('orrery_settings')->upsert([['key' => self::CURSOR_KEY, 'value' => $cursor]], ['key'], ['value']);
            });

            $written += count($records);
        }

        return $written;
    }

    /**
     * The sample retention: retires the minutes before now -
     * `samples.retention_hours`, oldest first, and deletes their rows, at
     * most RETENTION_MAX_ROWS rows. Returns the number of deleted rows.
     */
    public function retire(TickBudget $budget): int
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();
        $limit = max(1, $this->retentionRows ?? self::RETENTION_MAX_ROWS);
        $cutoff = Clock::now() - $this->counts->retentionHours() * 3600;
        $deleted = 0;

        while ($deleted < $limit && ! $budget->exhausted()) {
            $retired = self::retiredThrough($session);

            // First the rest of the minutes that are retired already.
            if ($retired !== null) {
                $removed = $this->deleteRetired($session, $retired, $limit - $deleted);
                $deleted += $removed['deleted'];

                if (! $removed['done']) {
                    break;
                }
            }

            $next = $session->table(self::TABLE)
                ->when($retired !== null, static fn ($query) => $query->where('minute', '>', $retired))
                ->where('minute', '<', (int) floor($cutoff))
                ->min('minute');

            if (! is_numeric($next) || (int) $next >= $cutoff) {
                break;
            }

            $deleted += $this->retireMinute($session, (int) $next);
            Hooks::reach(self::AFTER_RETIRE);
        }

        return $deleted;
    }

    /**
     * The over-limit runs of the latest fresh sample of every queue,
     * keyed `connection\0queue` (the primary connection of a
     * WaitTime row, as the samples store it).
     *
     * - One statement: the sampled `queue` rows with minute >= $now -
     *   FRESH_SECONDS. The latest sample per series is chosen in PHP.
     * - A queue appears only when that latest sample is over its limit and
     *   carries its start: `since` (integer epoch seconds at a minute
     *   boundary) and `censored` (the run may be longer than the samples
     *   show, so the UI says "at least since").
     * - It throws on a database error; the caller decides how to degrade.
     *
     * @return array<string, array{since: int, censored: bool}>
     */
    public function latestRuns(?float $now = null): array
    {
        $now ??= Clock::now();
        $latest = [];

        foreach (Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)
            ->where('scope', 'queue')
            ->where('minute', '>=', (int) ceil($now - self::FRESH_SECONDS))
            ->whereNotNull('over_limit')
            ->get(['series', 'connection', 'queue', 'minute', 'over_limit', 'over_limit_since', 'over_limit_since_censored']) as $row) {
            $series = (string) $row->series;

            if (! isset($latest[$series]) || (int) $row->minute > (int) $latest[$series]->minute) {
                $latest[$series] = $row;
            }
        }

        $runs = [];

        foreach ($latest as $row) {
            if ((bool) $row->over_limit && $row->over_limit_since !== null) {
                $runs[$row->connection."\0".$row->queue] = ['since' => (int) $row->over_limit_since, 'censored' => (bool) $row->over_limit_since_censored];
            }
        }

        return $runs;
    }

    /**
     * The sampled state of one queue series with minute in [$from, $to),
     * oldest first. $connection may be any alias of a shared jobs table: the
     * samples store the primary one.
     *
     * @return list<array{at: int, ready: int, oldest_age: ?float, workers: int, over_limit: bool}>
     */
    public function history(string $connection, string $queue, int $from, float $to): array
    {
        $connection = $this->waits->primaryAliases()[$connection] ?? $connection;
        $rows = Container::getInstance()->make(PackageSession::class)->mirror()->table(self::TABLE)
            ->where('scope', 'queue')
            ->where('series', SampleCounts::series('queue', $connection, $queue))
            ->where('minute', '>=', $from)
            ->where('minute', '<', (int) ceil($to))
            ->whereNotNull('over_limit')
            ->orderBy('minute')
            ->get(['minute', 'ready', 'oldest_age', 'workers', 'over_limit']);

        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'at' => (int) $row->minute,
                'ready' => (int) $row->ready,
                'oldest_age' => $row->oldest_age === null ? null : (float) $row->oldest_age,
                'workers' => (int) $row->workers,
                'over_limit' => (bool) $row->over_limit,
            ];
        }

        return $result;
    }

    public static function cursorValue(string $connection, string $queue): string
    {
        return (string) json_encode([$connection, $queue], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function instance(): self
    {
        return Container::getInstance()->make(self::class);
    }

    /**
     * The sampled state of the previous minute, keyed by series: one statement.
     *
     * @return array<string, \stdClass>
     */
    private function previousMinute(Connection $session, int $minute): array
    {
        $rows = [];

        foreach ($session->table(self::TABLE)
            ->where('scope', 'queue')
            ->where('minute', $minute)
            ->whereNotNull('over_limit')
            ->get(['series', 'over_limit', 'over_limit_since', 'over_limit_since_censored']) as $row) {
            $rows[(string) $row->series] = $row;
        }

        return $rows;
    }

    /**
     * [since, censored] of a row over its limit, from the previous minute's sample.
     *
     * @return array{0: int, 1: bool}
     */
    private static function carried(?\stdClass $previous, int $minute): array
    {
        if ($previous === null) {
            // A gap, or the queue's first sample: the run's start is not seen.
            return [$minute, true];
        }

        if (! (bool) $previous->over_limit) {
            return [$minute, false];
        }

        if ($previous->over_limit_since === null) {
            // An over-limit sample without a start: not seen.
            return [$minute, true];
        }

        return [(int) $previous->over_limit_since, (bool) $previous->over_limit_since_censored];
    }

    /**
     * The rows that follow the stored cursor, then the rows up to and including it.
     *
     * @template TRow of array{connection: string, queue: string}
     *
     * @param  list<TRow>  $rows  ordered by (connection, queue)
     * @return list<TRow>
     */
    private function fromCursor(Connection $session, array $rows): array
    {
        $value = $session->table('orrery_settings')->where('key', self::CURSOR_KEY)->value('value');
        $cursor = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($cursor) || count($cursor) !== 2 || ! is_string($cursor[0] ?? null) || ! is_string($cursor[1] ?? null)) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if (self::compare($row['connection'], $row['queue'], $cursor[0], $cursor[1]) > 0) {
                // The rotation as a key sort: it keeps the row type on every supported PHPStan.
                uksort($rows, static fn (int $a, int $b): int => [$a < $index, $a] <=> [$b < $index, $b]);

                return array_values($rows);
            }
        }

        return $rows;
    }

    /**
     * The byte order of (connection, queue): never PHP's numeric comparison of numeric strings.
     */
    private static function compare(string $connection, string $queue, string $otherConnection, string $otherQueue): int
    {
        return strcmp($connection, $otherConnection) ?: strcmp($queue, $otherQueue);
    }

    private static function retiredThrough(Connection $session): ?int
    {
        $value = $session->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->value('value');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Retires one minute: under the INVALID lock, deletes its `total` row and
     * raises RETIRED_THROUGH to it, in one transaction. Returns the deleted
     * rows (0 or 1).
     */
    private function retireMinute(Connection $session, int $minute): int
    {
        SampleCounts::ensureInvalidRow($session);

        $sessions = Container::getInstance()->make(PackageSession::class);

        return $sessions->bounded($sessions->mirror(), fn (Connection $bounded): int => $this->transaction->run($bounded, static function (Connection $tx) use ($minute): int {
            SampleCounts::lockInvalid($tx, SampleCounts::minuteOf(Clock::now()));
            $deleted = $tx->table(self::TABLE)->where('scope', 'total')->where('series', SampleCounts::series('total'))->where('minute', $minute)->delete();
            $updated = $tx->table('orrery_settings')->where('key', SampleCounts::RETIRED_THROUGH)->update(['value' => (string) $minute]);

            if ($updated === 0) {
                $tx->table('orrery_settings')->insertOrIgnore(['key' => SampleCounts::RETIRED_THROUGH, 'value' => (string) $minute]);
            }

            return $deleted;
        }));
    }

    /**
     * Deletes at most $limit rows of the retired minutes (minute <=
     * $retired), oldest first, by primary key. `done` is true when none is
     * left.
     *
     * @return array{deleted: int, done: bool}
     */
    private function deleteRetired(Connection $session, int $retired, int $limit): array
    {
        if ($limit <= 0) {
            return ['deleted' => 0, 'done' => false];
        }

        $keys = $session->table(self::TABLE)
            ->where('minute', '<=', $retired)
            ->orderBy('minute')
            ->limit($limit + 1)
            ->get(['scope', 'series', 'minute'])
            ->all();

        $more = count($keys) > $limit;
        $keys = array_slice($keys, 0, $limit);
        $groups = [];

        foreach ($keys as $key) {
            $groups[(int) $key->minute."\0".$key->scope][] = (string) $key->series;
        }

        $deleted = 0;

        foreach ($groups as $group => $series) {
            [$minute, $scope] = explode("\0", (string) $group, 2);

            foreach (array_chunk($series, self::KEY_CHUNK) as $chunk) {
                $deleted += $session->table(self::TABLE)
                    ->where('scope', $scope)
                    ->whereIn('series', $chunk)
                    ->where('minute', (int) $minute)
                    ->delete();
            }
        }

        return ['deleted' => $deleted, 'done' => ! $more];
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
