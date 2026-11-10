<?php

namespace Justrau\Orrery\Actions;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Carbon;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Monitoring\WaitTime;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Runtime\WatchResult;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;

/**
 * The waiting (unreserved) jobs of a database queue: the one service of
 * `orrery:clear`, `POST /api/queues/clear` (clear()),
 * its count (count()) and `POST /api/jobs/{uuid}/delete` (deleteOne()).
 * Call every method inside an operator action (PackageSession::asOperator(),
 * D17): its writes may reach a host SQLite file.
 *
 * **clear()** deletes only the waiting jobs of one queue, in batches (no
 * fence row: each batch is one transaction on the queue connection).
 *
 * 1. It selects up to BATCH unreserved ids of the queue on the observer
 *    session (a writer clone, so a read replica never answers).
 * 2. It deletes those ids through the queue connection, in one transaction
 *    that first locks them with `reserved_at IS NULL` re-checked. So a job
 *    that a worker reserved after step 1 stays, and the deleted ids are
 *    exactly the locked ones.
 * 3. One mirror transaction deletes the mirror rows of the deleted ids
 *    (`pending` or `unknown`, on any alias of the queue connection: the
 *    queue connections whose queue objects share its jobs table,
 *    WaitTime::aliasesOf()), with their tags, failed tags, history, cycle
 *    summaries and stuck incidents, as retention does. Provider cleanup rows
 *    and claim receipts stay.
 *
 * The queue name is byte-exact (on MySQL also `CAST(queue AS BINARY)`), as
 * the Queues screen lists it: a clear of `high` never deletes `HIGH`.
 *
 * Unresolved bulk rows have job_id NULL, so step 3 cannot match them by id.
 * Per batch:
 * - inside step 2's transaction, after the lock and before the delete, one
 *   mirror probe reads MIN(bulk_floor_id), as one statement over a UNION
 *   ALL of two branches: the unresolved rows (`job_id_unresolved`, job_id
 *   NULL, `pending` or `unknown`; the `unresolved` index) and the rows that
 *   the bulk resolution marked `unknown` / `bulk_unresolved` (job_id NULL;
 *   an index that starts with status), each of the aliases. Without such
 *   rows (the normal case), the batch reads no payload. On a SQLite queue
 *   connection without WAL, the probe runs just before step 2's transaction
 *   instead: the mirror session is a second connection, and its read inside
 *   the transaction would hold a shared lock with the queue's, so a worker's
 *   commit in that window would wait for the busy timeout (SQLite has no row
 *   locks anyway);
 * - else it reads `id` and `payload` of the locked ids above that floor, in
 *   chunks of PAYLOAD_CHUNK, and decodes the uuid (lower-cased) and
 *   `orreryGen`;
 * - the mirror transaction locks the unresolved rows of those uuids (the
 *   deleted jobs only, at most UUID_CHUNK uuids per statement), keeps those
 *   whose cycle_gen equals the payload's orreryGen (both NULL also matches),
 *   and deletes them with their dependents, as in step 3;
 * - a payload that cannot be decoded leaves its row (`undecodable`);
 * - a probe that fails keeps the batch: the transaction deletes nothing and
 *   the clear stops (`probe_failed`), so a later clear can still match the
 *   unresolved rows through the payloads of those jobs.
 *
 * A time budget (the API's BUDGET_SECONDS) stops the clear before the next
 * batch (`time_budget`). A queue connection inside a transaction is refused
 * (`in_transaction`): the batches commit one by one, and an outer
 * transaction would hold every lock and could roll them all back.
 *
 * Known limit (deferred with the bulk capture): the bulk capture writes its
 * mirror rows after the parent's insert has committed. If a bulk push
 * commits its jobs and the clear deletes them before their mirror rows
 * exist, those rows stay, and the result cannot count them. On SQLite
 * without WAL, the probe runs before the batch's transaction, so a bulk push
 * whose jobs and mirror rows both commit between the probe and the lock is
 * not matched when the probe found no unresolved row: its mirror rows stay,
 * as above.
 *
 * Unlike Laravel's queue:clear, it never deletes reserved jobs. A mirror
 * error after step 2 leaves those mirror rows to reconciliation, which marks
 * them `unknown` (`mirror_failed`). An error of the queue connection in the
 * first batch reaches the caller (nothing was deleted); after a committed
 * batch it ends the clear with the counts so far (`write_failed`).
 *
 * **deleteOne()** deletes one waiting job by its mirror row: the row must be
 * `pending` with a resolved job_id. One transaction on the queue connection
 * locks the host row with `reserved_at IS NULL` checked again, checks that
 * its payload's uuid is the job's, and deletes it; then the mirror rows go
 * as in step 3. Results: `deleted`; `not_pending` (the host row is reserved,
 * or gone while the mirror row is no longer pending); `gone` (the host row
 * is gone and the mirror row is still pending); `job_id_unknown` (an
 * unresolved bulk row, which the bulk resolution may still resolve);
 * `mirror_failed` (the host row is deleted, its mirror row stays);
 * `not_found` (no mirror row). A queue connection inside a transaction is
 * refused (`in_transaction`), and an SQLITE_BUSY answer runs the transaction
 * again (retryBusy()).
 *
 * **count()** counts the waiting jobs of one queue (ready and delayed, by
 * the workers' clock) on the observer session, and the pending mirror rows
 * of those jobs that belong to a batch.
 */
final class WaitingJobs
{
    /**
     * The ids per batch: with the other bindings of a statement it stays
     * below 999 variables, the limit of SQLite before 3.32.
     */
    public const BATCH = 900;

    /** The payloads per read of the unresolved case. */
    public const PAYLOAD_CHUNK = 100;

    /** The uuids or ids per mirror statement, below 999 variables with the others. */
    public const UUID_CHUNK = 900;

    /** The attempts of one batch transaction when SQLite answers SQLITE_BUSY (deleteWaitingRetried()). */
    public const BUSY_ATTEMPTS = 3;

    /** The time budget of a clear from the dashboard, in seconds. */
    public const BUDGET_SECONDS = 15;

    public const DELETED = 'deleted';

    public const NOT_PENDING = 'not_pending';

    public const GONE = 'gone';

    public const JOB_ID_UNKNOWN = 'job_id_unknown';

    public const MIRROR_FAILED = 'mirror_failed';

    public const NOT_FOUND = 'not_found';

    private const MIRROR_TABLES = ['orrery_job_tags', FailedTags::TABLE, 'orrery_job_history', CycleSummaries::TABLE];

    private const WAITING_STATES = ['pending', 'unknown'];

    /** The batch size of a test (useBatchForTesting()); the service is a singleton. */
    private ?int $batchForTesting = null;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly IncidentCleanup $incidents,
        private readonly WaitTime $waits,
    ) {}

    /**
     * @param  int|null  $size  the batch size; null restores BATCH
     */
    public static function useBatchForTesting(?int $size): void
    {
        Container::getInstance()->make(self::class)->batchForTesting = $size;
    }

    /**
     * The database queue of a queue connection, else ActionRefused
     * (`not_database_queue`).
     */
    public function queue(string $connection): DatabaseQueue
    {
        $config = Container::getInstance()->make('config');

        if ($config->get("queue.connections.{$connection}.driver") !== 'database') {
            throw new ActionRefused('not_database_queue', "The queue connection [{$connection}] does not use the database driver.");
        }

        $queue = Container::getInstance()->make('queue')->connection($connection);

        if (! $queue instanceof DatabaseQueue) {
            throw new ActionRefused('not_database_queue', "The queue connection [{$connection}] is not a database queue.");
        }

        return $queue;
    }

    /**
     * ActionRefused (`in_transaction`) when the queue connection is inside a
     * transaction: the action's own transaction would join it, so its write
     * would commit or roll back with the application's, not on its own.
     *
     * @throws ActionRefused
     */
    public static function refuseInTransaction(Connection $database, string $connection): void
    {
        if ($database->transactionLevel() > 0) {
            throw new ActionRefused('in_transaction', "The queue connection [{$connection}] is inside a transaction, so nothing changed.");
        }
    }

    /**
     * $work (one transaction on the queue connection), again at most
     * BUSY_ATTEMPTS times in all when SQLite answers SQLITE_BUSY: in WAL, a
     * deferred transaction that read first cannot write after another
     * process committed, and SQLite then fails at once instead of waiting.
     * The transaction rolled back, so the work runs again from its start.
     * Only the driver's result code counts, never a message (which can hold
     * a bound value).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function retryBusy(Connection $database, Closure $work): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $work();
            } catch (\Throwable $error) {
                if ($attempt >= self::BUSY_ATTEMPTS || $database->getDriverName() !== 'sqlite' || ! self::isSqliteBusy($error)) {
                    throw $error;
                }
            }
        }
    }

    /**
     * Deletes the waiting jobs of one queue (see the class docblock).
     *
     * @param  float|null  $budgetSeconds  the time after which no new batch starts; null: no limit
     *
     * @throws ActionRefused
     */
    public function clear(string $connection, string $queueName, ?float $budgetSeconds = null): ClearResult
    {
        $queue = $this->queue($connection);
        $database = $queue->getDatabase();

        self::refuseInTransaction($database, $connection);

        $aliases = $this->waits->aliasesOf($connection);
        $table = self::tableOf($queue);
        $observer = $this->sessions->forAction($this->sessions->databaseConnectionFor($connection));
        $batch = $this->batchForTesting ?? self::BATCH;
        $started = Clock::monotonic();
        $after = 0;
        $jobs = 0;
        $mirrorRows = 0;
        $unresolvedRows = 0;
        $undecodable = 0;
        $mirrorFailed = false;
        $countMismatches = 0;
        $reason = null;
        $batches = 0;
        $probeFirst = self::probesBeforeTheTransaction($database);

        while (true) {
            // The first batch always runs; the budget stops only a later one.
            if ($budgetSeconds !== null && $batches > 0 && Clock::monotonic() - $started >= $budgetSeconds) {
                $reason = ClearResult::TIME_BUDGET;

                break;
            }

            try {
                $ids = self::whereQueue($observer->table($table), $observer, $queueName)
                    ->whereNull('reserved_at')
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit($batch)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if ($ids === []) {
                    break;
                }

                $after = max($ids);
                $batches++;
                $early = $probeFirst ? $this->probe($aliases) : null;
                $result = $this->deleteWaitingRetried($database, $table, $queueName, $aliases, $ids, $early);
            } catch (\Throwable $error) {
                if ($batches <= 1 && $jobs === 0) {
                    // Nothing committed yet: the caller sees the error itself.
                    throw $error;
                }

                // Earlier batches committed: their counts are the answer, with the stop's reason.
                Watcher::instance()->report('clear:batch', $error);
                $reason = ClearResult::WRITE_FAILED;

                break;
            }
            $deleted = $result['deleted'];
            $countMismatches += $result['mismatch'] ? 1 : 0;
            $undecodable += $result['undecodable'];
            $jobs += count($deleted);

            if ($result['probe_failed']) {
                // Nothing of this batch was deleted: stop here, so the jobs stay for the next clear.
                $reason = ClearResult::PROBE_FAILED;

                break;
            }

            if ($deleted !== []) {
                // Only the generations of the jobs that are gone.
                $generations = array_intersect_key($result['generations'], array_flip($deleted));
                $removed = Watcher::instance()->run('clear:mirror', fn (): array => $this->deleteMirrorRows($aliases, $deleted, array_values($generations)), null);

                if (is_array($removed)) {
                    $mirrorRows += $removed['rows'];
                    $unresolvedRows += $removed['unresolved'];
                } else {
                    $mirrorFailed = true;
                }
            }

            if (count($ids) < $batch) {
                break;
            }
        }

        return new ClearResult($jobs, $mirrorRows, $unresolvedRows, $undecodable, $countMismatches, $mirrorFailed, $reason === null, $reason);
    }

    /**
     * The waiting jobs of one queue: `ready` (available_at <= now, by the
     * workers' clock) and `delayed`, from one read on the observer session,
     * and `in_batches`, the pending mirror rows of the queue (on any alias)
     * with a batch. A part that cannot be read is null, never 0 (the mirror
     * part is reported through the watcher; the queue part reaches the
     * caller). `in_batches_at_least` is true when the mirror has fewer
     * pending rows than the queue has waiting jobs: jobs without a row may
     * be in batches too. Both reads are bounded (PackageSession::bounded()).
     *
     * @return array{ready: int, delayed: int, in_batches: int|null, in_batches_at_least: bool}
     *
     * @throws ActionRefused
     */
    public function count(string $connection, string $queueName): array
    {
        $queue = $this->queue($connection);
        $table = self::tableOf($queue);
        $observer = $this->sessions->forAction($this->sessions->databaseConnectionFor($connection));
        $now = Carbon::now()->getTimestamp();
        $row = $this->sessions->bounded($observer, static fn (Connection $session): ?\stdClass => self::whereQueue($session->table($table), $session, $queueName)
            ->whereNull('reserved_at')
            ->selectRaw('SUM(CASE WHEN available_at <= ? THEN 1 ELSE 0 END) AS ready_count, SUM(CASE WHEN available_at > ? THEN 1 ELSE 0 END) AS delayed_count', [$now, $now])
            ->first());
        $ready = (int) ($row->ready_count ?? 0);
        $delayed = (int) ($row->delayed_count ?? 0);
        $aliases = $this->waits->aliasesOf($connection);
        $mirror = Watcher::instance()->run('clear:count_batches', function () use ($aliases, $queueName): array {
            return $this->sessions->bounded($this->sessions->mirror(), static function (Connection $session) use ($aliases, $queueName): array {
                $counts = self::whereQueue($session->table(JobStore::TABLE), $session, $queueName)
                    ->whereIn('connection', $aliases)
                    ->where('status', 'pending')
                    ->selectRaw('COUNT(*) AS pending_count, SUM(CASE WHEN batch_id IS NOT NULL THEN 1 ELSE 0 END) AS batched_count')
                    ->first();

                return [(int) ($counts->pending_count ?? 0), (int) ($counts->batched_count ?? 0)];
            });
        }, null);

        return [
            'ready' => $ready,
            'delayed' => $delayed,
            'in_batches' => is_array($mirror) ? $mirror[1] : null,
            // Fewer pending rows here than waiting jobs: some jobs have no row yet, so the batch count is a lower bound.
            'in_batches_at_least' => is_array($mirror) && $mirror[0] < $ready + $delayed,
        ];
    }

    /**
     * Deletes one waiting job (see the class docblock): one of the result
     * constants, and the mirror row's connection, queue and batch for the
     * audit. A mirror read that fails, and an error of the queue connection,
     * reach the caller.
     *
     * @return array{result: string, connection: string|null, queue: string|null, batch_id: string|null}
     *
     * @throws ActionRefused
     */
    public function deleteOne(string $uuid): array
    {
        $row = $this->mirrorRow($uuid);

        if ($row === null) {
            return ['result' => self::NOT_FOUND, 'connection' => null, 'queue' => null, 'batch_id' => null];
        }

        $facts = ['connection' => (string) $row->connection, 'queue' => (string) $row->queue, 'batch_id' => is_string($row->batch_id ?? null) ? $row->batch_id : null];

        if ($row->status !== 'pending') {
            return ['result' => self::NOT_PENDING] + $facts;
        }

        if ($row->job_id === null) {
            return ['result' => self::JOB_ID_UNKNOWN] + $facts;
        }

        $jobId = (int) $row->job_id;
        $queue = $this->queue($facts['connection']);
        $database = $queue->getDatabase();
        self::refuseInTransaction($database, $facts['connection']);
        $table = self::tableOf($queue);
        $locked = self::retryBusy($database, static fn (): string => $database->transaction(static function (Connection $database) use ($table, $jobId, $uuid): string {
            $job = $database->table($table)->where('id', $jobId)->whereNull('reserved_at')->lockForUpdate()->first(['id', 'payload']);

            if ($job === null) {
                return $database->table($table)->where('id', $jobId)->exists() ? self::NOT_PENDING : self::GONE;
            }

            if (PayloadIdentity::uuidOf($job->payload ?? null) !== $uuid) {
                // The id now holds another job (SQLite can reuse the id of a deleted row): this job is gone.
                return self::GONE;
            }

            return $database->table($table)->where('id', $jobId)->whereNull('reserved_at')->delete() === 1 ? self::DELETED : self::NOT_PENDING;
        }));

        if ($locked === self::GONE) {
            // The host row is gone: the mirror row decides whether the job ran or is lost.
            $current = $this->mirrorRow($uuid);

            return ['result' => $current !== null && $current->status === 'pending' ? self::GONE : self::NOT_PENDING] + $facts;
        }

        if ($locked !== self::DELETED) {
            return ['result' => $locked] + $facts;
        }

        $removed = Watcher::instance()->run('delete:mirror', fn (): array => $this->deleteMirrorRows($this->waits->aliasesOf($facts['connection']), [$jobId], []), null);

        return ['result' => is_array($removed) ? self::DELETED : self::MIRROR_FAILED] + $facts;
    }

    private function mirrorRow(string $uuid): ?\stdClass
    {
        /** @var \stdClass|null */
        return $this->sessions->bounded($this->sessions->mirror(), static fn (Connection $session): ?\stdClass => $session->table(JobStore::TABLE)->where('uuid', $uuid)->first(['uuid', 'connection', 'queue', 'status', 'job_id', 'batch_id']));
    }

    /**
     * The jobs table of the queue object that the queue manager holds, as
     * WaitTime reads it.
     */
    public static function tableOf(DatabaseQueue $queue): string
    {
        $table = Closure::bind(static fn (DatabaseQueue $queue): string => (string) $queue->table, null, DatabaseQueue::class)($queue);

        return $table === '' ? 'jobs' : $table;
    }

    /**
     * `queue = ?`, byte-exact: MySQL also compares `CAST(queue AS BINARY)`,
     * so the index still serves the first term.
     */
    public static function whereQueue(Builder $query, Connection $connection, string $queue): Builder
    {
        $query->where('queue', $queue);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->whereRaw('CAST(`queue` AS BINARY) = CAST(? AS BINARY)', [$queue]);
        }

        return $query;
    }

    /**
     * deleteWaiting(), again at most BUSY_ATTEMPTS times in all when SQLite
     * answers SQLITE_BUSY: in WAL, a deferred transaction that read first
     * cannot write after another process committed, and SQLite then fails at
     * once instead of waiting. The transaction rolled back, so the batch runs
     * again from its lock. Only the driver's result code counts, never a
     * message (which can hold a bound value).
     *
     * @param  list<string>  $aliases
     * @param  array<int, int>  $ids
     * @return array{deleted: array<int, int>, mismatch: bool, generations: array<int, array{0: string, 1: ?string}>, undecodable: int, probe_failed: bool}
     */
    private function deleteWaitingRetried(Connection $database, string $table, string $queueName, array $aliases, array $ids, ?WatchResult $early): array
    {
        return self::retryBusy($database, fn (): array => $this->deleteWaiting($database, $table, $queueName, $aliases, $ids, $early));
    }

    /**
     * SQLITE_BUSY by the driver's result code (5, with its extended codes).
     */
    private static function isSqliteBusy(\Throwable $error): bool
    {
        for ($current = $error, $depth = 0; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
            if ($current instanceof \PDOException && is_array($current->errorInfo) && (((int) ($current->errorInfo[1] ?? 0)) & 0xFF) === 5) {
                return true;
            }
        }

        return false;
    }

    /**
     * Step 2: locks the ids that are still unreserved, and deletes exactly
     * those, in one transaction on the queue's own connection. After the
     * lock and before the delete, it probes the mirror for unresolved bulk
     * rows, and only then reads the payloads above their lowest floor.
     *
     * @param  list<string>  $aliases
     * @param  array<int, int>  $ids
     * @return array{deleted: array<int, int>, mismatch: bool, generations: array<int, array{0: string, 1: ?string}>, undecodable: int, probe_failed: bool} the deleted ids, whether the delete count differed from the locked ids, the decoded uuid and generation per locked id, the payloads that could not be decoded, and whether the probe failed
     */
    private function deleteWaiting(Connection $database, string $table, string $queueName, array $aliases, array $ids, ?WatchResult $early): array
    {
        return $database->transaction(function (Connection $database) use ($table, $queueName, $aliases, $ids, $early): array {
            $locked = self::whereQueue($database->table($table), $database, $queueName)
                ->whereIn('id', $ids)
                ->whereNull('reserved_at')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($locked === []) {
                return ['deleted' => [], 'mismatch' => false, 'generations' => [], 'undecodable' => 0, 'probe_failed' => false];
            }

            ['generations' => $generations, 'undecodable' => $undecodable, 'failed' => $probeFailed] = $this->unresolvedGenerations($database, $table, $early ?? $this->probe($aliases), $locked);

            if ($probeFailed) {
                // The unresolved bulk rows can be matched only through the payloads of jobs that
                // are still in the table, so the batch is kept, and a later clear can check them.
                return ['deleted' => [], 'mismatch' => false, 'generations' => [], 'undecodable' => 0, 'probe_failed' => true];
            }

            $deleted = $database->table($table)->whereIn('id', $locked)->whereNull('reserved_at')->delete();

            if ($deleted !== count($locked)) {
                // The lock did not hold (for example a connection without
                // row locks): only the ids that are gone count as deleted.
                $kept = array_flip($database->table($table)->whereIn('id', $locked)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

                return ['deleted' => array_values(array_filter($locked, static fn (int $id): bool => ! isset($kept[$id]))), 'mismatch' => true, 'generations' => $generations, 'undecodable' => $undecodable, 'probe_failed' => false];
            }

            return ['deleted' => $locked, 'mismatch' => false, 'generations' => $generations, 'undecodable' => $undecodable, 'probe_failed' => false];
        });
    }

    /**
     * The probe and the payload read of one locked batch: the uuid and
     * the generation of each locked id above the lowest floor of the
     * aliases' unresolved bulk rows. Without unresolved rows, no payload is
     * read. A failed probe is no proof that none exist.
     *
     * @param  array<int, int>  $locked
     * @return array{generations: array<int, array{0: string, 1: ?string}>, undecodable: int, failed: bool}
     */
    private function unresolvedGenerations(Connection $database, string $table, WatchResult $probe, array $locked): array
    {
        if (! $probe->succeeded()) {
            return ['generations' => [], 'undecodable' => 0, 'failed' => true];
        }

        $floor = $probe->value();

        if (! is_numeric($floor)) {
            return ['generations' => [], 'undecodable' => 0, 'failed' => false];
        }

        $above = array_values(array_filter($locked, static fn (int $id): bool => $id > (int) $floor));
        $generations = [];
        $undecodable = 0;

        foreach (array_chunk($above, self::PAYLOAD_CHUNK) as $chunk) {
            foreach ($database->table($table)->whereIn('id', $chunk)->get(['id', 'payload']) as $job) {
                $payload = is_string($job->payload ?? null) ? $job->payload : null;
                $uuid = PayloadIdentity::uuidOf($payload);

                if ($uuid === null) {
                    $undecodable++;

                    continue;
                }

                /** @var array<string, mixed> $decoded uuidOf() decoded it already */
                $decoded = json_decode((string) $payload, true);
                $generation = $decoded['orreryGen'] ?? null;
                $generations[(int) $job->id] = [$uuid, is_string($generation) && $generation !== '' ? $generation : null];
                unset($decoded);
            }
        }

        return ['generations' => $generations, 'undecodable' => $undecodable, 'failed' => false];
    }

    /**
     * The probe: MIN(bulk_floor_id) of the aliases' unresolved bulk rows, on
     * the mirror session.
     *
     * @param  list<string>  $aliases
     */
    private function probe(array $aliases): WatchResult
    {
        // One statement: MIN over a UNION ALL of two indexed branches, the
        // unresolved rows (the `unresolved` index) and the rows that the
        // bulk resolution marked `unknown`, which left that index (an index
        // that starts with status). An OR of the two would scan every mirror
        // row of the connections.
        return Watcher::instance()->attempt('clear:mirror_probe', static function () use ($aliases): mixed {
            $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
            $unresolved = $mirror->table(JobStore::TABLE)
                ->select('bulk_floor_id')
                ->where('job_id_unresolved', true)
                ->whereNull('job_id')
                ->whereIn('status', self::WAITING_STATES)
                ->whereIn('connection', $aliases);
            $marked = $mirror->table(JobStore::TABLE)
                ->select('bulk_floor_id')
                ->where('status', 'unknown')
                ->where('unknown_reason', BulkResolver::UNRESOLVED_REASON)
                ->whereNull('job_id')
                ->whereIn('connection', $aliases);

            return $mirror->query()->fromSub($unresolved->unionAll($marked), 'bulk_rows')->min('bulk_floor_id');
        });
    }

    /**
     * The bulk rows whose jobs id is unknown: unresolved (`pending` or
     * `unknown`), or marked `unknown` / `bulk_unresolved` by the bulk
     * resolution.
     */
    private static function bulkRowsWithoutJobId(Builder $query): Builder
    {
        return $query
            ->where(static fn ($query) => $query->where('job_id_unresolved', true)->whereIn('status', self::WAITING_STATES))
            ->orWhere(static fn ($query) => $query->where('status', 'unknown')->where('unknown_reason', BulkResolver::UNRESOLVED_REASON));
    }

    /**
     * A SQLite queue connection without WAL takes the probe before its
     * transaction (see the class docblock). A journal mode that cannot be
     * read counts as not WAL: the earlier probe is the safe side for the host.
     */
    private static function probesBeforeTheTransaction(Connection $database): bool
    {
        if ($database->getDriverName() !== 'sqlite') {
            return false;
        }

        try {
            $mode = $database->selectOne('PRAGMA journal_mode');
        } catch (\Throwable) {
            return true;
        }

        $value = is_object($mode) ? (array) $mode : [];

        return strtolower((string) (reset($value) ?: '')) !== 'wal';
    }

    /**
     * Step 3: the mirror rows of the deleted ids on any alias, and the
     * unresolved bulk rows of their uuids and generations, with their
     * dependents.
     *
     * @param  list<string>  $aliases
     * @param  array<int, int>  $jobIds
     * @param  list<array{0: string, 1: ?string}>  $generations  the uuid and generation of each deleted job above the unresolved floor
     * @return array{rows: int, unresolved: int}
     */
    private function deleteMirrorRows(array $aliases, array $jobIds, array $generations): array
    {
        $incidents = $this->incidents;

        return $this->transactions->run($this->sessions->mirror(), static function (Connection $tx) use ($incidents, $aliases, $jobIds, $generations): array {
            $rows = [];

            foreach (array_chunk($jobIds, self::UUID_CHUNK) as $ids) {
                foreach ($tx->table(JobStore::TABLE)
                    ->whereIn('connection', $aliases)
                    ->whereIn('job_id', $ids)
                    ->whereIn('status', self::WAITING_STATES)
                    ->lockForUpdate()
                    ->get(['id', 'uuid']) as $row) {
                    $rows[] = $row;
                }
            }

            $unresolved = self::unresolvedRows($tx, $aliases, $generations);
            /** @var list<\stdClass> $all */
            $all = [...$rows, ...$unresolved];

            if ($all === []) {
                return ['rows' => 0, 'unresolved' => 0];
            }

            foreach (array_chunk(array_map(static fn (\stdClass $row): int => (int) $row->id, $all), self::UUID_CHUNK) as $ids) {
                $tx->table(JobStore::TABLE)
                    ->whereIn('id', $ids)
                    ->whereIn('status', self::WAITING_STATES)
                    ->delete();
            }

            /** @var list<string> $uuids */
            $uuids = array_values(array_unique(array_map(static fn (\stdClass $row): string => (string) $row->uuid, $all)));

            foreach (array_chunk($uuids, self::UUID_CHUNK) as $chunk) {
                foreach (self::MIRROR_TABLES as $dependent) {
                    $tx->table($dependent)->whereIn('job_uuid', $chunk)->delete();
                }

                $incidents->deleteForJobs($tx, $chunk);
            }

            return ['rows' => count($uuids), 'unresolved' => count($unresolved)];
        });
    }

    /**
     * The locked unresolved bulk rows of the decoded uuids, kept only when
     * their cycle_gen equals the payload's orreryGen (both NULL also
     * matches): a row whose retry started a new cycle keeps its uuid.
     *
     * @param  list<string>  $aliases
     * @param  list<array{0: string, 1: ?string}>  $generations
     * @return list<\stdClass>
     */
    private static function unresolvedRows(Connection $tx, array $aliases, array $generations): array
    {
        $wanted = [];

        foreach ($generations as [$uuid, $generation]) {
            $wanted[$uuid] = $generation;
        }

        $rows = [];

        foreach (array_chunk(array_keys($wanted), self::UUID_CHUNK) as $chunk) {
            $found = $tx->table(JobStore::TABLE)
                ->whereIn('uuid', $chunk)
                ->whereNull('job_id')
                ->whereIn('connection', $aliases)
                ->where(static fn ($query) => self::bulkRowsWithoutJobId($query))
                ->lockForUpdate()
                ->get(['id', 'uuid', 'cycle_gen']);

            foreach ($found as $row) {
                $uuid = strtolower((string) $row->uuid);
                $generation = is_string($row->cycle_gen) && $row->cycle_gen !== '' ? $row->cycle_gen : null;

                if (array_key_exists($uuid, $wanted) && $wanted[$uuid] === $generation) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
