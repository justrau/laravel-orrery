<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueManager;
use Justrau\Orrery\Lifecycle\CycleSummaries;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Observation\PayloadIdentity;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Runtime\WatchResult;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * orrery:clear: deletes only the waiting jobs of a
 * database queue, in batches (no fence row: each batch is one transaction
 * on the queue connection).
 *
 * 1. It selects up to 900 unreserved ids of the queue on the observer
 *    session (a writer clone, so a read replica never answers).
 * 2. It deletes those ids through the queue connection, in one transaction
 *    that first locks them with `reserved_at IS NULL` re-checked. So a job
 *    that a worker reserved after step 1 stays, and the deleted ids are
 *    exactly the locked ones.
 * 3. One mirror transaction deletes the mirror rows of the deleted ids
 *    (`pending` or `unknown`, on the same queue connection), with their
 *    tags, failed tags, history, cycle summaries and stuck incidents, as
 *    retention does. Provider cleanup rows and claim receipts stay.
 *
 * Unresolved bulk rows have job_id NULL, so step 3 cannot
 * match them by id. Per batch:
 * - inside step 2's transaction, after the lock and before the delete, one
 *   mirror probe reads MIN(bulk_floor_id), as one statement over a UNION
 *   ALL of two branches: the unresolved rows (`job_id_unresolved`, job_id
 *   NULL, `pending` or `unknown`; the `unresolved` index) and the rows
 *   that the bulk resolution marked `unknown` / `bulk_unresolved` (job_id
 *   NULL; an index that starts with status), each of the
 *   clear's connection. Without such rows (the normal case), the batch
 *   reads no payload. On a SQLite queue connection without WAL, the probe
 *   runs just before step 2's transaction instead: the mirror session is a
 *   second connection, and its read inside the transaction would hold a
 *   shared lock with the queue's, so a worker's commit in that window would
 *   wait for the busy timeout (SQLite has no row locks anyway);
 * - else it reads `id` and `payload` of the locked ids above that floor, in
 *   chunks of 100, and decodes the uuid (lower-cased) and `orreryGen`;
 * - the mirror transaction locks the unresolved rows of those uuids (the
 *   deleted jobs only, at most 900 uuids per statement), keeps those whose
 *   cycle_gen equals the payload's orreryGen (both NULL also matches), and
 *   deletes them with their dependents, as in step 3;
 * - a payload that cannot be decoded leaves its row, and the command prints
 *   a warning with the count (exit 0);
 * - a probe that fails keeps the batch: the transaction deletes nothing,
 *   the command stops and exits 1, so a later clear can still
 *   match the unresolved rows through the payloads of those jobs.
 *
 * Known limit (deferred with the bulk capture): the bulk capture writes its
 * mirror rows after the parent's insert has committed. If a bulk push
 * commits its jobs and the clear deletes them before their mirror rows
 * exist, those rows stay, and the output cannot count them. As for the other
 * rows, the match is on the clear's own connection name, not on the other
 * aliases of the same jobs table. On SQLite without WAL, the probe runs
 * before the batch's transaction, so a bulk push whose jobs and mirror rows
 * both commit between the probe and the lock is not matched when the probe
 * found no unresolved row: its mirror rows stay, as above.
 *
 * Unlike Laravel's queue:clear, it never deletes reserved jobs. A mirror
 * error after step 2 leaves those mirror rows to reconciliation,
 * which marks them `unknown`, and the command then exits 1: the operator
 * asked for the mirror rows too. An error of the queue connection is not
 * caught, so it also exits 1.
 */
#[AsCommand(name: 'orrery:clear')]
class ClearCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The ids per batch: with the other bindings of a statement it
     * stays below 999 variables, the limit of SQLite before 3.32.
     */
    public const BATCH = 900;

    /** The payloads per read of the unresolved case. */
    public const PAYLOAD_CHUNK = 100;

    /** The uuids or ids per mirror statement, below 999 variables with the others. */
    public const UUID_CHUNK = 900;

    /** The attempts of one batch transaction when SQLite answers SQLITE_BUSY (deleteWaitingRetried()). */
    public const BUSY_ATTEMPTS = 3;

    private const MIRROR_TABLES = ['orrery_job_tags', FailedTags::TABLE, 'orrery_job_history', CycleSummaries::TABLE];

    private const WAITING_STATES = ['pending', 'unknown'];

    /** @var string */
    protected $signature = 'orrery:clear
                            {connection? : The queue connection to clear (defaults to queue.default)}
                            {--queue= : The queue name (defaults to the connection\'s configured queue)}
                            {--force : Force the operation to run when in production}';

    /** @var string */
    protected $description = 'Delete the waiting (unreserved) jobs of a database queue and their mirror rows. Unlike queue:clear, reserved jobs stay.';

    /** The batch size of a test (useBatchForTesting()); the command is a singleton. */
    private ?int $batchForTesting = null;


    public function handle(QueueManager $manager, PackageSession $sessions, PackageTransaction $transactions, IncidentCleanup $incidents): int
    {
        // An operator action (D17): its writes may reach a host SQLite file.
        return PackageSession::asOperator(fn (): int => $this->handleInAction($manager, $sessions, $transactions, $incidents));
    }

    private function handleInAction(QueueManager $manager, PackageSession $sessions, PackageTransaction $transactions, IncidentCleanup $incidents): int
    {
        foreach (['connection' => $this->argument('connection'), '--queue' => $this->option('queue')] as $name => $value) {
            if ($value === '') {
                // An empty value is refused, never read as the default.
                $this->components->error("{$name} is empty: give a name, or leave it out.");

                return self::FAILURE;
            }
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $connection = (string) ($this->argument('connection')
            ?: $this->laravel->make('config')->get('queue.default'));

        if ($this->laravel->make('config')->get("queue.connections.{$connection}.driver") !== 'database') {
            $this->components->error("The queue connection [{$connection}] does not use the database driver.");

            return self::FAILURE;
        }

        $queueName = (string) ($this->option('queue')
            ?: $this->laravel->make('config')->get("queue.connections.{$connection}.queue", 'default'));

        $queue = $manager->connection($connection);

        if (! $queue instanceof DatabaseQueue) {
            $this->components->error("The queue connection [{$connection}] is not a database queue.");

            return self::FAILURE;
        }

        $table = (string) ($this->laravel->make('config')->get("queue.connections.{$connection}.table") ?: 'jobs');
        $observer = $sessions->forAction($sessions->databaseConnectionFor($connection));
        $database = $queue->getDatabase();
        $batch = $this->batchForTesting ?? self::BATCH;
        $after = 0;
        $jobs = 0;
        $mirrorRows = 0;
        $unresolvedRows = 0;
        $undecodable = 0;
        $mirrorFailed = false;
        $probeFailed = false;
        $countMismatches = 0;
        $probeFirst = self::probesBeforeTheTransaction($database);

        while (true) {
            $ids = $observer->table($table)
                ->where('queue', $queueName)
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
            $early = $probeFirst ? $this->probe($connection) : null;
            $result = $this->deleteWaitingRetried($database, $table, $queueName, $connection, $ids, $early);
            $deleted = $result['deleted'];
            $countMismatches += $result['mismatch'] ? 1 : 0;
            $undecodable += $result['undecodable'];
            $probeFailed = $probeFailed || $result['probe_failed'];
            $jobs += count($deleted);

            if ($result['probe_failed']) {
                // Nothing of this batch was deleted: stop here, so the jobs stay for the next clear.
                break;
            }

            if ($deleted !== []) {
                // Only the generations of the jobs that are gone.
                $generations = array_intersect_key($result['generations'], array_flip($deleted));
                $removed = Watcher::instance()->run('clear:mirror', fn (): array => $this->deleteMirrorRows($transactions, $incidents, $connection, $deleted, array_values($generations)), null);

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

        $this->components->info(sprintf(
            'Cleared %d waiting job(s) and %d mirror row(s), %d of them unresolved bulk rows, for [%s][%s].',
            $jobs,
            $mirrorRows,
            $unresolvedRows,
            $connection,
            $queueName,
        ));
        $this->components->info('Reserved jobs were kept: unlike queue:clear, orrery:clear deletes only jobs that no worker has reserved.');

        if ($countMismatches > 0) {
            $this->components->warn(sprintf('In %d batch(es), the queue deleted another number of jobs than it locked. Only the jobs that are gone were counted, and only their mirror rows were deleted.', $countMismatches));
        }

        if ($undecodable > 0) {
            $this->components->warn(sprintf('%d payload(s) could not be decoded, so their unresolved bulk mirror rows, if any, were kept.', $undecodable));
        }

        if ($mirrorFailed) {
            // The operator asked for the mirror rows too, so a failed mirror delete is a failed
            // command, although the jobs are gone: a script must not see success.
            $this->components->error('Some mirror rows could not be deleted. Reconciliation marks them unknown later.');
        }

        if ($probeFailed) {
            // The batch whose probe failed kept its jobs: a later clear can check their unresolved bulk rows.
            $this->components->error('The unresolved bulk mirror rows could not be checked, so Orrery stopped before it deleted the next batch of waiting jobs. Those jobs are still queued. Run orrery:clear again.');
        }

        if ($mirrorFailed || $probeFailed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  int|null  $size  the batch size; null restores 900
     */
    public static function useBatchForTesting(?int $size): void
    {
        Container::getInstance()->make(self::class)->batchForTesting = $size;
    }

    /**
     * deleteWaiting(), again at most BUSY_ATTEMPTS times in all when SQLite
     * answers SQLITE_BUSY: in WAL, a deferred transaction that
     * read first cannot write after another process committed, and SQLite
     * then fails at once instead of waiting. The transaction rolled back,
     * so the batch runs again from its lock. Only the driver's result code
     * counts, never a message (which can hold a bound value).
     *
     * @param  array<int, int>  $ids
     * @return array{deleted: array<int, int>, mismatch: bool, generations: array<int, array{0: string, 1: ?string}>, undecodable: int, probe_failed: bool}
     */
    private function deleteWaitingRetried(Connection $database, string $table, string $queueName, string $connection, array $ids, ?WatchResult $early): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->deleteWaiting($database, $table, $queueName, $connection, $ids, $early);
            } catch (\Throwable $error) {
                if ($attempt >= self::BUSY_ATTEMPTS || $database->getDriverName() !== 'sqlite' || ! self::isSqliteBusy($error)) {
                    throw $error;
                }
            }
        }
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
     * @param  array<int, int>  $ids
     * @return array{deleted: array<int, int>, mismatch: bool, generations: array<int, array{0: string, 1: ?string}>, undecodable: int, probe_failed: bool} the deleted ids, whether the delete count differed from the locked ids, the decoded uuid and generation per locked id, the payloads that could not be decoded, and whether the probe failed
     */
    private function deleteWaiting(Connection $database, string $table, string $queueName, string $connection, array $ids, ?WatchResult $early): array
    {
        return $database->transaction(function (Connection $database) use ($table, $queueName, $connection, $ids, $early): array {
            $locked = $database->table($table)
                ->whereIn('id', $ids)
                ->where('queue', $queueName)
                ->whereNull('reserved_at')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($locked === []) {
                return ['deleted' => [], 'mismatch' => false, 'generations' => [], 'undecodable' => 0, 'probe_failed' => false];
            }

            ['generations' => $generations, 'undecodable' => $undecodable, 'failed' => $probeFailed] = $this->unresolvedGenerations($database, $table, $early ?? $this->probe($connection), $locked);

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

                return ['deleted' => array_values(array_filter($locked, static fn (int $id): bool => ! isset($kept[$id]))), 'mismatch' => true, 'generations' => $generations, 'undecodable' => $undecodable, 'probe_failed' => $probeFailed];
            }

            return ['deleted' => $locked, 'mismatch' => false, 'generations' => $generations, 'undecodable' => $undecodable, 'probe_failed' => $probeFailed];
        });
    }

    /**
     * The probe and the payload read of one locked batch: the uuid and
     * the generation of each locked id above the lowest floor of the
     * connection's unresolved bulk rows. Without unresolved rows, no
     * payload is read. A failed probe is no proof that none exist, so it is
     * reported (and the command fails, as for a failed mirror delete).
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
            }
        }

        return ['generations' => $generations, 'undecodable' => $undecodable, 'failed' => false];
    }

    /**
     * The probe: MIN(bulk_floor_id) of the connection's unresolved bulk
     * rows, on the mirror session.
     */
    private function probe(string $connection): WatchResult
    {
        // One statement: MIN over a UNION ALL of two indexed branches, the
        // unresolved rows (the `unresolved` index) and the rows that the
        // bulk resolution marked `unknown`, which left that
        // index (an index that starts with status). An OR of the two would
        // scan every mirror row of the connection.
        return Watcher::instance()->attempt('clear:mirror_probe', static function () use ($connection): mixed {
            $mirror = Container::getInstance()->make(PackageSession::class)->mirror();
            $unresolved = $mirror->table(JobStore::TABLE)
                ->select('bulk_floor_id')
                ->where('job_id_unresolved', true)
                ->whereNull('job_id')
                ->whereIn('status', self::WAITING_STATES)
                ->where('connection', $connection);
            $marked = $mirror->table(JobStore::TABLE)
                ->select('bulk_floor_id')
                ->where('status', 'unknown')
                ->where('unknown_reason', BulkResolver::UNRESOLVED_REASON)
                ->whereNull('job_id')
                ->where('connection', $connection);

            return $mirror->query()->fromSub($unresolved->unionAll($marked), 'bulk_rows')->min('bulk_floor_id');
        });
    }

    /**
     * The bulk rows whose jobs id is unknown: unresolved (`pending` or
     * `unknown`), or marked `unknown` / `bulk_unresolved` by the bulk
     * resolution.
     */
    private static function bulkRowsWithoutJobId(\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder
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
     * Step 3: the mirror rows of the deleted ids, and the unresolved bulk
     * rows of their uuids and generations, with their dependents.
     *
     * @param  array<int, int>  $jobIds
     * @param  list<array{0: string, 1: ?string}>  $generations  the uuid and generation of each deleted job above the unresolved floor
     * @return array{rows: int, unresolved: int}
     */
    private function deleteMirrorRows(PackageTransaction $transactions, IncidentCleanup $incidents, string $connection, array $jobIds, array $generations): array
    {
        return $transactions->run(Container::getInstance()->make(PackageSession::class)->mirror(), static function (Connection $tx) use ($incidents, $connection, $jobIds, $generations): array {
            $rows = $tx->table(JobStore::TABLE)
                ->where('connection', $connection)
                ->whereIn('job_id', $jobIds)
                ->whereIn('status', self::WAITING_STATES)
                ->lockForUpdate()
                ->get(['id', 'uuid'])
                ->all();
            $unresolved = self::unresolvedRows($tx, $connection, $generations);
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
     * @param  list<array{0: string, 1: ?string}>  $generations
     * @return list<\stdClass>
     */
    private static function unresolvedRows(Connection $tx, string $connection, array $generations): array
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
                ->where('connection', $connection)
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
