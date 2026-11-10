<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFileAccessNotAllowed;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The steps of a retry operation after its claim (retry steps
 * 6–9): the resolution of a claimed operation past its deadline, the
 * finalisation of a published one, and the abort. The operation runner
 * calls step() (it tags itself OperationRunner::HANDLERS); the retry
 * service calls the same steps at once after its own publication, and the
 * operator's resolve (OperationResolver) through step().
 *
 * Every effect checks the fingerprint of the database it acts on first: the
 * resolution `queue_fingerprint`, the delete decision, the restore and the
 * restore read `record_fingerprint`. A change gives no mutation: the
 * operation keeps its state with `target_changed`, a health event and the
 * backoff.
 *
 * Host SQLite files (D17, D18): outside an operator action, a step never
 * writes to a host file, and reads one only when HostFiles::allowsReads()
 * holds. Each step reads first (the publish, delete and restore rows), and
 * opens a write session only when the decision needs a write; then it
 * awaits the operator (`sqlite_host_file`), or `sqlite_reads_not_allowed`
 * when even the read is not allowed. Steps that write only the mirror run.
 *
 * The abort (step 9), each part safe to repeat by any actor:
 * 1. mode before, the delete decision on the failed-record session, in one
 *    transaction: insertOrIgnore (delete, delete_cancelled), then the read of
 *    the delete row. The runner decides only after `record_deadline` by the
 *    failed-record database's clock; the original aborter at once.
 * 2. the restore, once: one transaction that reads the delete row FOR
 *    UPDATE (no `deleted` row: no restore), then the restore row (it exists:
 *    the restore committed; take its id), else inserts the saved columns
 *    without `id` and the restore row with the new id.
 * 3. the mirror transaction (RetryFinalizer::abortIn()).
 */
final class RetryHandler implements OperationHandler
{
    /** Before the restore transaction. */
    public const BEFORE_RESTORE = 'retry.before_restore';

    /** After the restore's COMMIT, before the abort's mirror transaction. */
    public const AFTER_RESTORE = 'retry.after_restore';

    /** The step codes that stay on an undecided operation. */
    public const TARGET_CHANGED = 'target_changed';

    public const RESOLUTION_FAILED = 'resolution_failed';

    public const FINALIZE_FAILED = 'finalize_failed';

    public const DELETE_DECISION_FAILED = 'delete_decision_failed';

    public const RESTORE_FAILED = 'restore_failed';

    public const RESTORE_CONFLICT = 'restore_conflict';

    public const ROW_MOVED = 'row_moved';

    /** The connection of the remote access that is running (for a blocking failure). */
    private ?string $current = null;

    public function __construct(
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly Operations $operations,
        private readonly Fence $fence,
        private readonly Publication $publication,
        private readonly RetryFinalizer $finalizer,
        private readonly HostFiles $files,
    ) {
    }

    public function action(): string
    {
        return Operations::RETRY;
    }

    public function step(stdClass $operation, OperationContext $context): OperationStep
    {
        $step = $this->guarded(fn (): OperationStep => match ((string) $operation->state) {
            Operations::CLAIMED => $this->claimed($operation, $context->mirrorNow),
            Operations::PUBLISHED => $this->finalize($operation, (int) $operation->result_job_id),
            Operations::ABORTING => $this->abortSteps($operation, false, $context->mirrorNow),
            default => OperationStep::decided(),
        });

        if ($step->kind !== OperationStep::AWAITING && $operation->awaiting_since !== null && $this->progressed($operation, $step)) {
            // The step could proceed: the awaiting fields go, the abort's error_code stays.
            $this->operations->clearAwaiting($this->sessions->mirror(), (string) $operation->id);
        }

        return $step;
    }

    /**
     * Whether a step moved the operation on: decided, or its state changed. A
     * failed step (an operator's resolve whose write failed) changed nothing,
     * so the operation keeps its awaiting mark and stays in /api/health; the
     * runner never takes a sqlite_host_file operation.
     */
    private function progressed(stdClass $operation, OperationStep $step): bool
    {
        if ($step->kind === OperationStep::DECIDED) {
            return true;
        }

        $now = $this->operations->find($this->sessions->mirror(), (string) $operation->id);

        return $now !== null && $now->state !== $operation->state;
    }

    /**
     * claimed → published (from the publish fence row), then the
     * finalisation. Returns whether it finalised (false: the finalisation
     * failed, and the runner finishes it).
     */
    public function published(stdClass $operation, int $jobId): bool
    {
        $mirror = $this->idleMirror();
        $this->operations->markPublished($mirror, (string) $operation->id, $jobId);

        return $this->finalize($operation, $jobId)->kind === OperationStep::DECIDED;
    }

    /**
     * The abort of an operation from `claimed`: the claimed → aborting
     * transition with $errorCode, then the abort steps. The original aborter
     * ($immediate) decides a mode-before delete at once. The result is the
     * step: DECIDED when the operation is aborted.
     */
    public function abort(stdClass $operation, string $errorCode, ?string $error, bool $immediate): OperationStep
    {
        return $this->guarded(function () use ($operation, $errorCode, $error, $immediate): OperationStep {
            $mirror = $this->idleMirror();
            $id = (string) $operation->id;

            if (! $this->operations->markAborting($mirror, $id, $errorCode, $error)) {
                $now = $this->operations->find($mirror, $id);

                if ($now === null || $now->state !== Operations::ABORTING) {
                    // Another actor ended it (or it was never claimed): nothing is left to do.
                    return OperationStep::decided();
                }
            }

            return $this->abortSteps($operation, $immediate, DatabaseClock::now($mirror));
        });
    }

    /**
     * The resolution at once, after a COMMIT error of the publication: the
     * fingerprint, then one package transaction on Q's session. The state
     * (PUBLISHED, CANCELLED or UNDECIDED) and the published job id. A failed
     * finalisation after PUBLISHED is reported; the runner finishes it.
     *
     * @return array{state: string, jobId: int|null}
     */
    public function resolveNow(stdClass $operation): array
    {
        try {
            if (! $this->queueTargetHolds($operation)) {
                return ['state' => Publication::UNDECIDED, 'jobId' => null];
            }

            $session = $this->session($this->queueDatabase($operation), true);
            $outcome = $this->publication->resolve($session, $operation);
        } catch (Throwable $error) {
            Watcher::instance()->report('retry:resolve', $error);

            return ['state' => Publication::UNDECIDED, 'jobId' => null];
        }

        if ($outcome['state'] === Publication::PUBLISHED) {
            try {
                $this->published($operation, (int) $outcome['jobId']);
            } catch (Throwable $error) {
                // The publication committed: the runner finalises it.
                Watcher::instance()->report('retry:finalize', $error);
            }
        }

        return ['state' => $outcome['state'], 'jobId' => $outcome['jobId']];
    }

    /**
     * The session of a database connection for a step: outside an operator
     * action, a write on a host SQLite file, or a read of a host file without
     * automatic reads, throws AwaitingOperator.
     */
    public function session(string $connection, bool $write): Connection
    {
        $operator = $this->sessions->operatorContext();

        if ($operator === null && $this->files->isHost($connection)) {
            if ($write) {
                throw new AwaitingOperator(Operations::SQLITE_HOST_FILE, $connection);
            }

            if (! $this->files->allowsReads($connection)) {
                throw new AwaitingOperator(Operations::SQLITE_READS_NOT_ALLOWED, $connection);
            }
        }

        $this->current = $connection;

        return $this->sessions->for($connection, $operator);
    }

    /**
     * The failed-record table of an operation, as the operation names it
     * (never the configuration of this moment).
     */
    public function records(stdClass $operation): SqlFailedRecords
    {
        return new SqlFailedRecords($this->sessions, (string) $operation->record_connection, (string) $operation->record_table);
    }

    /**
     * Whether the failed-record target of an operation is still the one that
     * it names. A change is reported.
     */
    public function recordTargetHolds(stdClass $operation): bool
    {
        $current = $this->records($operation)->target();

        if (is_string($operation->record_fingerprint) && hash_equals($operation->record_fingerprint, $current)) {
            return true;
        }

        $this->targetChanged($operation, 'failed-record');

        return false;
    }

    /**
     * Whether the queue target of an operation is still the one that it
     * names. A change is reported.
     */
    public function queueTargetHolds(stdClass $operation): bool
    {
        if (hash_equals((string) $operation->queue_fingerprint, $this->publication->queueFingerprint((string) $operation->queue_connection))) {
            return true;
        }

        $this->targetChanged($operation, 'queue');

        return false;
    }

    /**
     * Whether both targets of an operation are the ones that it names now,
     * without a report (the health read).
     */
    public function targetsHold(stdClass $operation): bool
    {
        if (! hash_equals((string) $operation->queue_fingerprint, $this->publication->queueFingerprint((string) $operation->queue_connection))) {
            return false;
        }

        if (! is_string($operation->record_fingerprint) || ! is_string($operation->record_connection) || ! is_string($operation->record_table)) {
            return true;
        }

        return hash_equals($operation->record_fingerprint, $this->records($operation)->target());
    }

    public function queueDatabase(stdClass $operation): string
    {
        return $this->sessions->databaseConnectionFor((string) $operation->queue_connection);
    }

    private function claimed(stdClass $operation, float $mirrorNow): OperationStep
    {
        if (! $this->queueTargetHolds($operation)) {
            return OperationStep::undecided(self::TARGET_CHANGED, 'The queue target of the operation changed since its claim.');
        }

        $database = $this->queueDatabase($operation);
        $read = $this->session($database, false);
        $queueNow = DatabaseClock::now($read);
        $deadline = (float) $operation->deadline;

        if ($deadline > $queueNow) {
            return OperationStep::later($mirrorNow + ($deadline - $queueNow));
        }

        $outcome = $this->publication->read($read, $operation);

        if ($outcome['state'] === Publication::UNDECIDED) {
            // No row yet: the cancelling insert is a write.
            $outcome = $this->publication->resolve($this->session($database, true), $operation);
        }

        return match ($outcome['state']) {
            Publication::PUBLISHED => $this->published($operation, (int) $outcome['jobId'])
                ? OperationStep::decided()
                : OperationStep::undecided(self::FINALIZE_FAILED),
            Publication::CANCELLED => $this->abort($operation, Fence::CANCELLED, null, false),
            default => $this->failure($outcome['error'] ?? new RuntimeException('The publish fence row is not decided.'), self::RESOLUTION_FAILED, 'retry:resolve'),
        };
    }

    /**
     * Step 8 in one mirror package transaction. A guard miss reads the
     * operation: done is fine (a worker adopted it); else it ends `done`
     * with row_moved, so no operation stays published.
     */
    private function finalize(stdClass $operation, int $jobId): OperationStep
    {
        $mirror = $this->idleMirror();
        $id = (string) $operation->id;

        try {
            $finalized = $this->transactions->run($mirror, fn (Connection $tx): bool => $this->finalizer->finalizeIn($tx, $id, (string) $operation->job_uuid, $jobId, Clock::now()));
        } catch (Throwable $error) {
            Watcher::instance()->report('retry:finalize', $error);

            return OperationStep::undecided(self::FINALIZE_FAILED, Operations::errorText('retry:finalize', $error));
        }

        if (! $finalized) {
            $now = $this->operations->find($mirror, $id);

            if ($now !== null && in_array($now->state, [Operations::CLAIMED, Operations::PUBLISHED], true)) {
                $this->operations->markDone($mirror, $id, $jobId, self::ROW_MOVED);
                Watcher::instance()->report('retry:row_moved', "The row of retry operation {$id} moved before its finalisation; the operation ends done with row_moved.");
            }
        }

        return OperationStep::decided();
    }

    private function abortSteps(stdClass $operation, bool $immediate, float $mirrorNow): OperationStep
    {
        $restored = null;
        $afterRestore = false;

        if ($operation->mode === Operations::BEFORE) {
            if (! $this->recordTargetHolds($operation)) {
                return OperationStep::undecided(self::TARGET_CHANGED, 'The failed-record target of the operation changed since its claim.');
            }

            $decision = $this->deleteDecision($operation, $immediate, $mirrorNow);

            if ($decision instanceof OperationStep) {
                return $decision;
            }

            if ($decision === Fence::DELETED) {
                $restored = $this->restoreOnce($operation);

                if ($restored instanceof OperationStep) {
                    return $restored;
                }

                $afterRestore = true;
            }
        }

        $id = (string) $operation->id;

        try {
            if ($afterRestore) {
                Hooks::reach(self::AFTER_RESTORE);
            }

            $mirror = $this->idleMirror();
            $this->transactions->run($mirror, fn (Connection $tx): bool => $this->finalizer->abortIn($tx, $id, (string) $operation->job_uuid, $restored, Clock::now()));
        } catch (Throwable $error) {
            if ($operation->awaiting_since !== null) {
                // Every write outside the mirror is done, so no operator is needed any more. The
                // runner takes the operation again (a step that still needs one marks it awaiting again).
                try {
                    $this->operations->clearAwaiting($this->idleMirror(), $id);
                } catch (Throwable) {
                    // The mirror failed again: the mark stays, and a Resolve still finishes the operation.
                }
            }

            throw $error;
        }

        return OperationStep::decided();
    }

    /**
     * Step 9.1: the state of the delete row (deleted or delete_cancelled), or
     * the step when nothing is decided yet.
     */
    private function deleteDecision(stdClass $operation, bool $immediate, float $mirrorNow): string|OperationStep
    {
        $connection = (string) $operation->record_connection;
        $id = (string) $operation->id;
        $read = $this->session($connection, false);
        $row = $this->fence->read($read, $id, Fence::DELETE);

        if ($row !== null) {
            return (string) $row->state;
        }

        if (! $immediate) {
            $recordNow = DatabaseClock::now($read);
            $deadline = (float) $operation->record_deadline;

            if ($deadline >= $recordNow) {
                return OperationStep::later($mirrorNow + ($deadline - $recordNow) + 0.001);
            }
        }

        $write = $this->session($connection, true);

        try {
            $row = $this->transactions->run($write, function (Connection $tx) use ($id, $operation): ?stdClass {
                $this->fence->insertOrIgnore($tx, $id, Fence::DELETE, Fence::DELETE_CANCELLED, (float) $operation->record_deadline);

                return $this->fence->read($tx, $id, Fence::DELETE);
            });
        } catch (Throwable $error) {

            return $this->failure($error, self::DELETE_DECISION_FAILED, 'retry:delete_decision');
        }

        return $row === null ? OperationStep::undecided(self::DELETE_DECISION_FAILED) : (string) $row->state;
    }

    /**
     * Step 9.2: the new record id R' (the restore committed, by this actor
     * or another), null when no restore is authorised, or the step when the
     * restore failed.
     */
    private function restoreOnce(stdClass $operation): int|OperationStep|null
    {
        $connection = (string) $operation->record_connection;
        $id = (string) $operation->id;
        $existing = $this->fence->read($this->session($connection, false), $id, Fence::RESTORE);

        if ($existing !== null && is_numeric($existing->job_id)) {
            return (int) $existing->job_id;
        }

        $full = $this->operations->find($this->idleMirror(), $id);

        if ($full === null || ! is_string($full->saved_record)) {
            return OperationStep::undecided(self::RESTORE_FAILED, 'The operation has no saved record.');
        }

        $write = $this->session($connection, true);
        Hooks::reach(self::BEFORE_RESTORE);
        $records = $this->records($operation);

        try {
            $restored = $this->transactions->run($write, function (Connection $tx) use ($id, $operation, $records, $full): ?int {
                $delete = $this->fence->read($tx, $id, Fence::DELETE, true);

                if ($delete === null || $delete->state !== Fence::DELETED) {
                    // No delete authorises a restore (after the fence retention collected it, a stale restorer finds nothing).
                    return null;
                }

                $restore = $this->fence->read($tx, $id, Fence::RESTORE);

                if ($restore !== null) {
                    return is_numeric($restore->job_id) ? (int) $restore->job_id : null;
                }

                // A unique-uuid conflict here is a foreign record with that uuid: the restore row was read under the lock.
                $newId = $records->restore($tx, SqlFailedRecords::decode((string) $full->saved_record));
                $this->fence->insert($tx, $id, Fence::RESTORE, Fence::RESTORED, (float) $operation->record_deadline, $newId);

                return $newId;
            });
        } catch (Throwable $error) {

            if ($error instanceof UniqueConstraintViolationException) {
                $message = "restore_conflict: the failed record of retry operation {$id} cannot be restored, because another record with its uuid exists. The operation stays aborting.";
                Degradation::error('retry:restore_conflict', $message);
                Watcher::instance()->report('retry:restore_conflict', $message);

                return OperationStep::undecided(self::RESTORE_CONFLICT, Operations::errorText('retry:restore', $message));
            }

            return $this->failure($error, self::RESTORE_FAILED, 'retry:restore');
        }

        return $restored;
    }

    /**
     * Runs a step and turns its errors into the runner's results.
     *
     * @param  callable(): OperationStep  $work
     */
    private function guarded(callable $work): OperationStep
    {
        $this->current = null;

        try {
            return $work();
        } catch (AwaitingOperator $awaiting) {
            return OperationStep::awaiting($awaiting->reason);
        } catch (HostFileAccessNotAllowed) {
            return OperationStep::awaiting(Operations::SQLITE_READS_NOT_ALLOWED);
        } catch (Throwable $error) {
            return $this->failure($error, 'check_failed', 'retry:check');
        }
    }

    private function failure(Throwable $error, string $code, string $operation): OperationStep
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AwaitingOperator) {
                return OperationStep::awaiting($cause->reason);
            }

            if ($cause instanceof HostFileAccessNotAllowed) {
                return OperationStep::awaiting(Operations::SQLITE_READS_NOT_ALLOWED);
            }
        }

        Watcher::instance()->report($operation, $error);
        $text = Operations::errorText($operation, $error);

        if ($this->current !== null && OperationRunner::isBlockingFailure($error)) {
            return OperationStep::blocked($this->current, $code, $text);
        }

        return OperationStep::undecided($code, $text);
    }

    /**
     * The mirror session at transaction level 0 (after a failed publication
     * on a shared session, its transaction is gone with its PDO).
     */
    private function idleMirror(): Connection
    {
        // A mirror failure (a lock timeout too) is never a blocking failure of a target connection.
        $this->current = null;
        $mirror = $this->sessions->mirror();

        if (PackageSession::isPackageSession($mirror->getName()) && PackageTransaction::isOpen($mirror)) {
            $this->sessions->disconnect($mirror);
            $mirror = $this->sessions->mirror();
        }

        return $mirror;
    }

    private function targetChanged(stdClass $operation, string $which): void
    {
        $message = "target_changed: the {$which} target of retry operation {$operation->id} differs from the one it was claimed on. It changes nothing until the configuration points at that target again.";
        Degradation::error('retry:target_changed', $message);
        Watcher::instance()->report('retry:target_changed', $message);
    }
}
