<?php

namespace Justrau\Orrery\Monitoring;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Justrau\Orrery\Database\Schema\SchemaOutdated;
use Justrau\Orrery\Maintenance\Retention;
use Justrau\Orrery\Maintenance\TickBudget;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Throwable;

/**
 * The per-minute counts of orrery_samples (the tick phase
 * `sample_counts`, priority 97, before retention can delete a row).
 *
 * The frontier M is the settings row COUNTED_THROUGH: the end of the last
 * aggregated minute, integer epoch seconds. It only moves forward. The
 * settings row COUNTED_AT holds the time when M last moved (retention's
 * bound reads it).
 *
 * One run at the current minute start m_now works through the minutes from
 * min(m_now - 300, M) (without M: m_now - 300), but never before
 * m_now - `samples.retention_hours` (a minute older than that is retired,
 * and its invalid intervals may be pruned), up to m_now (the current minute
 * is not counted), oldest first, one minute per unit. It stops after 65
 * units, or when its time share (`samples.count_seconds`) or the tick budget
 * ends; the first unit always runs. So the last 5 minutes are recounted on
 * every run, and a catch-up covers at most 65 minutes per run. On a SQLite
 * file (Retention's `file` lock mode) the run pauses
 * Retention::PAUSE_MICROSECONDS before a unit, at most once per 150 ms of
 * work, so a waiting package writer gets the lock.
 *
 * Each minute m is one package transaction. Before its BEGIN, one plain
 * read of the frontier (INVALID, M, RETIRED_THROUGH) and, when that read
 * lets m be published, the two grouped reads of step 1 run in autocommit
 * (the hold point AFTER_READS follows them), so the transaction holds its
 * lock (on SQLite the file) only for the writes. In the transaction:
 * 0. Lock the settings row INVALID (lockForUpdate; created before the run
 *    when absent), then read the live M and RETIRED_THROUGH. The lock comes
 *    first, so these reads see the last committed values on every engine.
 *    Skip m (no write) when m < live M - 300, or without M when m < the
 *    current minute - 300 (it left the recount window, and retention may
 *    have trimmed its rows), or m <= RETIRED_THROUGH. When m is
 *    in an invalid interval, write nothing for m, raise M past the end of
 *    that interval (at most to m_now), and commit: one unit crosses the
 *    whole interval, so a long blind gap costs one unit, not one per minute.
 * 1. The reads before BEGIN stand when the version of INVALID, M and
 *    RETIRED_THROUGH under the lock equal the values read before them;
 *    else the minute is read again here. The reads: three grouped reads over
 *    [m, m + 60): the completed rows by finished_at
 *    (COUNT, SUM(runtime_ms), COUNT(runtime_ms)), the rows of every
 *    status by failed_at, and the rows of every status by pushed_at (the
 *    arrivals; the index `pushed`), each grouped byte-exactly by
 *    connection, queue and name (MySQL: CAST(... AS BINARY); Postgres and
 *    SQLite compare bytes under their default collations).
 * 2. In PHP: each connection is mapped to its primary alias
 *    (WaitTime::primaryAliases()); the sums per exact (connection, queue)
 *    and per exact name use ===, and each series gets its digest (series()).
 * 3. The minute's counts are replaced: a plain read finds the rows of m;
 *    the count columns (`pushed` included) of each counted `queue` row
 *    (a count or `pushed` above 0) that has no group now
 *    are set to 0 (its queue-state columns stay), and each `job` row that
 *    has no group now is deleted, both by primary key (so no MySQL gap lock
 *    reaches the current minute's queue state, which Samples::write()
 *    upserts without the INVALID lock); then the new `queue` rows (count
 *    columns only) and `job` rows are upserted, and the `total` row is
 *    upserted (its `pushed` is always a number, 0 for a quiet minute), in
 *    chunks of at most BINDINGS bindings (rows × bound columns).
 * 4. M is raised to m + 60 when that is higher, with COUNTED_AT.
 * 5. COMMIT.
 * A reader sees the previous complete minute or the new complete minute. A
 * failing minute rolls back and ends the run: M stays where it was.
 *
 * The clock (a design decision): m_now and COUNTED_AT come
 * from the PHP clock of the tick server, and the row times (finished_at,
 * failed_at) from the clocks of the workers, so M is not tied to the
 * database's clock. A tick server whose clock runs d seconds ahead moves M
 * d seconds early: for d below RECOUNT_SECONDS the recount of the next runs
 * corrects the minutes, and the retention floor (M - 300) still holds their
 * rows; a larger skew can publish a minute before its last rows arrive (an
 * undercount that the recount no longer reaches). docs/dashboard.md asks for
 * synchronised clocks (NTP). The database clock is not used, because a
 * minute's rows carry the workers' clocks, not the database's.
 *
 * The invalid intervals (INVALID, the JSON {version, intervals}): minutes
 * whose evidence was destroyed before it was counted. Retention adds them in
 * the transaction of its delete batch (addInvalid()) after it locked the
 * row; a `total` row never outranks them. They are kept while younger than
 * `samples.retention_hours`. A value that cannot be parsed counts as one
 * interval over every minute before the current one, and the next minute
 * transaction writes it so.
 *
 * The blind gaps: before the first unit, run() lets BlindGaps::decide()
 * turn a window without evidence of observation (longer than
 * BlindGaps::GAP_SECONDS since the last decision) into invalid intervals, so the
 * catch-up never publishes such a minute as a covered 0. A failed decision
 * fails the run before any minute is published, and a decision that stops
 * at its probe cap publishes no minute from the stop's minute on.
 *
 * The arrivals (`pushed`, schema step 7): a minute's arrivals are known only
 * when its `total` row has a non-null `pushed` (SampleCoverage decides that
 * from the `total` row); a minute counted before the step keeps null. On a
 * schema before step 7 a minute fails, and run() and clear() throw
 * SchemaOutdated (`schema_outdated`) instead of the query error.
 */
final class SampleCounts
{
    public const TABLE = 'orrery_samples';

    public const COUNTED_THROUGH = 'samples_counted_through';

    public const COUNTED_AT = 'samples_counted_at';

    public const INVALID = 'samples_invalid';

    /** Raised by the sample retention: a minute at or before it is unknown. */
    public const RETIRED_THROUGH = 'samples_retired_through';

    public const MINUTE = 60;

    /** The recount window: an observation up to 5 minutes late is counted. */
    public const RECOUNT_SECONDS = 300;

    public const MAX_MINUTES = 65;

    /** The most bindings of one write statement (old SQLite builds allow 999). */
    public const BINDINGS = 980;

    /** After a run chose a minute, before its transaction (tests only). */
    public const BEFORE_LOCK = 'samples.before_lock';

    /** Inside a minute's transaction, after the reads, before the writes (tests only). */
    public const BEFORE_PUBLISH = 'samples.before_publish';

    /** After a minute's reads before BEGIN, before its transaction (tests only). */
    public const AFTER_READS = 'samples.after_reads';

    /** After each write chunk of a minute (tests only). */
    public const AFTER_CHUNK = 'samples.after_chunk';

    /** Before a minute raises M (tests only). */
    public const BEFORE_FRONTIER = 'samples.before_frontier';

    /** Inside addInvalid(), before its write (tests only). */
    public const BEFORE_INVALID = 'samples.before_invalid';

    /** The most primary keys of one statement that zeroes or deletes the rows of a minute. */
    private const KEY_CHUNK = 500;

    /** The series digests: these flags, and hex for a string that is not UTF-8. */
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** The count columns of a minute; `pushed` is the arrivals. */
    private const COUNT_COLUMNS = ['completed', 'failed', 'runtime_ms_sum', 'runtime_count', 'pushed'];

    /** The column of schema step 7. */
    private const PUSHED = 'pushed';

    /** The watcher operation of a reader that found no `pushed` column (reported at most once per 60 s). */
    public const OUTDATED_OPERATION = 'samples:schema_outdated';

    /** How long a probe of the `pushed` column stands, on the monotonic clock. */
    private const COLUMN_PROBE_SECONDS = 60.0;

    /** @var array<string, array{missing: bool, at: float}> per connection name */
    private array $arrivalsColumn = [];

    private ?int $rowsPerChunkForTesting = null;

    private ?string $lockModeForTesting = null;

    /** @var (Closure(int): void)|null */
    private ?Closure $sleepForTesting = null;

    public function __construct(
        private readonly PackageTransaction $transactions,
        private readonly WaitTime $waits,
        private readonly BlindGaps $blindGaps,
    ) {}

    /**
     * One aggregation run. Returns the number of minutes that it published.
     */
    public function run(?TickBudget $budget = null): int
    {
        $session = Container::getInstance()->make(PackageSession::class)->mirror();
        $now = Clock::now();
        $current = self::minuteOf($now);
        $deadline = Clock::monotonic() + $this->countSeconds();
        self::ensureInvalidRow($session);
        // A window without observation becomes invalid minutes before the counts reach it (BlindGaps).
        // A walk stopped at its cap leaves the rest undecided: no minute from the stop's minute on is published.
        $limit = min($current, self::minuteOf($this->blindGaps->decide($session, $now, $this->retentionHours())));

        $through = self::countedThrough($session);
        $start = $through === null ? $current - self::RECOUNT_SECONDS : min($current - self::RECOUNT_SECONDS, $through);
        $start = max($start, self::minuteOf($now - $this->retentionHours() * 3600) + self::MINUTE);
        $aliases = null;
        $units = 0;
        $published = 0;
        $file = ($this->lockModeForTesting ?? Retention::lockModeOf($session)) === 'file';
        $sincePause = Clock::monotonic();

        for ($minute = $start; $minute < $limit && $units < self::MAX_MINUTES; $minute += self::MINUTE) {
            // The first unit always runs, so a run always makes progress.
            if ($units > 0 && self::stops($deadline, $budget)) {
                break;
            }

            // Every unit is a write transaction. On a SQLite file of its own, a waiting
            // package writer gets the lock in a pause before the next one, at most once
            // per 150 ms of work (Retention's rule).
            if ($units > 0 && $file && Clock::monotonic() - $sincePause >= Retention::PAUSE_MICROSECONDS / 1e6) {
                self::sleep(Retention::PAUSE_MICROSECONDS);
                $sincePause = Clock::monotonic();

                if (self::stops($deadline, $budget)) {
                    break;
                }
            }

            Hooks::reach(self::BEFORE_LOCK);
            $aliases ??= $this->waits->primaryAliases();
            $units++;

            [$done, $next] = self::outdatedOr($session, fn (): array => $this->minute($minute, $current, $aliases));

            if ($done) {
                $published++;
            }

            // A unit inside an invalid interval moves M past the whole interval: the next unit starts after it.
            $minute = max($minute, $next - self::MINUTE);
        }

        return $published;
    }

    /**
     * @phpstan-impure
     */
    private static function stops(float $deadline, ?TickBudget $budget): bool
    {
        return Clock::monotonic() >= $deadline || ($budget?->exhausted() ?? false);
    }

    private static function sleep(int $microseconds): void
    {
        $state = self::bound();

        if ($state->sleepForTesting !== null) {
            ($state->sleepForTesting)($microseconds);
        } else {
            usleep($microseconds);
        }
    }

    /**
     * The epoch second of the minute that holds $time.
     */
    public static function minuteOf(float $time): int
    {
        return (int) (floor($time / self::MINUTE) * self::MINUTE);
    }

    /**
     * The byte-exact series digest: sha256 of the JSON of [scope, ...parts]
     * (`queue`: [connection, queue]; `job`: [name]; `total`: none). A part
     * that is not valid UTF-8 is written as {"hex": ...}, so two different
     * byte strings never share a digest.
     *
     * @param  'total'|'queue'|'job'  $scope
     */
    public static function series(string $scope, string ...$parts): string
    {
        $identity = [$scope];

        foreach ($parts as $part) {
            $identity[] = mb_check_encoding($part, 'UTF-8') ? $part : ['hex' => bin2hex($part)];
        }

        return hash('sha256', json_encode($identity, self::JSON));
    }

    /**
     * M at its minute boundary, or null when absent.
     */
    public static function countedThrough(Connection $session): ?int
    {
        $value = $session->table('orrery_settings')->where('key', self::COUNTED_THROUGH)->value('value');

        return is_numeric($value) ? self::minuteOf((float) $value) : null;
    }

    /**
     * The time when M last moved, or null when absent.
     */
    public static function countedAt(Connection $session): ?float
    {
        $value = $session->table('orrery_settings')->where('key', self::COUNTED_AT)->value('value');

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Creates the settings row INVALID when it is absent, in autocommit,
     * before a transaction locks it.
     */
    public static function ensureInvalidRow(Connection $session): void
    {
        if (! $session->table('orrery_settings')->where('key', self::INVALID)->exists()) {
            $session->table('orrery_settings')->insertOrIgnore(['key' => self::INVALID, 'value' => self::encodeInvalid(0, [])]);
        }
    }

    /**
     * The invalid intervals inside the caller's package transaction, with a
     * write lock on their row (MySQL and Postgres; SQLite runs under BEGIN
     * IMMEDIATE). The caller calls ensureInvalidRow() before its
     * transaction. `corrupt` is true when the stored value could not be
     * parsed: the intervals then cover every minute before $current.
     *
     * @return array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}
     */
    public static function lockInvalid(Connection $tx, int $current): array
    {
        $value = $tx->table('orrery_settings')->where('key', self::INVALID)->lockForUpdate()->value('value');

        return self::parseInvalid($value, $current);
    }

    /**
     * The invalid intervals without a lock (readers).
     *
     * @return array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}
     */
    public static function invalid(Connection $session, int $current): array
    {
        return self::parseInvalid($session->table('orrery_settings')->where('key', self::INVALID)->value('value'), $current);
    }

    /**
     * Adds the minutes $minutes (epoch seconds at minute boundaries) to the
     * invalid intervals, merged into half-open intervals [from, to), drops
     * the intervals that ended before now - $retentionHours, and raises the
     * version. Runs inside the transaction that holds the lock of
     * lockInvalid(), whose result is $state.
     *
     * @param  array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}  $state
     * @param  list<int>  $minutes
     */
    public static function addInvalid(Connection $tx, array $state, array $minutes, float $now, int $retentionHours): void
    {
        $intervals = [];

        foreach (array_unique($minutes) as $minute) {
            $intervals[] = [$minute, $minute + self::MINUTE];
        }

        self::addInvalidIntervals($tx, $state, $intervals, $now, $retentionHours);
    }

    /**
     * addInvalid() for whole intervals [from, to) of minute boundaries.
     *
     * @param  array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}  $state
     * @param  list<array{0: int, 1: int}>  $added
     */
    public static function addInvalidIntervals(Connection $tx, array $state, array $added, float $now, int $retentionHours): void
    {
        if ($added === [] && ! $state['corrupt']) {
            return;
        }

        Hooks::reach(self::BEFORE_INVALID);

        $tx->table('orrery_settings')->where('key', self::INVALID)->update([
            'value' => self::encodeInvalid($state['version'] + 1, self::merge([...$state['intervals'], ...$added], self::minuteOf($now - $retentionHours * 3600))),
        ]);
    }

    /**
     * `orrery:clear-metrics`,
     * in one package transaction under the INVALID lock: deletes the `total`
     * and `job` rows, sets the count columns of the `queue` rows to 0 and
     * their arrivals (`pushed`) to null (their
     * queue state, the 24 h history and the over_limit_since carry, stay),
     * deletes M and COUNTED_AT, and keeps the invalid intervals, adding one
     * from now - `samples.retention_hours` to the current minute, with a new
     * version. So no minute before the clear reads as a covered zero, and
     * counting resumes at the current minute. A clear that races the
     * aggregation or a trim waits on the same settings row.
     */
    public function clear(): void
    {
        $sessions = Container::getInstance()->make(PackageSession::class);
        $session = $sessions->mirror();
        self::ensureInvalidRow($session);

        self::outdatedOr($session, fn () => $sessions->bounded($sessions->mirror(), fn (Connection $bounded) => $this->transactions->run($bounded, function (Connection $tx): void {
            $now = Clock::now();
            $current = self::minuteOf($now);
            $invalid = self::lockInvalid($tx, $current);

            $tx->table(self::TABLE)->whereIn('scope', ['total', 'job'])->delete();
            $tx->table(self::TABLE)->where('scope', 'queue')
                ->where(static function ($query): void {
                    foreach (self::COUNT_COLUMNS as $column) {
                        $query->orWhere($column, '>', 0);
                    }

                    $query->orWhereNotNull(self::PUSHED);
                })
                // A cleared minute's arrivals are unknown, never a covered 0.
                ->update([...array_fill_keys(self::COUNT_COLUMNS, 0), self::PUSHED => null]);
            $tx->table('orrery_settings')->whereIn('key', [self::COUNTED_THROUGH, self::COUNTED_AT])->delete();

            $hours = $this->retentionHours();
            self::addInvalidIntervals($tx, $invalid, [[self::minuteOf($now - $hours * 3600), $current]], $now, $hours);
        })));
    }

    /**
     * Runs $work; when it fails on a mirror whose orrery_samples has no
     * `pushed` column (a schema before step 7), throws SchemaOutdated
     * instead of the query error. The column is checked only on that failure.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function outdatedOr(Connection $session, Closure $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $error) {
            if (self::arrivalsMissing($session)) {
                throw self::outdated();
            }

            throw $error;
        }
    }

    /**
     * Whether the mirror's orrery_samples lacks `pushed` (a schema before
     * step 7). Callers ask only after a failed statement. The probe
     * is cached per connection name for COLUMN_PROBE_SECONDS (as
     * IncidentCleanup caches hasTable()); a probe that fails answers false,
     * so the original error stands.
     */
    public static function arrivalsMissing(Connection $session): bool
    {
        $state = self::bound();
        $key = (string) $session->getName();
        $now = Clock::monotonic();
        $cached = $state->arrivalsColumn[$key] ?? null;

        if ($cached !== null && $now - $cached['at'] < self::COLUMN_PROBE_SECONDS) {
            return $cached['missing'];
        }

        try {
            $missing = ! $session->getSchemaBuilder()->hasColumn(self::TABLE, self::PUSHED);
        } catch (Throwable) {
            return false;
        }

        $state->arrivalsColumn[$key] = ['missing' => $missing, 'at' => $now];

        return $missing;
    }

    /**
     * The error of a schema before step 7.
     */
    public static function outdated(): SchemaOutdated
    {
        return SchemaOutdated::column(self::TABLE, self::PUSHED);
    }

    /**
     * Whether $minute lies in one of the intervals.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    public static function covers(array $intervals, int $minute): bool
    {
        foreach ($intervals as [$from, $to]) {
            if ($minute >= $from && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The end of the interval that holds $minute, or null.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private static function coveringEnd(array $intervals, int $minute): ?int
    {
        $end = null;

        foreach ($intervals as [$from, $to]) {
            if ($minute >= $from && $minute < $to) {
                $end = max($end ?? $to, $to);
            }
        }

        return $end;
    }

    /**
     * Lowers the rows per write chunk (tests only); null restores it.
     */
    public static function useRowsPerChunkForTesting(?int $rows): void
    {
        self::bound()->rowsPerChunkForTesting = $rows;
    }

    /**
     * Sets the lock mode (`file` or `rows`, else Retention::lockModeOf()) and
     * a sleep spy that gets the microseconds of each pause (tests only).
     *
     * @param  'file'|'rows'|null  $mode
     * @param  (Closure(int): void)|null  $sleep
     */
    public static function useLockModeForTesting(?string $mode, ?Closure $sleep = null): void
    {
        $state = self::bound();

        $state->lockModeForTesting = $mode;
        $state->sleepForTesting = $sleep;
    }

    /**
     * The minute's transaction (steps 0 to 5 of the class comment). Returns
     * whether it published the minute, and the next minute to work on: the
     * end of the invalid interval that holds the minute (at most $current),
     * else the following minute.
     *
     * @param  array<string, string>  $aliases
     * @return array{0: bool, 1: int}
     */
    private function minute(int $minute, int $current, array $aliases): array
    {
        $sessions = Container::getInstance()->make(PackageSession::class);

        return $sessions->bounded($sessions->mirror(), function (Connection $session) use ($minute, $current, $aliases): array {
            // Before BEGIN: the frontier and, for a minute that it will publish, the two grouped
            // reads in autocommit. So the transaction (on SQLite the file lock) holds only the
            // writes, and no hot-path write waits behind the reads of a busy minute.
            $seen = self::frontierOf($session, $current);
            $pre = self::publishes($seen, $minute, $current) ? $this->read($session, $minute, $aliases) : null;
            Hooks::reach(self::AFTER_READS);

            return $this->transactions->run($session, function (Connection $tx) use ($minute, $current, $aliases, $seen, $pre): array {
                $invalid = self::lockInvalid($tx, $current);

                if ($invalid['corrupt']) {
                    // The stored intervals are lost: every earlier minute stays unknown.
                    self::addInvalid($tx, $invalid, [], Clock::now(), $this->retentionHours());
                }

                $live = self::countedThrough($tx);
                $retired = self::settingInt($tx, self::RETIRED_THROUGH);
                // Without M, retention keeps the rows from the current minute - 300 only.
                $window = ($live ?? self::minuteOf(Clock::now())) - self::RECOUNT_SECONDS;

                if ($minute < $window || ($retired !== null && $minute <= $retired)) {
                    // It left the live recount window: its stored counts stay, never recounted.
                    return [false, $minute + self::MINUTE];
                }

                $end = self::coveringEnd($invalid['intervals'], $minute);

                if ($end !== null) {
                    // Its evidence was destroyed (or never observed): no total row, so it stays unknown.
                    // M moves past the whole interval in this one unit, up to the current minute.
                    $next = max($minute + self::MINUTE, min($end, $current));
                    self::raise($tx, $live, $next);

                    return [false, $next];
                }

                // The reads before BEGIN stand only while the version of INVALID (a trim or a clear
                // raises it), M and RETIRED_THROUGH are what they were; else the minute is read again
                // under the lock.
                $unchanged = $pre !== null && ! $invalid['corrupt'] && $invalid['version'] === $seen['version'] && $live === $seen['through'] && $retired === $seen['retired'];
                [$queues, $jobs, $total] = $unchanged ? $pre : $this->read($tx, $minute, $aliases);
                Hooks::reach(self::BEFORE_PUBLISH);
                $this->write($tx, $minute, $queues, $jobs, $total);
                Hooks::reach(self::BEFORE_FRONTIER);
                self::raise($tx, $live, $minute + self::MINUTE);

                return [true, $minute + self::MINUTE];
            });
        });
    }

    /**
     * The frontier without a lock, in one statement: the version and the
     * intervals of INVALID, M and RETIRED_THROUGH.
     *
     * @return array{version: int, corrupt: bool, intervals: list<array{0: int, 1: int}>, through: ?int, retired: ?int}
     */
    private static function frontierOf(Connection $session, int $current): array
    {
        $values = $session->table('orrery_settings')
            ->whereIn('key', [self::INVALID, self::COUNTED_THROUGH, self::RETIRED_THROUGH])
            ->pluck('value', 'key')
            ->all();
        $invalid = self::parseInvalid($values[self::INVALID] ?? null, $current);
        $through = $values[self::COUNTED_THROUGH] ?? null;
        $retired = $values[self::RETIRED_THROUGH] ?? null;

        return [
            'version' => $invalid['version'],
            'corrupt' => $invalid['corrupt'],
            'intervals' => $invalid['intervals'],
            'through' => is_numeric($through) ? self::minuteOf((float) $through) : null,
            'retired' => is_numeric($retired) ? (int) $retired : null,
        ];
    }

    /**
     * Whether the frontier read before BEGIN lets the minute be published
     * (the transaction decides again under the lock).
     *
     * @param  array{version: int, corrupt: bool, intervals: list<array{0: int, 1: int}>, through: ?int, retired: ?int}  $seen
     */
    private static function publishes(array $seen, int $minute, int $current): bool
    {
        $window = ($seen['through'] ?? $current) - self::RECOUNT_SECONDS;

        return ! $seen['corrupt']
            && $minute >= $window
            && ($seen['retired'] === null || $minute > $seen['retired'])
            && ! self::covers($seen['intervals'], $minute);
    }

    /**
     * The grouped reads of one minute, summed per series.
     *
     * @param  array<string, string>  $aliases
     * @return array{0: array<string, array{connection: string, queue: string, completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}>, 1: array<string, array{name: string, completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}>, 2: array{completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}}
     */
    private function read(Connection $tx, int $minute, array $aliases): array
    {
        $from = Clock::toDecimal((float) $minute);
        $to = Clock::toDecimal((float) ($minute + self::MINUTE));
        // MySQL and MariaDB group by the select aliases: MariaDB's
        // ONLY_FULL_GROUP_BY does not match a selected CAST() with the same
        // CAST() in GROUP BY.
        $binary = in_array($tx->getDriverName(), ['mysql', 'mariadb'], true);
        $zero = ['completed' => 0, 'failed' => 0, 'runtime_ms_sum' => 0, 'runtime_count' => 0, 'pushed' => 0];
        $queues = [];
        $jobs = [];
        $total = $zero;

        $completions = $tx->table('orrery_jobs')
            ->where('status', 'completed')
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->selectRaw($binary
                ? 'CAST(`connection` AS BINARY) AS orrery_c, CAST(`queue` AS BINARY) AS orrery_q, CAST(`name` AS BINARY) AS orrery_n, COUNT(*) AS completed, SUM(`runtime_ms`) AS runtime_ms_sum, COUNT(`runtime_ms`) AS runtime_count'
                : '"connection" AS orrery_c, "queue" AS orrery_q, "name" AS orrery_n, COUNT(*) AS completed, SUM("runtime_ms") AS runtime_ms_sum, COUNT("runtime_ms") AS runtime_count')
            ->groupByRaw($binary ? 'orrery_c, orrery_q, orrery_n' : '"connection", "queue", "name"')
            ->get();

        $failures = $tx->table('orrery_jobs')
            ->whereIn('status', Throughput::STATUSES)
            ->where('failed_at', '>=', $from)
            ->where('failed_at', '<', $to)
            ->selectRaw($binary
                ? 'CAST(`connection` AS BINARY) AS orrery_c, CAST(`queue` AS BINARY) AS orrery_q, CAST(`name` AS BINARY) AS orrery_n, COUNT(*) AS failed'
                : '"connection" AS orrery_c, "queue" AS orrery_q, "name" AS orrery_n, COUNT(*) AS failed')
            ->groupByRaw($binary ? 'orrery_c, orrery_q, orrery_n' : '"connection", "queue", "name"')
            ->get();

        // The arrivals: every status by pushed_at, through the index `pushed`.
        $arrivals = $tx->table('orrery_jobs')
            ->where('pushed_at', '>=', $from)
            ->where('pushed_at', '<', $to)
            ->selectRaw($binary
                ? 'CAST(`connection` AS BINARY) AS orrery_c, CAST(`queue` AS BINARY) AS orrery_q, CAST(`name` AS BINARY) AS orrery_n, COUNT(*) AS pushed'
                : '"connection" AS orrery_c, "queue" AS orrery_q, "name" AS orrery_n, COUNT(*) AS pushed')
            ->groupByRaw($binary ? 'orrery_c, orrery_q, orrery_n' : '"connection", "queue", "name"')
            ->get();

        foreach ([$completions, $failures, $arrivals] as $rows) {
            foreach ($rows as $row) {
                $connection = (string) $row->orrery_c;
                $connection = $aliases[$connection] ?? $connection;
                $queue = (string) $row->orrery_q;
                $name = (string) $row->orrery_n;
                $add = [
                    'completed' => (int) ($row->completed ?? 0),
                    'failed' => (int) ($row->failed ?? 0),
                    'runtime_ms_sum' => (int) ($row->runtime_ms_sum ?? 0),
                    'runtime_count' => (int) ($row->runtime_count ?? 0),
                    'pushed' => (int) ($row->pushed ?? 0),
                ];
                $queueSeries = self::series('queue', $connection, $queue);
                $jobSeries = self::series('job', $name);
                $queues[$queueSeries] ??= ['connection' => $connection, 'queue' => $queue] + $zero;
                $jobs[$jobSeries] ??= ['name' => $name] + $zero;

                foreach (self::COUNT_COLUMNS as $column) {
                    $queues[$queueSeries][$column] += $add[$column];
                    $jobs[$jobSeries][$column] += $add[$column];
                    $total[$column] += $add[$column];
                }
            }
        }

        return [$queues, $jobs, $total];
    }

    /**
     * Replaces the counts of one minute (step 3 of the class comment).
     *
     * @param  array<string, array{connection: string, queue: string, completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}>  $queues
     * @param  array<string, array{name: string, completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}>  $jobs
     * @param  array{completed: int, failed: int, runtime_ms_sum: int, runtime_count: int, pushed: int}  $total
     */
    private function write(Connection $tx, int $minute, array $queues, array $jobs, array $total): void
    {
        // The rows that the minute holds now, by a plain read. Every writer of a count takes the
        // INVALID lock first, so this read sees every count that it replaces. Only the rows that
        // lose their counts are changed, each by its primary key: a range statement over the
        // minute (UPDATE or DELETE ... WHERE scope = ? AND minute = ?) would take MySQL gap
        // locks up to the next minute's rows, and the queue state of the current minute
        // (Samples::write()) would wait on them.
        $stale = ['queue' => [], 'job' => []];

        foreach ($tx->table(self::TABLE)->where('minute', $minute)->whereIn('scope', ['queue', 'job'])->get(['scope', 'series', ...self::COUNT_COLUMNS]) as $row) {
            $scope = (string) $row->scope;
            $series = (string) $row->series;
            // `pushed` counts too (null in a queue-state row): a push-only group whose rows vanished returns to 0.
            $counted = (int) $row->completed + (int) $row->failed + (int) $row->runtime_ms_sum + (int) $row->runtime_count + (int) $row->pushed > 0;

            if ($scope === 'queue' && $counted && ! isset($queues[$series])) {
                $stale['queue'][] = $series;
            } elseif ($scope === 'job' && ! isset($jobs[$series])) {
                $stale['job'][] = $series;
            }
        }

        foreach (array_chunk($stale['queue'], self::KEY_CHUNK) as $chunk) {
            // A group whose last row moved or vanished returns to 0; its queue state stays.
            $tx->table(self::TABLE)->where('scope', 'queue')->whereIn('series', $chunk)->where('minute', $minute)
                ->update(array_fill_keys(self::COUNT_COLUMNS, 0));
        }

        foreach (array_chunk($stale['job'], self::KEY_CHUNK) as $chunk) {
            $tx->table(self::TABLE)->where('scope', 'job')->whereIn('series', $chunk)->where('minute', $minute)->delete();
        }

        $queueRows = [];

        foreach ($queues as $series => $row) {
            $queueRows[] = ['scope' => 'queue', 'series' => $series, 'connection' => $row['connection'], 'queue' => $row['queue'], 'minute' => $minute]
                + array_intersect_key($row, array_flip(self::COUNT_COLUMNS));
        }

        foreach ($this->chunks($queueRows) as $chunk) {
            $tx->table(self::TABLE)->upsert($chunk, ['scope', 'series', 'minute'], self::COUNT_COLUMNS);
            Hooks::reach(self::AFTER_CHUNK);
        }

        $jobRows = [];

        foreach ($jobs as $series => $row) {
            $jobRows[] = ['scope' => 'job', 'series' => $series, 'name' => $row['name'], 'minute' => $minute]
                + array_intersect_key($row, array_flip(self::COUNT_COLUMNS));
        }

        foreach ($this->chunks($jobRows) as $chunk) {
            $tx->table(self::TABLE)->upsert($chunk, ['scope', 'series', 'minute'], self::COUNT_COLUMNS);
            Hooks::reach(self::AFTER_CHUNK);
        }

        $tx->table(self::TABLE)->upsert(
            [['scope' => 'total', 'series' => self::series('total'), 'minute' => $minute] + $total],
            ['scope', 'series', 'minute'],
            self::COUNT_COLUMNS,
        );
    }

    /**
     * The rows in chunks of at most BINDINGS bindings: each row binds one
     * value per column (the upsert's update clause binds none), so a chunk
     * holds BINDINGS ÷ the columns of a row (queue rows 10, job rows 9).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function chunks(array $rows): array
    {
        return $rows === [] ? [] : array_chunk($rows, max(1, $this->rowsPerChunkForTesting ?? intdiv(self::BINDINGS, count($rows[0]))));
    }

    /**
     * Raises M to $to, with COUNTED_AT, when $to is higher than the live M.
     * The caller holds the lock of INVALID, which every writer of M takes
     * first, so this read-then-write never loses a higher value.
     */
    private static function raise(Connection $tx, ?int $live, int $to): void
    {
        if ($live !== null && $to <= $live) {
            return;
        }

        $tx->table('orrery_settings')->upsert([
            ['key' => self::COUNTED_THROUGH, 'value' => (string) $to],
            ['key' => self::COUNTED_AT, 'value' => Clock::toDecimal(Clock::now())],
        ], ['key'], ['value']);
    }

    private static function settingInt(Connection $session, string $key): ?int
    {
        $value = $session->table('orrery_settings')->where('key', $key)->value('value');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The stored value of INVALID, parsed (readers: SampleCoverage). A value
     * that cannot be parsed covers every minute before $current.
     *
     * @return array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}
     */
    public static function parseInvalid(mixed $value, int $current): array
    {
        if ($value === null) {
            return ['version' => 0, 'intervals' => [], 'corrupt' => false];
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        $intervals = [];
        $valid = is_array($decoded) && is_int($decoded['version'] ?? null) && is_array($decoded['intervals'] ?? null);

        if ($valid) {
            foreach ($decoded['intervals'] as $interval) {
                if (! is_array($interval) || ! is_int($interval[0] ?? null) || ! is_int($interval[1] ?? null) || $interval[0] >= $interval[1]) {
                    $valid = false;

                    break;
                }

                $intervals[] = [$interval[0], $interval[1]];
            }
        }

        if (! $valid) {
            return ['version' => is_array($decoded) && is_int($decoded['version'] ?? null) ? $decoded['version'] : 0, 'intervals' => [[0, $current]], 'corrupt' => true];
        }

        return ['version' => $decoded['version'], 'intervals' => $intervals, 'corrupt' => false];
    }

    /**
     * Sorted, merged intervals; those that end at or before $horizon go.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function merge(array $intervals, int $horizon): array
    {
        usort($intervals, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];
        $open = null;

        foreach ($intervals as [$from, $to]) {
            if ($to <= $horizon) {
                continue;
            }

            if ($open !== null && $from <= $open[1]) {
                $open[1] = max($open[1], $to);
            } else {
                if ($open !== null) {
                    $merged[] = $open;
                }

                $open = [$from, $to];
            }
        }

        if ($open !== null) {
            $merged[] = $open;
        }

        return $merged;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private static function encodeInvalid(int $version, array $intervals): string
    {
        return json_encode(['version' => $version, 'intervals' => $intervals], JSON_THROW_ON_ERROR);
    }

    private function countSeconds(): float
    {
        $value = $this->config()->get('orrery.samples.count_seconds', 5);

        return is_numeric($value) && $value > 0 ? (float) $value : 5.0;
    }

    public function retentionHours(): int
    {
        $value = $this->config()->get('orrery.samples.retention_hours', 24);

        return is_numeric($value) && $value > 0 ? (int) $value : 24;
    }

    /**
     * `samples.count_wait_minutes`: retention's bound on the counts floor.
     */
    public function countWaitMinutes(): int
    {
        $value = $this->config()->get('orrery.samples.count_wait_minutes', 120);

        return is_numeric($value) && $value >= 0 ? (int) $value : 120;
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
