<?php

namespace Justrau\Orrery\Maintenance;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Monitoring\BlindGaps;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Retry\ForgetService;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\ErrorKinds;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Throwable;

/**
 * Reconciliation of the mirror with the queue and the failed-job table.
 *
 * Active rows (`pending`, `reserved`) with a resolved job id, per queue
 * connection with the `database` driver:
 * - A persistent cursor (orrery_settings `reconcile_cursor:<connection>`)
 *   takes 1,000 rows per tick by mirror id, and one read on the observer
 *   session checks their ids against the queue table.
 * - The first absence sets absent_since, only while the row still has the
 *   inspected status set, job id and cycle. The rule markUnknown
 *   (JobStore::markUnknown(), reason `absent`) applies only when the row is still absent at a later
 *   check at least max(retry_after, 600) seconds after absent_since, with the
 *   job id and cycle unchanged. A present row loses its absent_since.
 * - Rows with a null job_id or with job_id_unresolved are never checked.
 * - A loss of a blind gap (Monitoring\BlindGaps): the first absence also
 *   writes `unknown_reason` = BLIND_GAP_REASON (else null), and markUnknown
 *   later takes that reason instead of `absent`, which raises no stuck
 *   incident (StuckIncidents::SILENT_REASONS). The first absence is a loss
 *   of a recorded gap [from, to) when all three hold:
 *   (a) the row's last observation (`activity_at`) is before `to`, so
 *       nothing observed the job after observation resumed;
 *   (b) no sweep that started at or after `to` has completed
 *       (lastVerified() < `to`), so this is the row's first check after
 *       the gap: a sweep whose cursor started before `to` starts again
 *       from the first row, so the first sweep after the gap checks each
 *       row once;
 *   (c) the last sweep before it started at most BlindGaps::GAP_SECONDS
 *       before `from` (lastVerified() >= from − GAP_SECONDS), so the row was
 *       found present at most that long before observation stopped (a row
 *       that the sweep found absent already has its first absence, and
 *       keeps `absent`).
 *   So the job left the queue between a check at most GAP_SECONDS before
 *   the gap and the first check after it. A job that is removed after the
 *   gap's end but before the first sweep after it reaches its row counts
 *   too: that sweep is the first look after the gap. A present row loses
 *   the reason with its absent_since.
 * - When a batch reaches the end, the sweep is complete: the start time of
 *   that sweep becomes `reconcile_swept_at:<connection>` (lastVerified()).
 * - The active rows of a connection that is no longer a `database` queue
 *   connection (removed, renamed, or given another driver) are checked
 *   against every configured queue table (reconcileRemoved()): a row is
 *   present when a table's job with its job id has its uuid as payload
 *   identity, and absent, with the reason `connection_removed`, only when
 *   every table was read.
 *
 * Failed rows (`reconcile_failed`; SqlFailedRecords only, the
 * package's provider on a table whose ids are never reused): a keyset
 * traversal by mirror id (orrery_settings `reconcile_failed_cursor`) takes
 * the `failed` rows with `operation_id IS NULL` and a bound record
 * (`provider_id` not null), at most FAILED_ROWS_PER_TICK (1,000) rows per
 * tick, in batches of FAILED_BATCH (500); the cursor keeps the position
 * between ticks.
 * - A row without a bound record is not checked: it ages out by
 *   `trim.failed`.
 * - A row whose `provider_target` is not the current target of the
 *   failed-record table is not checked (target drift): it is counted as
 *   `reconcile:not_checked` with a health event, and never deleted.
 * - Each batch checks presence with one read on the record session, by
 *   (`id`, `uuid`): a record is present only when both match.
 * - The first absence only sets absent_since on the failed row, and a later
 *   read that finds the record clears it.
 * - A record that is still absent at a check at least max(retry_after, 600)
 *   seconds after absent_since gives ForgetService::removeAbsent(): the
 *   forget's mirror delete, guarded by the state that the check read: the
 *   occurrence, still `failed` with `operation_id IS NULL` and the same
 *   provider_id, the same retry_requested_at and absent_since. The grace is
 *   not a bound on transaction lifetimes. A
 * failure whose native retry was requested (retry_requested_at not earlier
 * than failed_at) is kept while the queue table still holds a job whose
 * payload identity (PayloadIdentity::uuidOf()) is its uuid, so Rules::nativeRetry() can start its
 * cycle at the reservation. A native retry that lands during the check
 * changes retry_requested_at, so the guarded delete changes nothing, and the
 * next check sees the request.
 *
 * "Not checked": a read that fails (a lost target session, which
 * never reconnects transparently, a timeout, an unknown namespace) proves
 * nothing. The rows keep their state, the cursor stays, and the next tick
 * tries the same position again. A failed scan of a queue table for a
 * native retry is the exception: its row keeps its state, and the cursor
 * moves past it, so one failing scan never holds the rows after it. A pass
 * runs at most NATIVE_RETRY_SCANS such scans (each reads the jobs of the
 * row's queue; the payload has no index), none after its time share
 * NATIVE_RETRY_SECONDS or the tick's deadline, and the next pass resumes at
 * the first row that it did not scan. The failure is recorded as
 * `reconcile:not_checked` in the health events, and the session is reopened,
 * so the next read measures on a new session.
 *
 * Hook points: `reconcile.before_absence_check` (before each read of the
 * queue or provider table) and the hold point `reconcile.after_absence_check`
 * (after it, before any write).
 */
final class Reconciliation
{
    public const BEFORE_ABSENCE_CHECK = 'reconcile.before_absence_check';

    public const AFTER_ABSENCE_CHECK = 'reconcile.after_absence_check';

    /** The most ids that one statement binds: below SQLite's old limit of 999 variables. */
    public const BINDINGS = 900;

    /** The active rows per tick and connection. */
    public const ACTIVE_BATCH = 1000;

    /** The most queue payloads that the native retry check decodes for one row. */
    public const RETRY_CANDIDATES = 100;

    /**
     * The most scans of a queue table for a native retry in one pass of the
     * failed rows. Each scan reads every job of the row's queue (the payload
     * has no index), so a pass stops before the next scan at this count, at
     * NATIVE_RETRY_SECONDS or at its deadline, and the next pass resumes at
     * that row.
     */
    public const NATIVE_RETRY_SCANS = 20;

    /**
     * The time share of the native retry scans in one pass, in seconds: no
     * scan starts after it, so slow scans never use the tick's deadline and
     * skip the phases after this one.
     */
    public const NATIVE_RETRY_SECONDS = 5.0;

    /** The failed rows per batch. */
    public const FAILED_BATCH = 500;

    /**
     * The most failed rows that one tick checks. The keyset
     * cursor keeps the position, so the next tick resumes after it, and one
     * tick costs the same however many failed rows the mirror holds.
     */
    public const FAILED_ROWS_PER_TICK = 1000;

    /** The least grace between the first absence and markUnknown, in seconds. */
    public const MIN_GRACE_SECONDS = 600;

    /** Laravel's default retry_after of a database queue connection. */
    public const DEFAULT_RETRY_AFTER = 60;

    public const UNKNOWN_REASON = 'absent';

    /** The unknown reason of a job whose jobs row left during a recorded blind gap (Monitoring\BlindGaps). */
    public const BLIND_GAP_REASON = 'blind_gap';

    /** The settings key of the rotating cursor of reconcileRemoved(). */
    public const REMOVED_CURSOR = 'reconcile_removed_cursor';

    /** The rows of reconcileRemoved() per read of a queue table. */
    public const REMOVED_CHUNK = 100;

    /** The most distinct connection names that reconcileRemoved() finds by seeks before it filters the rows instead. */
    public const MAX_CONNECTION_SEEKS = 100;

    /** The unknown reason of a row whose connection is no longer configured and whose job no configured queue table holds. */
    public const REMOVED_REASON = 'connection_removed';

    public const HEALTH_NOT_CHECKED = 'reconcile:not_checked';

    public const CURSOR_PREFIX = 'reconcile_cursor:';

    public const SWEPT_PREFIX = 'reconcile_swept_at:';

    public const FAILED_CURSOR = 'reconcile_failed_cursor';

    private const TABLE = JobStore::TABLE;

    private const SETTINGS = 'orrery_settings';

    private ?int $activeBatchForTesting = null;

    private ?int $failedBatchForTesting = null;

    private ?int $failedRowsPerTickForTesting = null;

    private ?int $nativeRetryScansForTesting = null;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly HostFiles $hostFiles,
        private readonly JobStore $store,
        private readonly ForgetService $forget,
        private readonly Container $app,
    ) {}

    /**
     * The start time of the last completed sweep of the active rows of a
     * queue connection, or null before the first one. It is the time from
     * which every active row of the connection was checked, not a per-row
     * freshness.
     */
    public function lastVerified(string $connection): ?float
    {
        $value = $this->app->make(PackageSession::class)->mirror()->table(self::SETTINGS)->where('key', self::SWEPT_PREFIX.$connection)->value('value');

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * The queue connections with the `database` driver.
     *
     * @return list<string>
     */
    public function connections(): array
    {
        $connections = [];

        foreach ((array) $this->config()->get('queue.connections', []) as $name => $config) {
            // A numeric name is a PHP integer key; its rows carry the string.
            if (is_array($config) && ($config['driver'] ?? null) === 'database') {
                $connections[] = (string) $name;
            }
        }

        return $connections;
    }

    /**
     * One batch of the active rows of a queue connection. False when the
     * batch was not checked (a failed read, or D18 refuses the read): the
     * rows and the cursor stay.
     */
    public function reconcileActive(string $connection): bool
    {
        if (! $this->readable($this->sessions->databaseConnectionFor($connection))) {
            // D18: no automatic read of a host SQLite file that does not allow
            // it. The rows stay as they are (unknown); orrery:reconcile runs
            // the check as an operator action.
            return false;
        }

        $mirror = $this->app->make(PackageSession::class)->mirror();
        $cursor = $this->cursor($mirror, $connection);
        $batch = $this->activeBatchForTesting ?? self::ACTIVE_BATCH;
        $gaps = BlindGaps::read($mirror);
        $latest = $gaps === [] ? null : $gaps[count($gaps) - 1];

        if ($latest !== null && $cursor['after'] > 0 && Clock::stored($cursor['started_at']) < $latest[1]) {
            // A sweep that started before the gap's end starts again: the first sweep after the gap checks each row once.
            $cursor = ['after' => 0, 'started_at' => Clock::toDecimal(Clock::now())];
        }

        $swept = $gaps === [] ? null : $this->lastVerified($connection);

        $rows = $mirror->table(self::TABLE)
            ->where('connection', $connection)
            ->whereIn('status', ['pending', 'reserved'])
            ->whereNotNull('job_id')
            ->where('job_id_unresolved', false)
            ->where('id', '>', $cursor['after'])
            ->orderBy('id')
            ->limit($batch)
            ->get(['id', 'uuid', 'status', 'job_id', 'cycle', 'absent_since', 'activity_at', 'unknown_reason']);

        if ($rows->isNotEmpty()) {
            $present = $this->presentJobs($connection, $rows->pluck('job_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all());

            if ($present === null) {
                return false;
            }

            $this->applyActive(
                $mirror,
                $connection,
                $rows,
                static fn (object $row): bool => isset($present[(int) $row->job_id]),
                self::UNKNOWN_REASON,
                static fn (object $row): bool => self::blindGapLoss($gaps, $swept, $row->activity_at ?? null),
            );
        }

        if ($rows->count() < $batch) {
            // The end of the connection's rows: the sweep is complete.
            $this->storeSetting($mirror, self::SWEPT_PREFIX.$connection, $cursor['started_at']);
            $mirror->table(self::SETTINGS)->where('key', self::CURSOR_PREFIX.$connection)->delete();

            return true;
        }

        $last = $rows->last();

        // The batch is full here, so it has a last row.
        assert($last !== null);

        $this->storeSetting($mirror, self::CURSOR_PREFIX.$connection, json_encode([
            'after' => (int) $last->id,
            'started_at' => $cursor['started_at'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return true;
    }

    /**
     * One batch of the active rows whose connection is no longer a `database`
     * queue connection (removed, renamed, or given another driver), after the
     * rotating cursor `reconcile_removed_cursor`. A rename keeps the jobs in a
     * table that a configured connection reads, so each configured queue
     * table is read for the rows' job ids, REMOVED_CHUNK rows at a time: a row
     * is present when the table's job with its job id has the row's uuid as
     * payload identity (an id alone can name another job of another
     * database). A present row loses its absent_since. A row that no table
     * holds is absent, and unknown (reason `connection_removed`) after the
     * grace, as in reconcileActive(), but only when every table was read: a
     * table that this unit may not read (D18) or whose read failed is
     * skipped, and the rows that the other tables do not hold keep their
     * state. The budget is checked before each chunk and each table; a chunk
     * that it stops is not applied, and the cursor stays before it. The end
     * of the rows clears the cursor, so the next batch starts at the first
     * row again. orrery:reconcile runs it as an operator action.
     *
     * @return array{checked: int, skipped: list<string>, stopped: bool, wrapped: bool}
     */
    public function reconcileRemoved(?TickBudget $budget = null): array
    {
        $mirror = $this->app->make(PackageSession::class)->mirror();
        $removed = $this->removedConnections($mirror);

        if ($removed === []) {
            // No row names a removed connection: no read of the active rows.
            $mirror->table(self::SETTINGS)->where('key', self::REMOVED_CURSOR)->delete();

            return ['checked' => 0, 'skipped' => [], 'stopped' => false, 'wrapped' => true];
        }

        $after = (int) ($mirror->table(self::SETTINGS)->where('key', self::REMOVED_CURSOR)->value('value') ?? 0);
        $batch = $this->activeBatchForTesting ?? self::ACTIVE_BATCH;

        $rows = $mirror->table(self::TABLE)
            ->whereIn('status', ['pending', 'reserved'])
            ->when($removed === null, fn ($query) => $query->whereNotIn('connection', $this->connections()))
            ->when($removed !== null, static fn ($query) => $query->whereIn('connection', $removed))
            ->whereNotNull('job_id')
            ->where('job_id_unresolved', false)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($batch)
            ->get(['id', 'uuid', 'connection', 'status', 'job_id', 'cycle', 'absent_since']);

        $targets = $this->queueTargets();
        $skipped = [];
        $checked = 0;
        $last = null;
        $stopped = false;

        foreach ($rows->chunk(self::REMOVED_CHUNK) as $chunk) {
            $found = $budget?->exhausted() ? null : $this->presentInTargets($chunk, $targets, $budget, $skipped);

            if ($found === null) {
                $stopped = true;

                break;
            }

            [$present, $complete] = $found;

            foreach ($chunk->groupBy('connection') as $connection => $group) {
                $this->applyActive($mirror, (string) $connection, $group, static fn (object $row): ?bool => isset($present[(int) $row->id]) ? true : ($complete ? false : null), self::REMOVED_REASON);
            }

            $checked += $chunk->count();
            $lastRow = $chunk->last();
            assert($lastRow !== null);
            $last = (int) $lastRow->id;
        }

        $wrapped = ! $stopped && $rows->count() < $batch;

        if ($wrapped) {
            $mirror->table(self::SETTINGS)->where('key', self::REMOVED_CURSOR)->delete();
        } elseif ($last !== null) {
            $this->storeSetting($mirror, self::REMOVED_CURSOR, (string) $last);
        }

        return ['checked' => $checked, 'skipped' => array_keys($skipped), 'stopped' => $stopped, 'wrapped' => $wrapped];
    }

    /**
     * The connection names of the mirror rows that are not configured
     * `database` queue connections, found by one seek of the `cq_pushed`
     * index per distinct name, so the usual case (no removed
     * connection) reads no active row. The names are byte-exact columns,
     * and PHP compares them with ===. Null when more than
     * MAX_CONNECTION_SEEKS names exist: the caller then filters the rows
     * against the configured names.
     *
     * @return list<string>|null
     */
    private function removedConnections(Connection $mirror): ?array
    {
        $configured = $this->connections();
        $removed = [];
        $after = null;

        for ($seek = 0; $seek <= self::MAX_CONNECTION_SEEKS; $seek++) {
            $name = $mirror->table(self::TABLE)
                ->when($after !== null, static fn ($query) => $query->where('connection', '>', $after))
                ->orderBy('connection')
                ->limit(1)
                ->value('connection');

            if ($name === null) {
                return $removed;
            }

            $after = (string) $name;

            if (! in_array($after, $configured, true)) {
                $removed[] = $after;
            }
        }

        return null;
    }

    /**
     * Batches of the failed rows with a bound record, at most
     * FAILED_ROWS_PER_TICK rows per tick and while the deadline lasts. The
     * keyset cursor (`reconcile_failed_cursor`) keeps the position between
     * ticks, and the end of the rows clears it. At most NATIVE_RETRY_SCANS
     * scans of a queue table, each only while the deadline lasts. False when
     * a read was not checked: a failed read of the records (the cursor
     * stays) or of a queue table (the cursor moves past that row, so one
     * failing scan never holds the rows after it), a record table that
     * cannot be resolved, or D18 refuses the read.
     */
    public function reconcileFailed(TickBudget $budget): bool
    {
        $mirror = $this->app->make(PackageSession::class)->mirror();
        $batchSize = $this->failedBatchForTesting ?? self::FAILED_BATCH;
        $remaining = $this->failedRowsPerTickForTesting ?? self::FAILED_ROWS_PER_TICK;
        $scans = $this->nativeRetryScansForTesting ?? self::NATIVE_RETRY_SCANS;
        $scanBudget = new TickBudget(Clock::monotonic() + min($budget->remaining(), self::NATIVE_RETRY_SECONDS));
        $records = null;

        do {
            $batch = min($batchSize, $remaining);
            $after = (int) ($mirror->table(self::SETTINGS)->where('key', self::FAILED_CURSOR)->value('value') ?? 0);

            $rows = $mirror->table(self::TABLE)
                ->where('status', 'failed')
                ->whereNull('operation_id')
                ->whereNotNull('provider_id')
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($batch)
                ->get(['id', 'uuid', 'occurrence_id', 'provider_id', 'provider_target', 'connection', 'queue', 'failed_at', 'retry_requested_at', 'absent_since']);

            if ($rows->isEmpty()) {
                $mirror->table(self::SETTINGS)->where('key', self::FAILED_CURSOR)->delete();

                return true;
            }

            // The record table is resolved only when a row needs it.
            $records ??= $this->failedRecords();

            if ($records === false) {
                return false;
            }

            if ($records === null) {
                // No record table to check against (the null failer, a read-only failer): the pass is over,
                // so a cursor of an earlier failer goes (orrery:reconcile would repeat the pass).
                $mirror->table(self::SETTINGS)->where('key', self::FAILED_CURSOR)->delete();

                return true;
            }

            $check = $this->checkFailed($records, $rows, $scanBudget, $scans);
            // A failed read reopens its session, which is the mirror's when the
            // queue's connection name is the mirror's: resolve the mirror again.
            $mirror = $this->app->make(PackageSession::class)->mirror();

            if ($check['stopped_after'] !== null) {
                // The pass stopped inside the batch: the next one resumes after this id.
                $this->storeSetting($mirror, self::FAILED_CURSOR, (string) $check['stopped_after']);

                return $check['checked'];
            }

            if (! $check['checked']) {
                return false;
            }

            if ($rows->count() < $batch) {
                $mirror->table(self::SETTINGS)->where('key', self::FAILED_CURSOR)->delete();

                return true;
            }

            $this->storeSetting($mirror, self::FAILED_CURSOR, (string) (int) $rows->last()->id);
            $remaining -= $rows->count();
        } while ($remaining > 0 && ! $budget->exhausted());

        return true;
    }

    /**
     * @param  int|null  $size  the batch size of the active rows; null restores 1,000
     */
    public static function useActiveBatchForTesting(?int $size): void
    {
        self::bound()->activeBatchForTesting = $size;
    }

    /**
     * @param  int|null  $size  the batch size of the failed rows; null restores 500
     */
    public static function useFailedBatchForTesting(?int $size): void
    {
        self::bound()->failedBatchForTesting = $size;
    }

    /**
     * @param  int|null  $rows  the failed rows per tick; null restores 1,000
     */
    public static function useFailedRowsPerTickForTesting(?int $rows): void
    {
        self::bound()->failedRowsPerTickForTesting = $rows;
    }

    /**
     * @param  int|null  $scans  the scans of a queue table per pass; null restores NATIVE_RETRY_SCANS
     */
    public static function useNativeRetryScansPerTickForTesting(?int $scans): void
    {
        self::bound()->nativeRetryScansForTesting = $scans;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @param  \Closure(\stdClass): ?bool  $isPresent  true present, false absent, null undecided (the row keeps its state)
     * @param  (\Closure(\stdClass): bool)|null  $blindGapLoss  whether a first absence is a loss of a blind gap (the class comment)
     */
    private function applyActive(Connection $mirror, string $connection, Collection $rows, \Closure $isPresent, string $reason, ?\Closure $blindGapLoss = null): void
    {
        $now = Clock::now();
        $time = Clock::toDecimal($now);
        $grace = $this->grace($connection);

        foreach ($rows as $row) {
            $jobId = (int) $row->job_id;
            $cycle = (int) $row->cycle;

            $state = $isPresent($row);

            if ($state === null) {
                continue;
            }

            if ($state) {
                if ($row->absent_since !== null) {
                    $this->inspected($mirror, $row)->update($blindGapLoss === null ? ['absent_since' => null] : ['absent_since' => null, 'unknown_reason' => null]);
                }

                continue;
            }

            if ($row->absent_since === null) {
                // The first absence. A row that moved on in the meantime keeps its state.
                $this->inspected($mirror, $row)->whereNull('absent_since')->update($blindGapLoss === null
                    ? ['absent_since' => $time]
                    : ['absent_since' => $time, 'unknown_reason' => $blindGapLoss($row) ? self::BLIND_GAP_REASON : null]);

                continue;
            }

            if ($now - Clock::stored($row->absent_since) >= $grace) {
                // The reason of the first absence: a loss of a blind gap keeps it.
                $marked = $blindGapLoss !== null && ($row->unknown_reason ?? null) === self::BLIND_GAP_REASON ? self::BLIND_GAP_REASON : $reason;
                $this->store->markUnknown((string) $row->uuid, $jobId, $cycle, $marked, $now);
            }
        }
    }

    /**
     * Whether a first absence is a loss of one of $gaps (the class comment):
     * the row's last observation before the gap's end, and the last complete
     * sweep $swept from at most BlindGaps::GAP_SECONDS before the gap's start
     * to before its end.
     *
     * @param  list<array{0: float, 1: float}>  $gaps
     */
    private static function blindGapLoss(array $gaps, ?float $swept, mixed $activityAt): bool
    {
        if (! is_numeric($activityAt)) {
            return false;
        }

        if ($swept === null) {
            // No complete sweep before the gap: nothing proves that the job was there when observation stopped.
            return false;
        }

        $activity = Clock::stored($activityAt);

        foreach ($gaps as [$from, $to]) {
            if ($activity < $to && $swept < $to && $swept >= $from - BlindGaps::GAP_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row as the check inspected it: its id, status, job id and cycle,
     * with a resolved job id.
     *
     * @param  \stdClass  $row
     */
    private function inspected(Connection $mirror, object $row): Builder
    {
        return $mirror->table(self::TABLE)
            ->where('id', $row->id)
            ->where('status', $row->status)
            ->where('job_id', $row->job_id)
            ->where('cycle', $row->cycle)
            ->where('job_id_unresolved', false);
    }

    /**
     * The job ids that the queue table of the connection holds, read on the
     * observer session of its database connection. Null when the read
     * failed ("not checked").
     *
     * @param  array<int, int>  $ids
     * @return array<int, true>|null
     */
    private function presentJobs(string $connection, array $ids): ?array
    {
        $session = null;
        $table = (string) ($this->config()->get("queue.connections.{$connection}.table") ?: 'jobs');

        try {
            $session = $this->sessions->forAction($this->sessions->databaseConnectionFor($connection));
            Hooks::reach(self::BEFORE_ABSENCE_CHECK);
            $found = [];

            // At most BINDINGS ids per statement: SQLite before 3.32 takes at most 999 variables.
            foreach (array_chunk($ids, self::BINDINGS) as $chunk) {
                array_push($found, ...$session->table($table)->whereIn('id', $chunk)->pluck('id')->all());
            }
        } catch (Throwable $error) {
            if (! ErrorKinds::isHostFileRefusal($error)) {
                $this->notChecked($session, "The jobs of the queue connection [{$connection}] were not checked: ".$error->getMessage(), $error);
            }

            return null;
        }

        Hooks::reach(self::AFTER_ABSENCE_CHECK);

        $present = [];

        foreach ($found as $id) {
            $present[(int) $id] = true;
        }

        return $present;
    }

    /**
     * Each distinct database connection and table of the configured
     * `database` queue connections.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function queueTargets(): array
    {
        $targets = [];

        foreach ($this->connections() as $connection) {
            $database = $this->sessions->databaseConnectionFor($connection);
            $table = (string) ($this->config()->get("queue.connections.{$connection}.table") ?: 'jobs');
            $targets[$database."\0".$table] = [$database, $table];
        }

        return $targets;
    }

    /**
     * The mirror ids of the rows whose job a configured queue table holds
     * (the table's row with the job id has the row's uuid as payload
     * identity, PayloadIdentity::uuidOf()), read on the observer session of
     * each target, and whether every target was read. A target that this
     * unit may not read (D18), or whose read failed (reported as not
     * checked), is skipped and named in $skipped; a failed target is not read
     * again in this batch. Null when the budget ended before a target.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<string, array{0: string, 1: string}>  $targets
     * @param  array<string, true>  $skipped
     * @return array{0: array<int, true>, 1: bool}|null
     */
    private function presentInTargets(Collection $rows, array $targets, ?TickBudget $budget, array &$skipped): ?array
    {
        $ids = $rows->pluck('job_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $present = [];
        $complete = true;

        foreach ($targets as [$database, $table]) {
            $name = "the table [{$table}] of the connection [{$database}]";

            if ($budget?->exhausted()) {
                return null;
            }

            if (isset($skipped[$name]) || ! $this->readable($database)) {
                // D18, or a read that failed before: this table proves nothing.
                $skipped[$name] = true;
                $complete = false;

                continue;
            }

            $session = null;

            try {
                $session = $this->sessions->forAction($database);
                Hooks::reach(self::BEFORE_ABSENCE_CHECK);
                $payloads = $session->table($table)->whereIn('id', $ids)->pluck('payload', 'id');
            } catch (Throwable $error) {
                if (! ErrorKinds::isHostFileRefusal($error)) {
                    $this->notChecked($session, "The jobs of the removed queue connections were not checked in {$name}: ".$error->getMessage(), $error);
                }

                $skipped[$name] = true;
                $complete = false;

                continue;
            }

            Hooks::reach(self::AFTER_ABSENCE_CHECK);

            foreach ($rows as $row) {
                $payload = $payloads[(int) $row->job_id] ?? null;

                if ($payload !== null && PayloadIdentity::uuidOf($payload) === strtolower((string) $row->uuid)) {
                    $present[(int) $row->id] = true;
                }
            }
        }

        return [$present, $complete];
    }

    /**
     * The failed-record table of the bound failer; null when it has none
     * (the null failer, a read-only failer); false when this unit may not
     * read it (D18: the failed rows stay unknown; orrery:reconcile checks
     * them) or when it cannot be resolved (not checked).
     */
    private function failedRecords(): SqlFailedRecords|false|null
    {
        try {
            $records = FailedRecords::for($this->app, $this->config());
        } catch (HostFileAccessNotAllowed) {
            // D18: the ids check may not read a host SQLite file outside an operator action.
            return false;
        } catch (Throwable $error) {
            $this->notChecked(null, 'The failed-job records were not checked: the failed-job table cannot be resolved.', $error);

            return false;
        }

        if (! $records instanceof SqlFailedRecords) {
            return null;
        }

        return $this->readable($records->connection()) ? $records : false;
    }

    /**
     * Checks one batch of failed rows. `checked` is false when a read failed
     * (not checked). `stopped_after` is the mirror id after which the next
     * pass resumes when this one stops inside the batch: before a scan of a
     * queue table that the scan count or the deadline does not allow (the
     * row is not checked yet), or after a failed scan (that row is skipped).
     * Else it is null, and a failed read of the records keeps the cursor.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array{checked: bool, stopped_after: int|null}
     */
    private function checkFailed(SqlFailedRecords $records, Collection $rows, TickBudget $budget, int &$scans): array
    {
        $target = $records->target();
        $checked = [];
        $drifted = 0;

        foreach ($rows as $row) {
            if (is_string($row->provider_target) && hash_equals($target, trim($row->provider_target))) {
                $checked[] = $row;
            } else {
                $drifted++;
            }
        }

        if ($drifted > 0) {
            // Target drift: these rows are bound to another failed-job table than the configured one.
            $this->notChecked(null, sprintf('target_changed: %d failed row(s) are bound to another failed-job table than the configured one (the failed-job storage changed), so reconciliation did not check them.', $drifted), null);
        }

        if ($checked === []) {
            return ['checked' => true, 'stopped_after' => null];
        }

        $pairs = array_map(
            /** @param  \stdClass  $row */
            static fn (object $row): array => ['id' => (int) $row->provider_id, 'uuid' => strtolower((string) $row->uuid)],
            $checked,
        );

        try {
            Hooks::reach(self::BEFORE_ABSENCE_CHECK);
            $present = $records->present($pairs, $target);
        } catch (Throwable $error) {
            if (! ErrorKinds::isHostFileRefusal($error)) {
                $this->notChecked($this->recordSession($records), 'The failed-job records were not checked: '.$error->getMessage(), $error);
            }

            return ['checked' => false, 'stopped_after' => null];
        }

        Hooks::reach(self::AFTER_ABSENCE_CHECK);

        $mirror = $this->app->make(PackageSession::class)->mirror();
        $now = Clock::now();

        foreach ($checked as $row) {
            if (isset($present[SqlFailedRecords::pairKey((int) $row->provider_id, strtolower((string) $row->uuid))])) {
                if ($row->absent_since !== null) {
                    // Present again (for example a failed-job insert that committed late).
                    $this->failedSnapshot($mirror, $row)->update(['absent_since' => null]);
                }

                continue;
            }

            if ($row->absent_since === null) {
                // The first absence proves nothing yet: the record can belong to
                // a transaction that has not committed (a job that fails inside
                // DB::transaction()).
                $this->failedSnapshot($mirror, $row)->update(['absent_since' => Clock::toDecimal($now)]);

                continue;
            }

            if ($now - Clock::stored($row->absent_since) < $this->grace((string) $row->connection)) {
                continue;
            }

            if ($this->needsScan($row)) {
                if (! $this->readable($this->sessions->databaseConnectionFor((string) $row->connection))) {
                    // D18 refuses the read of the queue's file: the row stays
                    // as it is (orrery:reconcile checks it), and the pass goes on.
                    continue;
                }

                if ($scans <= 0 || $budget->exhausted()) {
                    // This row and the rows after it wait for the next pass.
                    return ['checked' => true, 'stopped_after' => (int) $row->id - 1];
                }

                $scans--;
            }

            $pending = $this->nativeRetryPending($row);

            if ($pending === null) {
                // Not checked: the session was reopened, so the pass stops
                // here, and the next one starts after this row.
                return ['checked' => false, 'stopped_after' => (int) $row->id];
            }

            if ($pending) {
                continue;
            }

            // The delete is bound to the state that this check read: the
            // occurrence, the bound record, the native retry request and the
            // absence marker.
            $this->forget->removeAbsent((string) $row->uuid, (int) $row->occurrence_id, (int) $row->provider_id, [
                'retry_requested_at' => self::decimal($row->retry_requested_at),
                'absent_since' => self::decimal($row->absent_since),
            ]);
        }

        return ['checked' => true, 'stopped_after' => null];
    }

    private function recordSession(SqlFailedRecords $records): ?Connection
    {
        try {
            return $this->sessions->forAction($records->connection());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The failed row as this check read it: its id, occurrence, bound
     * record, native retry request and absence marker.
     *
     * @param  \stdClass  $row
     */
    private function failedSnapshot(Connection $mirror, object $row): Builder
    {
        $query = $mirror->table(self::TABLE)
            ->where('id', $row->id)
            ->where('occurrence_id', $row->occurrence_id)
            ->where('status', 'failed')
            ->whereNull('operation_id')
            ->where('provider_id', $row->provider_id);

        foreach (['retry_requested_at', 'absent_since'] as $column) {
            $value = self::decimal($row->{$column});
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    private static function decimal(mixed $value): ?string
    {
        return $value === null ? null : Clock::toDecimal((float) $value);
    }

    /**
     * A native retry of this failure was requested (queue:retry deletes the
     * provider record and pushes the job again), and the queue table still
     * holds a job whose payload identity is the row's uuid: its reservation
     * starts the next cycle (Rules::nativeRetry()), so the row stays. The identity is the one of
     * the lifecycle (PayloadIdentity::uuidOf(): the top-level uuid, else a
     * canonical id, in any case). Candidates are the payloads of the row's queue that mention the
     * uuid in any case; each one is decoded, so a mention inside another JSON
     * object proves nothing. In doubt (an undecodable candidate, more than
     * RETRY_CANDIDATES candidates, a D18 refusal of the queue's file) the row
     * stays. Null when the read failed (not checked).
     *
     * @param  \stdClass  $row
     */
    private function nativeRetryPending(object $row): ?bool
    {
        if (! $this->needsScan($row)) {
            return false;
        }

        $connection = (string) $row->connection;

        $uuid = strtolower((string) $row->uuid);
        $table = (string) ($this->config()->get("queue.connections.{$connection}.table") ?: 'jobs');
        $session = null;

        if (! $this->readable($this->sessions->databaseConnectionFor($connection))) {
            // D18: in doubt the row stays (the caller asks first).
            return true;
        }

        try {
            $session = $this->sessions->forAction($this->sessions->databaseConnectionFor($connection));
            // queue:retry pushes the job onto its recorded queue: only that
            // queue's jobs are read (Laravel's jobs table has an index on queue).
            $payloads = $session->table($table)
                ->whereRaw('lower(payload) like ?', ['%'.$uuid.'%'])
                ->where('queue', (string) $row->queue)
                ->orderBy('id')
                ->limit(self::RETRY_CANDIDATES + 1)
                ->pluck('payload');
        } catch (Throwable $error) {
            if (ErrorKinds::isHostFileRefusal($error)) {
                // A late D18 refusal is no failed read: in doubt the row stays.
                return true;
            }

            $this->notChecked($session, "The queue of the native retry of job {$uuid} was not checked: ".$error->getMessage(), $error);

            return null;
        }

        if ($payloads->count() > self::RETRY_CANDIDATES) {
            return true;
        }

        foreach ($payloads as $payload) {
            if (! is_array(json_decode((string) $payload, true))) {
                return true;
            }

            if (PayloadIdentity::uuidOf((string) $payload) === $uuid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the check of a row past its grace scans a queue table: its
     * native retry was requested after the failure, on a `database` queue
     * connection.
     *
     * @param  \stdClass  $row
     */
    private function needsScan(object $row): bool
    {
        if ($row->retry_requested_at === null || $row->failed_at === null || (float) $row->retry_requested_at < (float) $row->failed_at) {
            return false;
        }

        return $this->config()->get('queue.connections.'.(string) $row->connection.'.driver') === 'database';
    }

    /**
     * The grace of markUnknown for a queue connection: max(retry_after, 600) seconds.
     */
    private function grace(string $connection): float
    {
        $retryAfter = $this->config()->get("queue.connections.{$connection}.retry_after", self::DEFAULT_RETRY_AFTER);

        return (float) max(is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER, self::MIN_GRACE_SECONDS);
    }

    /**
     * Whether this unit may read a database connection: inside an operator
     * action (orrery:reconcile) always; else only when the connection is not
     * on a host SQLite file that refuses automatic reads (D18). The callers
     * ask first, so a refusal is never reported as "not checked".
     */
    private function readable(string $database): bool
    {
        return $this->sessions->operatorContext() !== null || $this->hostFiles->allowsReads($database);
    }

    /**
     * A read that proves nothing: the health events record it, and the
     * session is reopened, so the next read measures on a new PDO.
     */
    private function notChecked(?Connection $session, string $message, ?Throwable $error): void
    {
        Degradation::error(self::HEALTH_NOT_CHECKED, $message);
        Watcher::instance()->report(self::HEALTH_NOT_CHECKED, $error ?? $message);

        if ($session === null) {
            return;
        }

        try {
            $this->sessions->reopen($session);
        } catch (Throwable) {
            // The next tick opens a new session.
        }
    }

    /**
     * The cursor of the active rows of a connection: the last checked mirror
     * id, and the start time of its sweep (now for a new sweep).
     *
     * @return array{after: int, started_at: string}
     */
    private function cursor(Connection $mirror, string $connection): array
    {
        $value = $mirror->table(self::SETTINGS)->where('key', self::CURSOR_PREFIX.$connection)->value('value');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (is_array($decoded) && is_int($decoded['after'] ?? null) && is_string($decoded['started_at'] ?? null) && is_numeric($decoded['started_at'])) {
            return ['after' => $decoded['after'], 'started_at' => $decoded['started_at']];
        }

        return ['after' => 0, 'started_at' => Clock::toDecimal(Clock::now())];
    }

    private function storeSetting(Connection $mirror, string $key, string $value): void
    {
        $mirror->table(self::SETTINGS)->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
    }

    /**
     * The application's instance (a container binding).
     */
    private static function bound(): self
    {
        return \Illuminate\Container\Container::getInstance()->make(self::class);
    }

    /**
     * The configuration of the running request, read when it is used: this
     * object is resolved at boot, and an Octane request has its
     * own configuration sandbox.
     */
    private function config(): Repository
    {
        return \Illuminate\Container\Container::getInstance()->make('config');
    }
}
