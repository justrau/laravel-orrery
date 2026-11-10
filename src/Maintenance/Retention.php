<?php

namespace Justrau\Orrery\Maintenance;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Monitoring\MonitoringRepair;
use Justrau\Orrery\Monitoring\MonitoringVersion;
use Justrau\Orrery\Monitoring\SampleCounts;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use UnexpectedValueException;

/**
 * Partitioned retention of the mirror rows.
 *
 * Partitions:
 * - A partition is (connection, queue, effective state, monitored),
 *   discovered from the mirror. Only `completed`, `failed` and `unknown`
 *   rows are trimmed: `pending`, `reserved` and `retrying` rows never are,
 *   whatever their finished_at, and neither is a row that an operation
 *   holds (`operation_id` set: a forget of a failed row).
 * - Each partition has its own cutoff (now minus its RetentionPolicy
 *   minutes, which apply the `orrery.trim_overrides` of its connection and
 *   queue, epoch seconds bound through Clock::toDecimal(), so no timezone
 *   takes part) and its own cursor, the settings row cursorKey():
 *   `retention_cursor:` and the SHA-256 of the serialized (connection,
 *   queue, state, monitored). Its JSON
 *   value holds one (finished_at, id) position per `silenced` value (the
 *   keys `plain` and `silenced`): the `failed` and `unknown` states hold
 *   both values, and each value is its own range of the index (connection,
 *   queue, status, silenced, monitored, finished_at, id). A traversal that
 *   reaches the end of a range clears its position, so the next tick starts
 *   again at the oldest row.
 * - Partition discovery seeks the index (connection, queue, status,
 *   silenced, monitored, …) one tuple at a time: a row-value comparison
 *   `(connection, queue, status, silenced, monitored) > (…)` with ORDER BY
 *   and LIMIT 1 (MySQL and MariaDB: the same comparison written as nested
 *   OR terms, which the range optimizer serves). So discovery costs one
 *   index seek per distinct tuple, never a scan of the whole index. A seek
 *   that returns a tuple again throws, so a wrong predicate never loops.
 * - One run takes one batch of each partition in turn, until every
 *   partition is done, or 10 seconds pass, or the tick budget ends. A run
 *   has no row cap. So protected rows never consume
 *   another partition's budget. The first batch always runs, also when the
 *   share or the budget is already used up, so a run with a due partition
 *   always deletes or checks one batch.
 * - The start rotates: the settings row START_KEY holds the tuple of the
 *   last partition that got a batch, and the next run starts at the first
 *   partition after it (wrapping). So the later partitions get batches too.
 * - A range that reaches its end from a stored cursor is finished only
 *   after one LIMIT 1 read from the start of the range finds no due row (a
 *   row that became due before the cursor, for example). Else its cursor is
 *   cleared and the range stays active.
 * - The write lock (lockMode()). A mirror on the SQLite file of a
 *   connection that the package uses is refused (D17), so a
 *   SQLite mirror is always a file of its own (the sibling file by default):
 *   - `file`: the mirror is a SQLite file of its own. The run waits
 *     PAUSE_MICROSECONDS (150 ms) before a batch that follows a write
 *     transaction, but at most once per 150 ms of work. A waiting package
 *     writer (BEGIN IMMEDIATE) gets the lock in that pause: its busy
 *     handler sleeps at most 100 ms between its tries. The short
 *     transactions of many small partitions share one pause.
 *   - `rows`: MySQL, Postgres and in-memory SQLite lock rows or have no
 *     other process, and do not pause.
 *
 * The counts floor (countsFloor()). The per-minute sample
 * counts (Monitoring\SampleCounts) read the finished rows and the arrivals,
 * so retention keeps a row whose finished_at, failed_at or pushed_at is at or
 * after M - 300 while M moved at most max(the partition's retention,
 * `samples.count_wait_minutes`) ago; without M the floor is the current
 * minute - 300. The floor lowers the partition's cutoff (finished_at), and
 * failed_at and pushed_at are residual predicates of every read that selects
 * rows to trim (the batch select, the read that finishes a range, the
 * lookahead) and of the delete (belowFloor()), so held rows are never
 * candidates and never count as behind. pushed_at needs its own predicate: a
 * producer's clock and a worker's clock can disagree, so pushed_at <=
 * finished_at does not hold across hosts. Past the bound
 * there is no floor, so every partition can finish: each delete batch
 * locks the invalid sample minutes first and records the minutes of its
 * deleted rows (by finished_at, failed_at and pushed_at) in the same
 * transaction, so those minutes stay unknown, never zero.
 *
 * The state. When the run stops with partitions left,
 * it checks the unfinished partitions that it visited, at most
 * LOOKAHEAD_PARTITIONS (20), in visiting order: one indexed read per
 * partition finds the oldest row that is still due after its cursor
 * (LIMIT 1). At the end of the run, the settings row STATE_KEY holds the
 * JSON object {at, deleted, behind, lag_seconds, partitions_unchecked}:
 * - `behind`: true when a lookahead found a due row; false when every
 *   partition finished, or every unfinished partition was checked and has
 *   no due row; else null (unknown).
 * - `lag_seconds`: the largest cutoff − finished_at of the found rows, else
 *   null. Those rows are still due, so it is a lower bound of the worst
 *   remaining lag.
 * - `partitions_unchecked`: the unfinished partitions that were not checked.
 * A run that throws, or that stops on an invalid policy, writes no state, so
 * the stored `at` gets old.
 *
 * One batch:
 * 1. Up to 500 candidates are read in autocommit, from the cursor, with the
 *    partition predicate and finished_at < cutoff. The hook point
 *    `retention.after_select` follows.
 * 2. One package transaction: V with a shared lock; the candidates again
 *    with the partition predicate and FOR UPDATE on MySQL and Postgres
 *    (SQLite runs under BEGIN IMMEDIATE); the inline verification of each
 *    candidate whose monitor_version is older than V
 *    (MonitoringRepair::recompute()); the delete with the full predicate
 *    re-applied (status, silenced, monitored, operation_id null,
 *    finished_at < cutoff, and monitor_version null or V); the tags, failed tags, history, cycle
 *    summaries and stuck incidents of the uuids whose rows were deleted;
 *    the cursor. The operation ledger and the fence rows are never trimmed
 *    with the job.
 * So a retention decision never uses an unverified monitored flag, whatever
 * the order in which mirror transactions committed.
 */
final class Retention
{
    public const AFTER_SELECT = 'retention.after_select';

    public const BATCH = 500;

    public const MAX_SECONDS = 10.0;

    /** The hook point inside a deletion transaction, after its delete (tests only). */
    public const IN_TRANSACTION = 'retention.in_transaction';

    /** The pause before a batch that follows a write transaction, in the `file` lock mode. */
    public const PAUSE_MICROSECONDS = 150_000;

    /** The settings row of the partition tuple where the next run starts after. */
    public const START_KEY = 'retention_start';

    /** The most unfinished partitions that the lookahead of one run checks. */
    public const LOOKAHEAD_PARTITIONS = 20;

    /** The settings row of the state of the last completed run. */
    public const STATE_KEY = 'retention_state';

    public const CURSOR_PREFIX = 'retention_cursor:';

    /** The health-event operation of a run that an invalid policy stops. */
    public const CONFIG_INVALID = 'retention:config_invalid';

    /** The statuses that retention trims. */
    public const TRIMMED = ['completed', 'failed', 'unknown'];

    private const TABLE = 'orrery_jobs';

    /** The keys of the two ranges in a cursor value: silenced = false and silenced = true. */
    private const PLAIN = 'plain';

    private const SILENCED = 'silenced';

    /** The package tables whose rows belong to a trimmed job (job_uuid). */
    private const DEPENDENT_TABLES = ['orrery_job_tags', FailedTags::TABLE, 'orrery_job_history', CycleSummaries::TABLE];

    private ?int $batchForTesting = null;

    private ?int $rowsForTesting = null;

    private ?float $secondsForTesting = null;

    private ?string $lockModeForTesting = null;

    /** @var (Closure(int): void)|null */
    private ?Closure $sleepForTesting = null;

    public function __construct(
        private readonly RetentionPolicy $policy,
        private readonly PackageTransaction $transactions,
        private readonly MonitoringVersion $versions,
        private readonly MonitoringRepair $repair,
        private readonly SampleCounts $counts,
    ) {}

    /**
     * One retention run. Returns the number of deleted job rows. An invalid
     * policy (`orrery.trim` or `orrery.trim_overrides`)
     * deletes nothing, records the error `retention:config_invalid`, and
     * writes no state.
     */
    public function run(?TickBudget $budget = null): int
    {
        $errors = $this->policy->validate();

        if ($errors !== []) {
            Degradation::error(self::CONFIG_INVALID, 'The retention policy is invalid: '.implode(' ', $errors));

            return 0;
        }

        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $mode = $this->lockModeForTesting ?? $this->lockMode($connection);
        $batch = $this->batchForTesting ?? self::BATCH;
        // No row cap; only a test sets one (useLimitsForTesting()).
        $limit = $this->rowsForTesting;
        $deadline = Clock::monotonic() + ($this->secondsForTesting ?? self::MAX_SECONDS);
        $now = Clock::now();
        // The row of V exists before the first transaction that locks it.
        $this->versions->ensureRow();
        // So does the row of the invalid sample minutes.
        SampleCounts::ensureInvalidRow($connection);
        $active = $this->rotated($connection, $this->partitions($connection, $now));
        // The position of each visited, unfinished partition after its last batch.
        $visited = [];
        $deleted = 0;
        $batches = 0;
        $locked = null;
        $sincePause = Clock::monotonic();
        $last = null;

        while ($active !== []) {
            foreach ($active as $key => $partition) {
                // The first batch always runs (see the class comment).
                if ($batches > 0 && self::stops($deleted, $limit, $deadline, $budget)) {
                    break 2;
                }

                if ($locked !== null && $this->pause($mode, $locked, $sincePause)) {
                    $sincePause = Clock::monotonic();

                    if (self::stops($deleted, $limit, $deadline, $budget)) {
                        break 2;
                    }
                }

                [$count, $done, $next, $locked] = $this->batch($partition, $limit === null ? $batch : min($batch, $limit - $deleted));
                $deleted += $count;
                $batches++;
                $last = $partition;

                if ($done) {
                    unset($active[$key], $visited[$key]);
                } else {
                    $visited[$key] = $next;
                }
            }
        }

        if ($last !== null) {
            $connection->table('orrery_settings')->upsert(
                // A name that is not valid UTF-8 is stored substituted: it matches no partition, so the next run starts at the first.
                [['key' => self::START_KEY, 'value' => json_encode(self::tuple($last), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)]],
                ['key'],
                ['value'],
            );
        }

        $this->storeState($deleted, $active, $visited);

        return $deleted;
    }

    /**
     * @phpstan-impure
     */
    private static function stops(int $deleted, ?int $limit, float $deadline, ?TickBudget $budget): bool
    {
        return ($limit !== null && $deleted >= $limit) || Clock::monotonic() >= $deadline || ($budget?->exhausted() ?? false);
    }

    /**
     * The pause before the next batch, after a write transaction that held
     * the lock for $locked seconds (see the class comment). Returns whether
     * it paused.
     */
    private function pause(string $mode, float $locked, float $sincePause): bool
    {
        $microseconds = match ($mode) {
            'file' => Clock::monotonic() - $sincePause >= self::PAUSE_MICROSECONDS / 1e6 ? self::PAUSE_MICROSECONDS : 0,
            default => 0,
        };

        if ($microseconds <= 0) {
            return false;
        }

        if ($this->sleepForTesting !== null) {
            ($this->sleepForTesting)($microseconds);
        } else {
            usleep($microseconds);
        }

        return true;
    }

    /**
     * The lock mode of the mirror (see the class comment): `file` for a
     * SQLite file (always the package's own), else `rows`.
     */
    public function lockMode(Connection $mirror): string
    {
        return self::lockModeOf($mirror);
    }

    /**
     * lockMode() for the classes without an instance (SampleCounts).
     */
    public static function lockModeOf(Connection $mirror): string
    {
        if ($mirror->getDriverName() !== 'sqlite') {
            return 'rows';
        }

        $database = (string) $mirror->getDatabaseName();

        return $database === '' || PackageSession::isInMemory(['driver' => 'sqlite', 'database' => $database]) ? 'rows' : 'file';
    }

    /**
     * The tuple of a partition range, in index order.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @return array{0: string, 1: string, 2: string, 3: bool, 4: bool}
     */
    private static function tuple(array $partition): array
    {
        return [$partition['connection'], $partition['queue'], $partition['status'], $partition['silenced'], $partition['monitored']];
    }

    /**
     * The partitions, starting at the first one after the stored start
     * tuple (START_KEY), wrapping. Discovery returns them in index order.
     *
     * @param  list<array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}>  $partitions
     * @return list<array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}>
     */
    private function rotated(Connection $connection, array $partitions): array
    {
        $value = $connection->table('orrery_settings')->where('key', self::START_KEY)->value('value');
        $after = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($after) || count($after) !== 5) {
            return $partitions;
        }

        $after = array_values($after);

        foreach ($partitions as $index => $partition) {
            if (self::tuple($partition) === $after) {
                return array_merge(array_slice($partitions, $index + 1), array_slice($partitions, 0, $index + 1));
            }
        }

        return $partitions;
    }

    /**
     * The lookahead and the settings row STATE_KEY (see the class comment).
     *
     * @param  array<int, array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}>  $unfinished
     * @param  array<int, array{0: string, 1: int}|null>  $visited
     */
    private function storeState(int $deleted, array $unfinished, array $visited): void
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $checked = 0;
        $lag = null;

        foreach (array_slice($visited, 0, self::LOOKAHEAD_PARTITIONS, true) as $key => $position) {
            $partition = $unfinished[$key];
            $finishedAt = $this->candidates($connection, $partition, $position)->limit(1)->value('finished_at');
            $checked++;

            if ($finishedAt !== null) {
                $lag = max($lag ?? 0.0, (float) $partition['cutoff'] - (float) $finishedAt);
            }
        }

        $behind = match (true) {
            $lag !== null => true,
            $checked === count($unfinished) => false,
            default => null,
        };

        $state = [
            'at' => round(Clock::now(), 6),
            'deleted' => $deleted,
            'behind' => $behind,
            'lag_seconds' => $lag === null ? null : round($lag, 6),
            'partitions_unchecked' => count($unfinished) - $checked,
        ];

        $connection->table('orrery_settings')->upsert(
            [['key' => self::STATE_KEY, 'value' => json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)]],
            ['key'],
            ['value'],
        );
    }

    public static function useLimitsForTesting(?int $batch = null, ?int $rows = null, ?float $seconds = null): void
    {
        $state = self::bound();

        $state->batchForTesting = $batch;
        $state->rowsForTesting = $rows;
        $state->secondsForTesting = $seconds;
    }

    /**
     * @param  'file'|'rows'|null  $mode
     * @param  (Closure(int): void)|null  $sleep  called with the microseconds of each pause instead of usleep()
     */
    public static function useLockModeForTesting(?string $mode, ?Closure $sleep = null): void
    {
        $state = self::bound();

        $state->lockModeForTesting = $mode;
        $state->sleepForTesting = $sleep;
    }

    /**
     * The partitions of the mirror, each with its cutoff, in index order. A
     * `failed` or `unknown` partition holds one range per silenced value.
     * One index seek per distinct tuple (see the class comment).
     *
     * @return list<array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}>
     */
    private function partitions(Connection $connection, float $now): array
    {
        $partitions = [];
        $after = null;
        $through = SampleCounts::countedThrough($connection);
        $countedAt = SampleCounts::countedAt($connection);
        // Once per run: overrides match by the primary alias of a shared jobs table.
        $aliases = $this->policy->aliases();

        $seen = [];

        while (($tuple = $this->nextTuple($connection, $after)) !== null) {
            // A seek that returns a tuple again did not move forward: its
            // predicate is wrong for this driver, and the loop would never end.
            // serialize(): byte-exact, also for a name that is not valid UTF-8 (SQLite stores one).
            $key = serialize($tuple);

            if (isset($seen[$key])) {
                throw new UnexpectedValueException('The retention discovery did not move forward: the seek returned a partition again.');
            }

            $seen[$key] = true;
            $after = $tuple;

            if (! in_array($after[2], self::TRIMMED, true)) {
                continue;
            }

            [$name, $queue, $status, $silenced, $monitored] = $after;
            $state = (string) RetentionPolicy::effectiveState($status, $silenced);
            $minutes = $this->policy->minutes($name, $queue, $state, $monitored, $aliases);
            [$floor, $records] = $this->countsFloor($through, $countedAt, $minutes, $now);
            $cutoff = $now - $minutes * 60;

            $partitions[] = [
                'connection' => $name,
                'queue' => $queue,
                'status' => $status,
                'silenced' => $silenced,
                'monitored' => $monitored,
                'state' => $state,
                'cutoff' => Clock::toDecimal($floor === null ? $cutoff : min($cutoff, (float) $floor)),
                'cursor' => self::cursorKey($name, $queue, $state, $monitored),
                'floor' => $floor === null ? null : Clock::toDecimal((float) $floor),
                'records' => $records,
            ];
        }

        return $partitions;
    }

    /**
     * The first (connection, queue, status, silenced, monitored) tuple of
     * orrery_jobs after $after, in index order, or null.
     *
     * @param  array{0: string, 1: string, 2: string, 3: bool, 4: bool}|null  $after
     * @return array{0: string, 1: string, 2: string, 3: bool, 4: bool}|null
     */
    private function nextTuple(Connection $connection, ?array $after): ?array
    {
        $columns = ['connection', 'queue', 'status', 'silenced', 'monitored'];
        $query = self::discovery($connection, $after);

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        $row = $query->limit(1)->first($columns);

        if (! is_object($row)) {
            return null;
        }

        $row = (array) $row;

        return [(string) $row['connection'], (string) $row['queue'], (string) $row['status'], (bool) $row['silenced'], (bool) $row['monitored']];
    }

    /**
     * The discovery seek after $after: a row-value comparison, or on MySQL
     * and MariaDB the same comparison as nested OR terms (public for the
     * plan test). MariaDB without ANSI_QUOTES reads a double-quoted name as
     * a string, so it must not get the row-value text.
     *
     * @param  array{0: string, 1: string, 2: string, 3: bool, 4: bool}|null  $after
     */
    public static function discovery(Connection $connection, ?array $after): Builder
    {
        $query = $connection->table(self::TABLE);

        if ($after === null) {
            return $query;
        }

        $columns = ['connection', 'queue', 'status', 'silenced', 'monitored'];

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // SQLite and Postgres quote identifiers with double quotes; columns carry no prefix.
            return $query->whereRaw('("connection", "queue", "status", "silenced", "monitored") > (?, ?, ?, ?, ?)', $after);
        }

        // (c1, …, c5) > (v1, …, v5) as c1 > v1 OR (c1 = v1 AND (c2 > v2 OR (…))).
        $nest = static function (Builder $query, int $i) use (&$nest, $columns, $after): void {
            $query->where($columns[$i], '>', $after[$i]);

            if ($i < 4) {
                $query->orWhere(static function (Builder $query) use (&$nest, $columns, $after, $i): void {
                    $query->where($columns[$i], '=', $after[$i])->where(static function (Builder $query) use (&$nest, $i): void {
                        $nest($query, $i + 1);
                    });
                });
            }
        };

        return $query->where(static function (Builder $query) use ($nest): void {
            $nest($query, 0);
        });
    }

    /**
     * One batch of a partition range: the deleted rows, whether the range
     * has no more candidates, the position after the batch, and the seconds
     * that its write transaction held the lock (null without one).
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @return array{0: int, 1: bool, 2: array{0: string, 1: int}|null, 3: float|null}
     */
    private function batch(array $partition, int $limit): array
    {
        $connection = Container::getInstance()->make(PackageSession::class)->mirror();
        $position = $this->position($connection, $partition);

        $candidates = $this->candidates($connection, $partition, $position)->limit($limit)->get(['id', 'finished_at']);

        if ($candidates->isEmpty()) {
            if ($position === null) {
                return [0, true, null, null];
            }

            // The end of the range from a cursor: finished only when no due
            // row is left before the cursor either (one LIMIT 1 read).
            $restart = $this->candidates($connection, $partition, null)->limit(1)->value('id') !== null;
            $locked = 0.0;
            $this->transactions->run($connection, function (Connection $tx) use ($partition, &$locked): void {
                $locked = Clock::monotonic();
                $this->storePosition($tx, $partition, null);
            });

            return [0, ! $restart, null, Clock::monotonic() - $locked];
        }

        Hooks::reach(self::AFTER_SELECT);

        $done = $candidates->count() < $limit;
        $last = $candidates->last();
        $next = $done ? null : [Clock::toDecimal((float) $last->finished_at), (int) $last->id];
        /** @var list<int> $ids */
        $ids = $candidates->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        // The lock is held from the start of the transaction's work (after
        // BEGIN IMMEDIATE got it, so a wait for the lock does not count) to its commit.
        $locked = 0.0;
        $deleted = $this->transactions->run($connection, function (Connection $tx) use ($partition, $ids, $next, &$locked): int {
            $locked = Clock::monotonic();

            return $this->delete($tx, $partition, $ids, $next);
        });

        return [$deleted, $done, $next, Clock::monotonic() - $locked];
    }

    /**
     * The due rows of a partition range after $position, oldest first: the
     * partition predicate, finished_at < cutoff, and the keyset position.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @param  array{0: string, 1: int}|null  $position
     */
    private function candidates(Connection $connection, array $partition, ?array $position): Builder
    {
        // A held row is never trimmed (delete()), so it is never a candidate either: else a range of
        // held rows alone never finishes.
        $query = self::belowFloor($this->partition($connection, $partition)->where('finished_at', '<', $partition['cutoff'])->whereNull('operation_id'), $partition);

        if ($position !== null) {
            [$finishedAt, $id] = $position;
            $query->where(static function (Builder $query) use ($finishedAt, $id): void {
                $query->where('finished_at', '>', $finishedAt)
                    ->orWhere(static function (Builder $query) use ($finishedAt, $id): void {
                        $query->where('finished_at', '=', $finishedAt)->where('id', '>', $id);
                    });
            });
        }

        return $query->orderBy('finished_at')->orderBy('id');
    }

    /**
     * The deletion transaction of one batch (steps 1 to 4).
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @param  list<int>  $ids
     * @param  array{0: string, 1: int}|null  $next
     */
    private function delete(Connection $tx, array $partition, array $ids, ?array $next): int
    {
        // Past the counts bound, the invalid sample minutes are locked first, so
        // a minute publication and this delete never interleave.
        $invalid = $partition['records'] ? SampleCounts::lockInvalid($tx, SampleCounts::minuteOf(Clock::now())) : null;
        $version = $this->versions->locked($tx);

        // A row that an operation holds (a forget of a failed row) is never trimmed.
        $locked = self::belowFloor($this->partition($tx, $partition), $partition)
            ->whereIn('id', $ids)
            ->whereNull('operation_id')
            ->where('finished_at', '<', $partition['cutoff'])
            ->lockForUpdate()
            ->get(['id', 'uuid', 'monitor_version', 'finished_at', 'failed_at', 'pushed_at']);

        $stale = [];

        foreach ($locked as $row) {
            if ($row->monitor_version !== null && (int) $row->monitor_version < $version) {
                $stale[] = (int) $row->id;
            }
        }

        if ($stale !== []) {
            // A candidate that is now monitored leaves this partition's deletion.
            $this->repair->recompute($tx, $stale, $version);
        }

        $gone = [];

        if ($locked->isNotEmpty()) {
            $lockedIds = $locked->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            self::belowFloor($this->partition($tx, $partition), $partition)
                ->whereIn('id', $lockedIds)
                ->whereNull('operation_id')
                ->where('finished_at', '<', $partition['cutoff'])
                ->where(static function (Builder $query) use ($version): void {
                    $query->whereNull('monitor_version')->orWhere('monitor_version', '=', $version);
                })
                ->delete();

            Hooks::reach(self::IN_TRANSACTION);

            $kept = array_flip($tx->table(self::TABLE)->whereIn('id', $lockedIds)->pluck('uuid')->map(static fn (mixed $uuid): string => (string) $uuid)->all());

            foreach ($locked as $row) {
                if (! isset($kept[(string) $row->uuid])) {
                    $gone[] = (string) $row->uuid;
                }
            }
        }

        if ($gone !== []) {
            foreach (array_chunk($gone, 500) as $chunk) {
                foreach (self::DEPENDENT_TABLES as $table) {
                    $tx->table($table)->whereIn('job_uuid', $chunk)->delete();
                }
            }

            Container::getInstance()->make(IncidentCleanup::class)->deleteForJobs($tx, $gone);

            if ($invalid !== null) {
                $deleted = array_flip($gone);
                $this->recordInvalid($tx, $invalid, array_values(array_filter($locked->all(), static fn (object $row): bool => isset($deleted[(string) $row->uuid]))));
            }
        }

        $this->storePosition($tx, $partition, $next);

        return count($gone);
    }

    /**
     * The counts floor on failed_at and pushed_at:
     * a row that failed or was pushed at or after the floor is kept,
     * like a row that finished there (the cutoff covers finished_at). Only
     * while the floor holds: past the counts bound there is no floor.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     */
    private static function belowFloor(Builder $query, array $partition): Builder
    {
        $floor = $partition['floor'];

        if ($floor === null) {
            return $query;
        }

        return $query->where(static function (Builder $query) use ($floor): void {
            $query->whereNull('failed_at')->orWhere('failed_at', '<', $floor);
        })->where('pushed_at', '<', $floor);
    }

    /**
     * The rows of a partition range: its connection, queue, status,
     * silenced value and monitored flag.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     */
    private function partition(Connection $connection, array $partition): Builder
    {
        $query = $connection->table(self::TABLE)
            ->where('connection', $partition['connection'])
            ->where('queue', $partition['queue'])
            ->where('status', $partition['status'])
            ->where('silenced', $partition['silenced'])
            ->where('monitored', $partition['monitored']);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // Defence in depth: the columns are byte-exact (PackageSchema::byteExactCollation()),
            // but a schema created before that change keeps a collation that folds case and
            // trailing spaces; the index serves the collated range, and these keep it exact.
            $query->whereRaw('CAST(`connection` AS BINARY) = CAST(? AS BINARY)', [$partition['connection']])
                ->whereRaw('CAST(`queue` AS BINARY) = CAST(? AS BINARY)', [$partition['queue']]);
        }

        return $query;
    }

    /**
     * The counts floor of a partition: [the floor, whether
     * its deletes record invalid sample minutes].
     *
     * - With M (SampleCounts::COUNTED_THROUGH): while M moved at most
     *   max(the partition's retention, `samples.count_wait_minutes`) ago
     *   (SampleCounts::COUNTED_AT), the floor is M - 300: a row finished or
     *   failed at or after it is kept, because the next aggregation can
     *   still recount its minute. Past that bound there is no floor, and each
     *   delete batch records the minutes of its rows in the invalid intervals
     *   (recordInvalid()), so they stay unknown, never zero.
     * - Without M (a first install, or a cleared M), the floor is the first
     *   minute that the next aggregation counts: the current minute - 300.
     *   Its delete batches also take the INVALID lock, so a minute
     *   publication never interleaves with them; the aggregation skips the
     *   minutes below the current minute - 300 under that lock.
     *
     * @return array{0: int|null, 1: bool}
     */
    private function countsFloor(?int $through, ?float $countedAt, int $minutes, float $now): array
    {
        if ($through === null) {
            return [SampleCounts::minuteOf($now) - SampleCounts::RECOUNT_SECONDS, true];
        }

        $bound = max($minutes, $this->counts->countWaitMinutes()) * 60;

        if ($countedAt !== null && $now - $countedAt <= $bound) {
            return [$through - SampleCounts::RECOUNT_SECONDS, false];
        }

        return [null, true];
    }

    /**
     * Adds the minutes of the deleted rows (by finished_at, failed_at and
     * pushed_at)
     * that the aggregation could still count to the invalid intervals: the
     * minutes at or after the live M - 300 (without M, the current minute -
     * 300). Inside the delete batch's transaction, which locked the row
     * first ($invalid).
     *
     * @param  array{version: int, intervals: list<array{0: int, 1: int}>, corrupt: bool}  $invalid
     * @param  list<object>  $rows  the deleted rows with finished_at, failed_at and pushed_at
     */
    private function recordInvalid(Connection $tx, array $invalid, array $rows): void
    {
        $now = Clock::now();
        $live = SampleCounts::countedThrough($tx);
        $from = ($live ?? SampleCounts::minuteOf($now)) - SampleCounts::RECOUNT_SECONDS;
        $minutes = [];

        foreach ($rows as $row) {
            foreach (['finished_at', 'failed_at', 'pushed_at'] as $column) {
                $time = $row->{$column} ?? null;

                if (is_numeric($time) && ($minute = SampleCounts::minuteOf((float) $time)) >= $from) {
                    $minutes[] = $minute;
                }
            }
        }

        SampleCounts::addInvalid($tx, $invalid, $minutes, $now, $this->counts->retentionHours());
    }

    /**
     * The settings key of a partition's cursor: the prefix and the SHA-256
     * of its serialized parts, so names that contain `:` never share a key
     * ((a, b:c) and (a:b, c)) and every key fits the column.
     */
    public static function cursorKey(string $connection, string $queue, string $state, bool $monitored): string
    {
        return self::CURSOR_PREFIX.hash('sha256', serialize([$connection, $queue, $state, $monitored]));
    }

    /**
     * The stored (finished_at, id) position of a partition range, or null.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @return array{0: string, 1: int}|null
     */
    private function position(Connection $connection, array $partition): ?array
    {
        $positions = $this->positions($connection->table('orrery_settings')->where('key', $partition['cursor'])->value('value'));

        return $positions[self::range($partition)] ?? null;
    }

    /**
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     * @param  array{0: string, 1: int}|null  $position
     */
    private function storePosition(Connection $tx, array $partition, ?array $position): void
    {
        $positions = $this->positions($tx->table('orrery_settings')->where('key', $partition['cursor'])->value('value'));
        $range = self::range($partition);

        if ($position === null) {
            unset($positions[$range]);
        } else {
            $positions[$range] = $position;
        }

        if ($positions === []) {
            $tx->table('orrery_settings')->where('key', $partition['cursor'])->delete();

            return;
        }

        ksort($positions);
        $tx->table('orrery_settings')->upsert(
            [['key' => $partition['cursor'], 'value' => json_encode($positions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]],
            ['key'],
            ['value'],
        );
    }

    /**
     * The key of a partition range in the cursor value.
     *
     * @param  array{connection: string, queue: string, status: string, silenced: bool, monitored: bool, state: string, cutoff: string, cursor: string, floor: string|null, records: bool}  $partition
     */
    private static function range(array $partition): string
    {
        return $partition['silenced'] ? self::SILENCED : self::PLAIN;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    private function positions(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        $positions = [];

        if (! is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $range => $position) {
            if (in_array($range, [self::PLAIN, self::SILENCED], true) && is_array($position) && is_string($position[0] ?? null) && is_numeric($position[0]) && is_int($position[1] ?? null)) {
                $positions[$range] = [$position[0], $position[1]];
            }
        }

        return $positions;
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
