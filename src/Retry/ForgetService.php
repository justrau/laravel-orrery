<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\IncidentCleanup;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\ConnectionName;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\HostFiles;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use LogicException;
use stdClass;
use Throwable;

/**
 * The forget protocol of the operation ledger.
 *
 * forget() is an operator action (PackageSession::operator()), in this order:
 * 1. Refusals before any write: the adapter (FailedRecords::for(): read-only
 *    failers, ids that may be reused); the row's eligibility (a missing row
 *    or another occurrence: occurrence_mismatch; a row that an operation
 *    holds: operation_in_progress, after running the pending step of that
 *    operation (OperationResolver::runPending()); a row that is not failed:
 *    not_failed); for SQL
 *    records a null provider_id (occurrence_unprovable) or a provider_target
 *    that is not the current target (target_changed); the fence table of
 *    the failed-record connection (fence_missing); the mirror's clock and
 *    the failed-record database's clock (mirror_unreachable,
 *    record_unreachable).
 * 2. Prepare: the record by the identity rule (id and uuid). A record that
 *    is gone, and the null failer, give mode none.
 * 3. Claim, one mirror transaction: the guarded hold of the row
 *    (`operation_id IS NULL`, status failed, the occurrence) and the
 *    `claimed` operation (deadline = next_check_at = mirror_now + WINDOW,
 *    record_deadline = record_now + WINDOW). A COMMIT error reads the row
 *    once: the hold continues, another value is claim_failed, a read error
 *    claim_uncertain. A failed read of the committed operation is
 *    claim_uncertain in mode before (no delete ran) and mirror_pending in
 *    mode none.
 * 4. Mode before (an SQL record): the record_fingerprint check, then one
 *    transaction on the failed-record session: the conditional fence insert
 *    (`delete`, `deleted`, before record_deadline by that database's clock;
 *    a duplicate key: cancelled; 0 rows: deadline_passed), the exact
 *    `DELETE … WHERE id = ? AND uuid = ?` (0 or 1 rows), COMMIT. An error
 *    before COMMIT: failed / provider_error and the abort; a COMMIT error:
 *    uncertain, the runner decides.
 * 5. The mirror delete, one transaction: the row held by this operation
 *    (failed, the occurrence) with its dependents, and `→ done`. A guard
 *    miss clears the hold and ends `done` / row_moved. An error:
 *    mirror_pending; the runner finishes it.
 * 6. Abort and recovery (the runner after record_deadline by the
 *    failed-record clock, or the caller after its own error): decide the
 *    delete (insertOrIgnore `delete_cancelled`, then read): `deleted`
 *    finishes step 5; `delete_cancelled` is `claimed → aborting`, then one
 *    mirror transaction releases the row and ends `aborted`. Mode none has
 *    no fence: the runner repeats step 5 in mirror time.
 *
 * As the runner's forget handler (OperationHandler), step() takes step 5 or
 * 6 of a due operation. On a host SQLite file (D17, D18) it writes nothing
 * there outside an operator action: it reads the failed-record clock and
 * the `delete` fence row only when HostFiles::allowsReads() holds (else it
 * awaits `sqlite_reads_not_allowed`), finishes from a `deleted` or
 * `delete_cancelled` row, and awaits `sqlite_host_file` when the decision
 * needs the fence insert. OperationResolver::resolve() runs that step as an
 * operator action.
 *
 * Every hold point is reached outside every error handler.
 */
final class ForgetService implements OperationHandler
{
    public const BEFORE_CLAIM = 'forget.before_claim';

    public const AFTER_CLAIM = 'forget.after_claim';

    public const BEFORE_RECORD_DELETE = 'forget.before_record_delete';

    public const AFTER_RECORD_DELETE = 'forget.after_record_delete';

    /** The delete window of a forget: its deadline and record_deadline, in seconds. */
    public const WINDOW_SECONDS = 60.0;

    /** The per-job tables that the mirror delete empties with the row. */
    public const JOB_TABLES = ['orrery_job_tags', FailedTags::TABLE, 'orrery_job_history', 'orrery_cycle_summaries'];

    public const UNPROVABLE_MESSAGE = "Orrery cannot prove which failed record belongs to this failure; use Laravel's queue:retry or queue:forget.";

    public const TARGET_CHANGED_MESSAGE = 'The failed-job storage changed since this failure.';

    /** The health event of a target change after the claim. */
    public const HEALTH_TARGET_CHANGED = 'forget:target_changed';

    private const ROW_COLUMNS = ['uuid', 'status', 'occurrence_id', 'operation_id', 'provider_id', 'provider_target', 'connection', 'queue'];

    public function __construct(
        private readonly Application $app,
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly Operations $operations,
        private readonly Fence $fence,
        private readonly HostFiles $files,
        private readonly IncidentCleanup $incidents,
        private readonly OperationResolver $resolver,
        private readonly Publication $publication,
    ) {}

    public function action(): string
    {
        return Operations::FORGET;
    }

    /**
     * Forgets one failed occurrence ($source: Operations::SOURCES).
     *
     * $bulkRef names the bulk run that asks for this forget:
     * the operation carries it, and the claim transaction takes one slot of
     * the run's confirmed total (`claimed < confirmed_total`). Without a free
     * slot nothing is claimed: the result is not_confirmed. A hold that loses
     * rolls its slot back with it. Without a run, the operation has no
     * bulk_ref.
     *
     * @throws LogicException inside an open mirror transaction
     */
    public function forget(string $uuid, int $occurrenceId, ?string $actor, string $source = 'dashboard', ?string $bulkRef = null): ForgetResult
    {
        $this->refuseOpenMirrorTransaction('forget()');

        if (! in_array($source, Operations::SOURCES, true)) {
            return new ForgetResult(ForgetResult::REFUSED, 'unknown_source', occurrenceId: $occurrenceId);
        }

        if ($bulkRef !== null && ! Operations::validRef($bulkRef)) {
            throw new \InvalidArgumentException('A bulk run ref is 32 lowercase hexadecimal characters.');
        }

        return $this->sessions->operator(fn (): ForgetResult => $this->forgetInAction(strtolower($uuid), $occurrenceId, SafeText::clean($actor), $source, $bulkRef));
    }

    /**
     * Whether a `done` forget of the occurrence exists (a group forget's
     * already_done).
     */
    public function forgotten(string $uuid, int $occurrenceId): bool
    {
        return $this->sessions->mirror()->table(Operations::TABLE)
            ->where('job_uuid', strtolower($uuid))
            ->where('occurrence_id', $occurrenceId)
            ->where('action', Operations::FORGET)
            ->where('state', Operations::DONE)
            ->exists();
    }

    /**
     * The removal of a failed row whose bound record is gone (reconcile_failed):
     * the forget's mirror delete, guarded by the state that the
     * check read: the occurrence, `status = failed`, `operation_id IS NULL`,
     * the same provider_id, and the same retry_requested_at and absent_since
     * ($expected: column => stored decimal or null). One mirror transaction
     * deletes the row with its dependents. True when the row went.
     *
     * @param  array<string, string|null>  $expected
     */
    public function removeAbsent(string $uuid, int $occurrenceId, int $providerId, array $expected): bool
    {
        $uuid = strtolower($uuid);

        try {
            return $this->transactions->run($this->sessions->mirror(), function (Connection $tx) use ($uuid, $occurrenceId, $providerId, $expected): bool {
                $query = $tx->table(JobStore::TABLE)
                    ->where('uuid', $uuid)
                    ->where('occurrence_id', $occurrenceId)
                    ->where('status', 'failed')
                    ->whereNull('operation_id')
                    ->where('provider_id', $providerId);

                foreach ($expected as $column => $value) {
                    $value === null ? $query->whereNull($column) : $query->where($column, $value);
                }

                if ($query->delete() !== 1) {
                    return false;
                }

                foreach (self::JOB_TABLES as $table) {
                    $tx->table($table)->where('job_uuid', $uuid)->delete();
                }

                $this->incidents->deleteForJobs($tx, [$uuid]);

                return true;
            });
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:absent', $error);

            return false;
        }
    }

    public function step(stdClass $operation, OperationContext $context): OperationStep
    {
        $id = (string) $operation->id;

        if ($operation->state === Operations::ABORTING) {
            // The delete is decided `delete_cancelled`: only the mirror transaction is left.
            return $this->releaseAborted($operation) ? OperationStep::decided() : OperationStep::undecided('mirror_error');
        }

        if ($operation->state !== Operations::CLAIMED) {
            return OperationStep::undecided('unexpected_state');
        }

        if ($operation->record_id === null) {
            // Mode none: no fence and no failed-record clock; the mirror delete in mirror time.
            if ($context->mirrorNow < (float) $operation->deadline) {
                return OperationStep::later((float) $operation->deadline);
            }

            return $this->finishStep($operation);
        }

        $connection = (string) $operation->record_connection;
        $operator = $this->sessions->operatorContext() !== null;
        $host = $this->files->isHost($connection);

        if ($host && ! $operator && ! $this->files->allowsReads($connection)) {
            return OperationStep::awaiting(Operations::SQLITE_READS_NOT_ALLOWED);
        }

        if ($operation->awaiting_reason === Operations::SQLITE_READS_NOT_ALLOWED) {
            // The read-only step can proceed now; the abort's error_code stays.
            try {
                $this->operations->clearAwaiting($this->sessions->mirror(), $id);
            } catch (Throwable $error) {
                return OperationStep::undecided('mirror_error', Operations::errorText('forget:awaiting', $error));
            }
        }

        if (! $this->targetHolds($operation)) {
            $this->targetChanged($id);

            return OperationStep::undecided('target_changed', self::TARGET_CHANGED_MESSAGE);
        }

        try {
            $recordNow = $operator ? DatabaseClock::now($this->sessions->forAction($connection)) : $context->clock($connection);
        } catch (Throwable $error) {
            return self::failure($connection, 'record_unreachable', 'forget:record_clock', $error);
        }

        if ($recordNow < (float) $operation->record_deadline) {
            return OperationStep::later($context->mirrorNow + ((float) $operation->record_deadline - $recordNow));
        }

        try {
            if ($host && ! $operator) {
                // D17: no automatic write here; a decided row can still be read.
                $state = $this->fence->read($this->sessions->for($connection), $id, Fence::DELETE)?->state;

                if ($state === null) {
                    return OperationStep::awaiting(Operations::SQLITE_HOST_FILE);
                }
            } else {
                $state = $this->decideDelete($operation);
            }
        } catch (Throwable $error) {
            return self::failure($connection, 'decide_failed', 'forget:decide', $error);
        }

        if ($state !== Fence::DELETED && $state !== Fence::DELETE_CANCELLED) {
            return OperationStep::undecided('decide_failed');
        }

        if ($operation->awaiting_since !== null) {
            // The decision is in: the rest is mirror work, so the runner may finish it (also after an operator's
            // resolve whose mirror step fails).
            try {
                $this->operations->clearAwaiting($this->sessions->mirror(), $id);
            } catch (Throwable $error) {
                return OperationStep::undecided('mirror_error', Operations::errorText('forget:awaiting', $error));
            }
        }

        if ($state === Fence::DELETED) {
            return $this->finishStep($operation);
        }

        // A runner abort keeps the code of the caller's own error, when one is stored.
        $code = in_array($operation->error_code, ['provider_error', 'record_delete_uncertain'], true) ? (string) $operation->error_code : 'cancelled';

        return $this->abort($operation, $code) ? OperationStep::decided() : OperationStep::undecided('mirror_error');
    }

    private function forgetInAction(string $uuid, int $occurrenceId, ?string $actor, string $source, ?string $bulkRef): ForgetResult
    {
        try {
            $records = FailedRecords::for($this->app, $this->app->make('config'));
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:records', $error);

            return new ForgetResult(ForgetResult::REFUSED, 'record_unreachable');
        }

        if ($records->kind() === FailedRecords::READ_ONLY) {
            return new ForgetResult(ForgetResult::REFUSED, $records->refusal(), message: $records->message());
        }

        $held = null;

        try {
            // Inside the catch: a mirror that cannot be built (a refused or unreachable mirror) is mirror_unreachable, as for a retry.
            $mirror = $this->sessions->mirror();
            $row = $this->readRow($mirror, $uuid);

            if ($row !== null && $row->operation_id !== null) {
                // The operation that holds the row gets its pending step first, as the operator's resolve runs it.
                $held = (string) $row->operation_id;
                $this->resolver->runPending($held);
                $row = $this->readRow($mirror, $uuid);
            }

            if ($held !== null && $row === null && $this->forgotten($uuid, $occurrenceId)) {
                // That step finished a forget of this occurrence: it is forgotten, by that operation.
                return new ForgetResult(ForgetResult::DELETED, null, $held, $occurrenceId);
            }
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:read', $error);

            return new ForgetResult(ForgetResult::REFUSED, 'mirror_unreachable');
        }

        $reason = self::ineligible($row, $occurrenceId);

        if ($reason !== null || $row === null) {
            return new ForgetResult(ForgetResult::NOT_ELIGIBLE, $reason ?? 'occurrence_mismatch', occurrenceId: $occurrenceId);
        }

        $sql = $records instanceof SqlFailedRecords ? $records : null;
        $recordNow = null;

        if ($sql !== null) {
            if ($row->provider_id === null) {
                return new ForgetResult(ForgetResult::REFUSED, 'occurrence_unprovable', message: self::UNPROVABLE_MESSAGE);
            }

            if (! is_string($row->provider_target) || ! hash_equals($sql->target(), $row->provider_target)) {
                return new ForgetResult(ForgetResult::REFUSED, 'target_changed', message: self::TARGET_CHANGED_MESSAGE);
            }

            try {
                if (! $this->fence->available($sql->connection())) {
                    return new ForgetResult(ForgetResult::REFUSED, 'fence_missing');
                }

                $recordNow = DatabaseClock::now($this->sessions->forAction($sql->connection()));
            } catch (Throwable $error) {
                Watcher::instance()->report('forget:record_clock', $error);

                return new ForgetResult(ForgetResult::REFUSED, 'record_unreachable');
            }
        } elseif ($row->provider_id !== null) {
            // The null failer, and a row bound to a failed record: the failed-job storage changed after the failure.
            return new ForgetResult(ForgetResult::REFUSED, 'target_changed', message: self::TARGET_CHANGED_MESSAGE);
        }

        try {
            $mirrorNow = DatabaseClock::now($mirror);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:mirror_clock', $error);

            return new ForgetResult(ForgetResult::REFUSED, 'mirror_unreachable');
        }

        $record = null;
        $recordColumns = [];

        if ($sql !== null) {
            try {
                $record = $sql->read((int) $row->provider_id, $uuid);
            } catch (Throwable $error) {
                Watcher::instance()->report('forget:record_read', $error);

                return new ForgetResult(ForgetResult::REFUSED, 'record_unreachable');
            }

            if ($record !== null) {
                $recordColumns = [
                    'record_connection' => $sql->connection(),
                    'record_table' => $sql->table(),
                    'record_id' => $record->id,
                    'record_uuid' => $record->uuid,
                    'record_fingerprint' => $sql->target(),
                    'record_deadline' => $recordNow + self::WINDOW_SECONDS,
                ];
            }
        }

        try {
            $queueFingerprint = $this->publication->queueFingerprint((string) $row->connection);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:queue_target', $error);

            return new ForgetResult(ForgetResult::REFUSED, 'queue_unreachable');
        }

        $id = Operations::newId();
        $columns = [
            'id' => $id,
            'action' => Operations::FORGET,
            'mode' => $record === null ? Operations::NONE : Operations::BEFORE,
            'job_uuid' => $uuid,
            'occurrence_id' => $occurrenceId,
            'queue_connection' => (string) $row->connection,
            'queue' => (string) $row->queue,
            'queue_fingerprint' => $queueFingerprint,
            'deadline' => $mirrorNow + self::WINDOW_SECONDS,
            'next_check_at' => $mirrorNow + self::WINDOW_SECONDS,
            'actor' => $actor,
            'source' => $source,
        ] + $recordColumns;

        if ($bulkRef !== null) {
            // Only a run's operation names the column: a per-job forget works on a schema before step 8.
            $columns['bulk_ref'] = $bulkRef;
        }

        Hooks::reach(self::BEFORE_CLAIM);
        $claim = $this->claim($mirror, $uuid, $occurrenceId, $row->provider_id === null ? null : (int) $row->provider_id, $columns);

        if ($claim !== null) {
            return $claim;
        }

        Hooks::reach(self::AFTER_CLAIM);

        try {
            $operation = $this->operations->find($mirror, $id);
        } catch (Throwable $error) {
            // The claim committed; the runner goes on from the ledger.
            Watcher::instance()->report('forget:read', $error);

            if ($record !== null) {
                // Mode before: no delete ran, and the runner cancels the forget after its deadline.
                return new ForgetResult(ForgetResult::UNCERTAIN, 'claim_uncertain', $id, $occurrenceId);
            }

            // Mode none: there is no record to delete; the runner finishes the mirror delete.
            return new ForgetResult(ForgetResult::MIRROR_PENDING, 'mirror_error', $id, $occurrenceId);
        }

        if ($operation === null) {
            return new ForgetResult(ForgetResult::UNCERTAIN, 'claim_uncertain', $id, $occurrenceId);
        }

        if ($sql !== null && $operation->record_id !== null) {
            $deleted = $this->deleteRecord($operation, $sql);

            if ($deleted !== null) {
                return $deleted;
            }
        }

        return $this->finishMirror($operation);
    }

    /**
     * The claim transaction. Null when the claim is in (the caller goes on),
     * else the result.
     *
     * @param  array<string, mixed>  $columns
     */
    private function claim(Connection $mirror, string $uuid, int $occurrenceId, ?int $providerId, array $columns): ?ForgetResult
    {
        $id = (string) $columns['id'];
        /** @var bool $committing the closure sets it by reference */
        $committing = false;

        try {
            $this->transactions->run($mirror, function (Connection $tx) use ($uuid, $occurrenceId, $providerId, $columns, $id, &$committing): void {
                // The hold names the provider_id that was read: a record restored meanwhile (a new id) loses.
                $held = $tx->table(JobStore::TABLE)
                    ->where('uuid', $uuid)
                    ->where('status', 'failed')
                    ->where('occurrence_id', $occurrenceId)
                    ->whereNull('operation_id')
                    ->where(static function ($query) use ($providerId): void {
                        $providerId === null ? $query->whereNull('provider_id') : $query->where('provider_id', $providerId);
                    })
                    ->update(['operation_id' => $id]);

                if ($held !== 1) {
                    throw new RolledBack('not_eligible');
                }

                // One slot of the run's confirmed total, in the claim transaction: no slot, no claim.
                if (isset($columns['bulk_ref']) && ! Operations::countBulkClaim($tx, (string) $columns['bulk_ref'], true)) {
                    throw new RolledBack(ForgetResult::NOT_CONFIRMED);
                }

                $this->operations->insertClaimed($tx, $columns);
                $committing = true;
            });
        } catch (Throwable $error) {
            if ($error instanceof RolledBack && $error->reason === ForgetResult::NOT_CONFIRMED) {
                // The run reached its confirmed total (or its audit row is gone): nothing was written.
                return new ForgetResult(ForgetResult::NOT_CONFIRMED, occurrenceId: $occurrenceId);
            }

            if ($error instanceof RolledBack) {
                // The guarded hold lost: the row changed after the read. Nothing was written.
                try {
                    $reason = self::ineligible($this->readRow($mirror, $uuid), $occurrenceId);
                } catch (Throwable) {
                    return new ForgetResult(ForgetResult::REFUSED, 'mirror_unreachable', occurrenceId: $occurrenceId);
                }

                // Still eligible: its record changed (another provider_id).
                return new ForgetResult(ForgetResult::NOT_ELIGIBLE, $reason ?? 'occurrence_mismatch', occurrenceId: $occurrenceId);
            }

            Watcher::instance()->report('forget:claim', $error);

            if (! $committing) {
                return new ForgetResult(ForgetResult::FAILED, 'claim_failed', occurrenceId: $occurrenceId);
            }

            // The COMMIT failed: the server may have committed. Read the row once.
            try {
                $held = $this->readRow($mirror, $uuid)?->operation_id;
            } catch (Throwable) {
                return new ForgetResult(ForgetResult::UNCERTAIN, 'claim_uncertain', $id, $occurrenceId);
            }

            return $held === $id ? null : new ForgetResult(ForgetResult::FAILED, 'claim_failed', occurrenceId: $occurrenceId);
        }

        return null;
    }

    /**
     * Step 4, the fenced exact delete. Null when the record is deleted (or was
     * already gone) and the mirror delete follows, else the result.
     */
    private function deleteRecord(stdClass $operation, SqlFailedRecords $records): ?ForgetResult
    {
        $id = (string) $operation->id;
        $occurrence = (int) $operation->occurrence_id;

        if (! $this->targetHolds($operation)) {
            $this->targetChanged($id);
            $this->note($operation, 'target_changed', self::TARGET_CHANGED_MESSAGE);

            return new ForgetResult(ForgetResult::UNCERTAIN, 'target_changed', $id, $occurrence, self::TARGET_CHANGED_MESSAGE);
        }

        Hooks::reach(self::BEFORE_RECORD_DELETE);
        $session = null;
        /** @var bool $committing the closure sets it by reference */
        $committing = false;

        try {
            $session = $this->sessions->forAction($records->connection());
            $this->transactions->run($session, function (Connection $tx) use ($operation, $records, $id, &$committing): void {
                $fence = $this->fence->insertBefore($tx, $id, Fence::DELETE, Fence::DELETED, (float) $operation->record_deadline);

                if ($fence !== Fence::INSERTED) {
                    throw new RolledBack($fence);
                }

                // 0 rows: the record went meanwhile (a native retry or forget); the forget still ends.
                $records->delete($tx, (int) $operation->record_id, (string) $operation->record_uuid);
                $committing = true;
            });
        } catch (RolledBack $lost) {
            $code = $lost->reason === Fence::DUPLICATE ? 'cancelled' : 'deadline_passed';
            $this->abortAfterError($operation, $code);

            return new ForgetResult(ForgetResult::FAILED, $code, $id, $occurrence);
        } catch (Throwable $error) {
            // PackageTransaction rolled the transaction back (and dropped the PDO only when that failed). No
            // disconnect here: on a shared session (MySQL, same mode) it would also close the mirror's.
            Watcher::instance()->report('forget:record_delete', $error);

            if (! $committing) {
                $this->note($operation, 'provider_error', Operations::errorText('forget:record_delete', $error));
                $this->abortAfterError($operation, 'provider_error');

                return new ForgetResult(ForgetResult::FAILED, 'provider_error', $id, $occurrence);
            }

            $this->note($operation, 'record_delete_uncertain', Operations::errorText('forget:record_delete', $error));

            return new ForgetResult(ForgetResult::UNCERTAIN, 'record_delete_uncertain', $id, $occurrence);
        }

        Hooks::reach(self::AFTER_RECORD_DELETE);

        return null;
    }

    /**
     * Step 5 as the caller's result.
     */
    private function finishMirror(stdClass $operation): ForgetResult
    {
        $id = (string) $operation->id;
        $occurrence = (int) $operation->occurrence_id;

        try {
            $outcome = $this->mirrorDelete($operation);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:mirror', $error);

            return new ForgetResult(ForgetResult::MIRROR_PENDING, 'mirror_error', $id, $occurrence);
        }

        return new ForgetResult(ForgetResult::DELETED, $outcome === 'row_moved' ? 'row_moved' : null, $id, $occurrence);
    }

    /**
     * Step 5 as the runner's step.
     */
    private function finishStep(stdClass $operation): OperationStep
    {
        try {
            $this->mirrorDelete($operation);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:mirror', $error);

            return OperationStep::undecided('mirror_error', Operations::errorText('forget:mirror', $error));
        }

        return OperationStep::decided();
    }

    /**
     * Step 5: one mirror transaction deletes the row that this operation
     * holds (failed, the occurrence) with its dependents and ends the
     * operation `done`. A guard miss clears the hold and ends `done` /
     * row_moved. Returns deleted, row_moved or finished (another actor ended
     * the operation first).
     */
    private function mirrorDelete(stdClass $operation): string
    {
        $id = (string) $operation->id;
        $uuid = (string) $operation->job_uuid;
        $occurrence = (int) $operation->occurrence_id;

        try {
            return $this->transactions->run($this->sessions->mirror(), function (Connection $tx) use ($id, $uuid, $occurrence): string {
                $guard = static fn () => $tx->table(JobStore::TABLE)->where('uuid', $uuid)->where('operation_id', $id)->where('status', 'failed')->where('occurrence_id', $occurrence);

                if ($guard()->lockForUpdate()->first(['id']) === null) {
                    $tx->table(JobStore::TABLE)->where('uuid', $uuid)->where('operation_id', $id)->update(['operation_id' => null]);

                    return $this->operations->markDone($tx, $id, null, 'row_moved') ? 'row_moved' : 'finished';
                }

                $guard()->delete();

                foreach (self::JOB_TABLES as $table) {
                    $tx->table($table)->where('job_uuid', $uuid)->delete();
                }

                $this->incidents->deleteForJobs($tx, [$uuid]);

                if (! $this->operations->markDone($tx, $id)) {
                    throw new RolledBack('not_claimed');
                }

                return 'deleted';
            });
        } catch (RolledBack) {
            return 'finished';
        }
    }

    /**
     * The decision of the delete, in one transaction on the failed-record
     * session: insertOrIgnore `delete_cancelled`, then read the row. A
     * deleter that holds the key makes this wait up to the lock timeout.
     */
    private function decideDelete(stdClass $operation): ?string
    {
        $id = (string) $operation->id;
        $session = $this->sessions->forAction((string) $operation->record_connection);
        $decided = $this->fence->read($session, $id, Fence::DELETE)?->state;

        if (is_string($decided)) {
            // A decided row never changes: no write transaction is needed.
            return $decided;
        }

        return $this->transactions->run($session, function (Connection $tx) use ($id, $operation): ?string {
            $this->fence->insertOrIgnore($tx, $id, Fence::DELETE, Fence::DELETE_CANCELLED, (float) $operation->record_deadline);
            $state = $this->fence->read($tx, $id, Fence::DELETE, true)?->state;

            return is_string($state) ? $state : null;
        });
    }

    /**
     * The caller's abort after its own error or a lost fence: decide the
     * delete at once (its own delete is over), then finish from the
     * decision. A failure leaves the operation claimed for the runner.
     */
    private function abortAfterError(stdClass $operation, string $code): void
    {
        try {
            $state = $this->decideDelete($operation);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:decide', $error);

            return;
        }

        if ($state === Fence::DELETED) {
            // Another actor of this operation deleted the record: the forget happened.
            try {
                $this->mirrorDelete($operation);
            } catch (Throwable $error) {
                Watcher::instance()->report('forget:mirror', $error);
            }

            return;
        }

        if ($state === Fence::DELETE_CANCELLED) {
            $this->abort($operation, $code);
        }
    }

    /**
     * `claimed → aborting`, then the mirror transaction. A loser that reads
     * `aborting` continues; one that reads a terminal state stops. True when
     * the operation is terminal afterwards.
     */
    private function abort(stdClass $operation, string $code): bool
    {
        $mirror = $this->sessions->mirror();
        $id = (string) $operation->id;

        try {
            if (! $this->operations->markAborting($mirror, $id, $code)) {
                $current = $this->operations->find($mirror, $id);

                if ($current === null || $current->state !== Operations::ABORTING) {
                    // Another actor ended the operation.
                    return $current !== null && in_array($current->state, Operations::TERMINAL, true);
                }
            }
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:abort', $error);

            return false;
        }

        return $this->releaseAborted($operation);
    }

    /**
     * The abort's mirror transaction: the row back to free (`operation_id =
     * NULL`), and `aborting → aborted`.
     */
    private function releaseAborted(stdClass $operation): bool
    {
        $id = (string) $operation->id;

        try {
            $this->transactions->run($this->sessions->mirror(), function (Connection $tx) use ($id, $operation): void {
                $tx->table(JobStore::TABLE)->where('uuid', (string) $operation->job_uuid)->where('operation_id', $id)->update(['operation_id' => null]);
                $this->operations->markAborted($tx, $id);
            });
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:abort', $error);

            return false;
        }

        return true;
    }

    private function targetHolds(stdClass $operation): bool
    {
        $connection = $operation->record_connection;
        $table = $operation->record_table;

        if (! is_string($connection) || ! is_string($table) || ! is_string($operation->record_fingerprint)) {
            return false;
        }

        try {
            $stored = SqlFailedRecords::targetOf($this->sessions->writerConfig($connection), $table);
            $configured = $this->configuredTarget();
        } catch (Throwable) {
            // A connection that is no longer configured is another target.
            return false;
        }

        // Both the stored descriptor and queue.failed now must name the claimed target.
        return hash_equals($operation->record_fingerprint, $stored) && $configured !== null && hash_equals($operation->record_fingerprint, $configured);
    }

    /**
     * The target of the failed-record table that queue.failed names now, or
     * null when it names none.
     */
    private function configuredTarget(): ?string
    {
        $config = $this->app->make('config');
        $failed = (array) $config->get('queue.failed', []);
        $table = $failed['table'] ?? null;

        if (! is_string($table) || $table === '') {
            return null;
        }

        $database = is_string($failed['database'] ?? null) && $failed['database'] !== '' ? $failed['database'] : (string) $config->get('database.default');

        return SqlFailedRecords::targetOf($this->sessions->writerConfig(ConnectionName::base($this->app->make(DatabaseManager::class), $database)), $table);
    }

    private function targetChanged(string $operationId): void
    {
        Degradation::error(self::HEALTH_TARGET_CHANGED, "target_changed: the failed-job storage of operation {$operationId} changed since its claim; it waits until the configuration is restored.");
        Watcher::instance()->report(self::HEALTH_TARGET_CHANGED, 'target_changed: the failed-job storage of a forget changed since its claim.');
    }

    /**
     * Keeps the code and text of a caller's error on the claimed operation;
     * next_check_at does not change.
     */
    private function note(stdClass $operation, string $code, string $text): void
    {
        try {
            $this->operations->reschedule($this->sessions->mirror(), (string) $operation->id, (float) $operation->next_check_at, false, $code, $text);
        } catch (Throwable $error) {
            Watcher::instance()->report('forget:note', $error);
        }
    }

    /**
     * The step of a failed read or write on a database connection: a
     * blocking failure defers the connection.
     */
    private static function failure(string $connection, string $code, string $operation, Throwable $error): OperationStep
    {
        $text = Operations::errorText($operation, $error);

        return OperationRunner::isBlockingFailure($error) ? OperationStep::blocked($connection, $code, $text) : OperationStep::undecided($code, $text);
    }

    private function readRow(Connection $mirror, string $uuid): ?stdClass
    {
        $row = $mirror->table(JobStore::TABLE)->where('uuid', $uuid)->first(self::ROW_COLUMNS);

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Why a row cannot be forgotten as this occurrence, or null.
     */
    private static function ineligible(?stdClass $row, int $occurrenceId): ?string
    {
        return match (true) {
            $row === null => 'occurrence_mismatch',
            $row->operation_id !== null => 'operation_in_progress',
            $row->status !== 'failed' => 'not_failed',
            (int) $row->occurrence_id !== $occurrenceId => 'occurrence_mismatch',
            default => null,
        };
    }

    private function refuseOpenMirrorTransaction(string $call): void
    {
        try {
            $open = $this->transactions->active($this->sessions->mirror());
        } catch (Throwable) {
            // A mirror that cannot be built holds no open transaction: the forget refuses later (mirror_unreachable).
            return;
        }

        if ($open) {
            throw new LogicException("orrery: ForgetService::{$call} cannot run inside an open mirror transaction: its record delete commits at once.");
        }
    }
}
