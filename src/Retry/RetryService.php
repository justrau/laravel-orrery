<?php

namespace Justrau\Orrery\Retry;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Justrau\Orrery\Failed\FailedRecords;
use Justrau\Orrery\Failed\SqlFailedRecords;
use Justrau\Orrery\Lifecycle\FailedTags;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\SafeText;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Storage\DatabaseClock;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Storage\PackageTransaction;
use LogicException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The retry of one failed occurrence on the operation ledger.
 *
 * retry() is an operator action (D17): its writes may reach a host SQLite
 * file, each in one short transaction. It refuses with a LogicException
 * inside an open mirror transaction. The steps:
 * 1. refusals before any write: the adapter (provider_read_only,
 *    ids_may_be_reused); the row (not_failed, occurrence_mismatch,
 *    operation_in_progress: an operation that holds the row gets its pending
 *    step first, as the operator's resolve runs it); under SqlFailedRecords a
 *    null provider_id (occurrence_unprovable) or another provider_target
 *    (target_changed); the fence tables (fence_missing); the clocks of Q, the
 *    mirror and, in mode before, the failed-record database
 *    (queue_unreachable, mirror_unreachable, record_unreachable);
 * 2. the record by the identity rule (record_missing; the null failer uses
 *    the row's payload) and the payload (RetryPayload; prepare_failed);
 * 3. the claim, in one mirror transaction: the guarded row update, the
 *    `claimed` operation, the row's failed tags deleted;
 * 4. mode before: the fenced exact delete on the failed-record session;
 * 5. the publication on Q's session (Publication);
 * 6. after COMMIT: claimed → published, then the finalisation; accepted.
 * A COMMIT error of the publication runs one resolution at once; an error
 * before it, a cancelled fence row, a passed deadline or a missing record
 * aborts the operation (RetryHandler::abort()). Every error that leaves the
 * service is a code; its text goes through SafeError into the ledger.
 *
 * The hold points: retry.before_claim, retry.after_claim,
 * retry.before_record_delete, retry.after_record_delete, and the
 * publication's retry.before_fence_insert, retry.before_commit and
 * retry.after_commit; retry.before_restore in the abort.
 */
final class RetryService
{
    public const BEFORE_CLAIM = 'retry.before_claim';

    public const AFTER_CLAIM = 'retry.after_claim';

    public const BEFORE_RECORD_DELETE = 'retry.before_record_delete';

    public const AFTER_RECORD_DELETE = 'retry.after_record_delete';

    public const AFTER_COMMIT = 'retry.after_commit';

    public const OCCURRENCE_UNPROVABLE = "Orrery cannot prove which failed record belongs to this failure; use Laravel's queue:retry or queue:forget.";

    public const TARGET_CHANGED = 'The failed-job storage changed since this failure.';

    /** The text of an abort whose next step waits for an operator (awaiting_operator). */
    public const AWAITING_OPERATOR = 'The retry stopped, and its next step needs an operator on the SQLite file: resolve it on the job page, or with php artisan orrery:resolve.';

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly PackageSession $sessions,
        private readonly PackageTransaction $transactions,
        private readonly Operations $operations,
        private readonly Fence $fence,
        private readonly Publication $publication,
        private readonly RetryPayload $payloads,
        private readonly RetryHandler $handler,
        private readonly OperationResolver $resolver,
    ) {
    }

    /**
     * $bulkRef names the bulk run that asks for this retry: the
     * operation carries it, and the claim transaction adds one to the run's
     * `claimed` count. Without a run, the operation has no bulk_ref (so a
     * schema before step 8 still takes the insert).
     */
    public function retry(string $uuid, int $occurrenceId, string $source, ?string $actor = null, ?string $bulkRef = null): RetryResult
    {
        if (! in_array($source, Operations::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown retry source {$source}.");
        }

        if ($bulkRef !== null && ! Operations::validRef($bulkRef)) {
            throw new InvalidArgumentException('A bulk run ref is 32 lowercase hexadecimal characters.');
        }

        $this->refuseOpenMirrorTransaction();

        return $this->sessions->operator(fn (): RetryResult => $this->retryInAction(strtolower($uuid), $occurrenceId, $source, $actor, $bulkRef));
    }

    private function retryInAction(string $uuid, int $occurrenceId, string $source, ?string $actor, ?string $bulkRef): RetryResult
    {
        // 1. The refusals before any write.
        try {
            $records = FailedRecords::for($this->app, $this->config);
        } catch (Throwable $error) {
            return $this->refused('record_unreachable', $error);
        }

        if ($records->kind() === FailedRecords::READ_ONLY) {
            return new RetryResult(RetryResult::REFUSED, $records->refusal(), message: $records->message());
        }

        $row = $this->eligibleRow($uuid, $occurrenceId);

        if ($row instanceof RetryResult) {
            return $row;
        }

        $sql = $records instanceof SqlFailedRecords ? $records : null;

        if ($sql !== null) {
            if ($row->provider_id === null) {
                return new RetryResult(RetryResult::REFUSED, 'occurrence_unprovable', message: self::OCCURRENCE_UNPROVABLE);
            }

            if (! is_string($row->provider_target) || ! hash_equals($row->provider_target, $sql->target())) {
                return new RetryResult(RetryResult::REFUSED, 'target_changed', message: self::TARGET_CHANGED);
            }
        } elseif ($row->provider_id !== null) {
            // The null failer, and a row bound to a failed record: the failed-job storage changed after the failure.
            return new RetryResult(RetryResult::REFUSED, 'target_changed', message: self::TARGET_CHANGED);
        }

        $queueConnection = (string) $row->connection;
        $queue = $this->config->get("queue.connections.{$queueConnection}");

        if (! is_array($queue) || ($queue['driver'] ?? null) !== 'database') {
            return new RetryResult(RetryResult::REFUSED, 'queue_unreachable', message: "The queue connection [{$queueConnection}] is not a database queue connection.");
        }

        $mode = (string) $records->mode($queueConnection);
        $queueDatabase = $this->sessions->databaseConnectionFor($queueConnection);

        try {
            $queueFence = $this->fence->available($queueDatabase);
        } catch (Throwable $error) {
            return $this->refused('queue_unreachable', $error);
        }

        try {
            $recordFence = $mode !== Operations::BEFORE || $sql === null || $this->fence->available($sql->connection());
        } catch (Throwable $error) {
            return $this->refused('record_unreachable', $error);
        }

        if (! $queueFence || ! $recordFence) {
            return new RetryResult(RetryResult::REFUSED, 'fence_missing', message: 'The fence table '.Fence::TABLE.' is missing; run php artisan orrery:install --fence and php artisan migrate.');
        }

        try {
            $queueNow = DatabaseClock::now($this->sessions->forAction($queueDatabase));
        } catch (Throwable $error) {
            return $this->refused('queue_unreachable', $error);
        }

        try {
            $mirrorNow = DatabaseClock::now($this->sessions->mirror());
        } catch (Throwable $error) {
            return $this->refused('mirror_unreachable', $error);
        }

        $recordNow = null;

        if ($mode === Operations::BEFORE && $sql !== null) {
            try {
                $recordNow = DatabaseClock::now($this->sessions->forAction($sql->connection()));
            } catch (Throwable $error) {
                return $this->refused('record_unreachable', $error);
            }
        }

        // 2. The record and the payload.
        $record = null;

        if ($sql !== null) {
            try {
                $record = $sql->read((int) $row->provider_id, $uuid);
            } catch (Throwable $error) {
                return $this->refused('record_unreachable', $error);
            }

            if ($record === null) {
                return new RetryResult(RetryResult::NOT_ELIGIBLE, 'record_missing');
            }

            $raw = $record->columns['payload'] ?? null;
        } else {
            $raw = $row->payload;
        }

        $id = Operations::newId();
        $cycle = (int) $row->cycle + 1;

        try {
            if (! is_string($raw) || $raw === '') {
                throw new RuntimeException('The failed occurrence has no payload.');
            }

            $payload = $this->payloads->build($raw, $cycle, $id);
        } catch (Throwable $error) {
            Watcher::instance()->report('retry:prepare', $error);

            return new RetryResult(RetryResult::FAILED, 'prepare_failed');
        }

        $timeout = $this->publishTimeout();
        $columns = [
            'id' => $id,
            'action' => Operations::RETRY,
            'mode' => $mode,
            'job_uuid' => $uuid,
            'occurrence_id' => $occurrenceId,
            'queue_connection' => $queueConnection,
            'queue' => (string) $row->queue,
            'record_connection' => $sql?->connection(),
            'record_table' => $sql?->table(),
            'record_id' => $record?->id,
            'record_uuid' => $record?->uuid,
            'record_deadline' => $recordNow === null ? null : $recordNow + $timeout,
            'saved_record' => $mode === Operations::BEFORE && $record !== null ? SqlFailedRecords::encode($record) : null,
            'payload' => $payload,
            'queue_fingerprint' => $this->publication->queueFingerprint($queueConnection),
            'record_fingerprint' => $sql?->target(),
            'deadline' => $queueNow + $timeout,
            'next_check_at' => $mirrorNow + $timeout,
            'actor' => self::actor($actor),
            'source' => $source,
        ];

        if ($bulkRef !== null) {
            // Only a run's operation names the column: a per-job retry works on a schema before step 8.
            $columns['bulk_ref'] = $bulkRef;
        }

        // 3. The claim.
        $claimed = $this->claim($uuid, $occurrenceId, $columns);

        if ($claimed instanceof RetryResult) {
            return $claimed;
        }

        Hooks::reach(self::AFTER_CLAIM);

        try {
            $operation = $this->operations->find($this->sessions->mirror(), $id);
        } catch (Throwable $error) {
            // The claim committed; the runner resolves the operation after its deadline.
            Watcher::instance()->report('retry:claim', $error);

            return new RetryResult(RetryResult::UNCERTAIN, 'claim_uncertain', $id);
        }

        if ($operation === null) {
            return new RetryResult(RetryResult::UNCERTAIN, 'claim_uncertain', $id);
        }

        // 4. Mode before: the exact delete first, fenced.
        if ($mode === Operations::BEFORE && $sql !== null) {
            $deleted = $this->deleteBefore($operation, $sql);

            if ($deleted !== null) {
                return $deleted;
            }
        }

        // 5. The publication.
        return $this->publish($operation, $payload, $mode === Operations::IN_TX ? $sql : null, $cycle);
    }

    /**
     * The row of the occurrence, or the outcome that refuses it. An
     * operation that holds the row gets its pending step first.
     */
    private function eligibleRow(string $uuid, int $occurrenceId): stdClass|RetryResult
    {
        try {
            $row = $this->row($uuid);

            if ($row !== null && $row->operation_id !== null) {
                $this->resolver->runPending((string) $row->operation_id);
                $row = $this->row($uuid);
            }
        } catch (Throwable $error) {
            return $this->refused('mirror_unreachable', $error);
        }

        if ($row === null) {
            return new RetryResult(RetryResult::NOT_ELIGIBLE, 'occurrence_mismatch');
        }

        $reason = self::ineligible($row, $occurrenceId);

        return $reason === null ? $row : new RetryResult(RetryResult::NOT_ELIGIBLE, $reason);
    }

    private static function ineligible(stdClass $row, int $occurrenceId): ?string
    {
        if ($row->operation_id !== null) {
            return 'operation_in_progress';
        }

        if ($row->status !== 'failed') {
            return 'not_failed';
        }

        if ((int) ($row->occurrence_id ?? 0) !== $occurrenceId) {
            return 'occurrence_mismatch';
        }

        return null;
    }

    /**
     * Step 3. Null when the claim committed (also after a COMMIT error whose
     * read shows the claim), else the outcome.
     *
     * @param  array<string, mixed>  $columns
     */
    private function claim(string $uuid, int $occurrenceId, array $columns): ?RetryResult
    {
        Hooks::reach(self::BEFORE_CLAIM);
        $id = (string) $columns['id'];
        $mirror = $this->sessions->mirror();
        $lost = new RuntimeException('orrery: the claim found no eligible row.');
        /** @var bool $done the closure sets it by reference */
        $done = false;

        try {
            $this->transactions->run($mirror, function (Connection $tx) use ($uuid, $occurrenceId, $columns, $id, $lost, &$done): void {
                $done = false;
                $claimed = $tx->table(JobStore::TABLE)
                    ->where('uuid', $uuid)
                    ->where('status', 'failed')
                    ->where('occurrence_id', $occurrenceId)
                    ->whereNull('operation_id')
                    ->update([
                        'observed_gap' => new Expression('CASE WHEN observed_attempts + late_outcomes < attempts THEN attempts - (observed_attempts + late_outcomes) ELSE 0 END'),
                        'status' => 'retrying',
                        'operation_id' => $id,
                        'updated_at' => Clock::toDecimal(Clock::now()),
                    ]);

                if ($claimed === 0) {
                    throw $lost;
                }

                $this->operations->insertClaimed($tx, $columns);

                // The run's claim count, in the claim transaction: it rolls back with the claim.
                if (isset($columns['bulk_ref']) && ! Operations::countBulkClaim($tx, (string) $columns['bulk_ref'], false)) {
                    throw new RolledBack('bulk_run_missing');
                }

                $tx->table(FailedTags::TABLE)->where('job_uuid', $uuid)->delete();
                $done = true;
            });
        } catch (Throwable $error) {
            if ($error === $lost) {
                $row = $this->rowOrNull($uuid);

                return new RetryResult(RetryResult::NOT_ELIGIBLE, $row === null ? 'occurrence_mismatch' : (self::ineligible($row, $occurrenceId) ?? 'operation_in_progress'));
            }

            if ($error instanceof RolledBack) {
                // The run's audit row is gone (its retention): nothing was claimed.
                return new RetryResult(RetryResult::FAILED, 'claim_failed');
            }

            if (! $done) {
                Watcher::instance()->report('retry:claim', $error);

                return new RetryResult(RetryResult::FAILED, 'claim_failed');
            }

            // The COMMIT may have reached the server: one read decides.
            try {
                $row = $this->row($uuid);
            } catch (Throwable) {
                return new RetryResult(RetryResult::UNCERTAIN, 'claim_uncertain', $id);
            }

            if ($row === null || $row->operation_id !== $id) {
                Watcher::instance()->report('retry:claim', $error);

                return new RetryResult(RetryResult::FAILED, 'claim_failed');
            }
        }

        return null;
    }

    /**
     * Step 4: the fenced exact delete on the failed-record session. Null when
     * the record is deleted, else the outcome (the operation aborts).
     */
    private function deleteBefore(stdClass $operation, SqlFailedRecords $records): ?RetryResult
    {
        Hooks::reach(self::BEFORE_RECORD_DELETE);
        $id = (string) $operation->id;

        if (! $this->handler->recordTargetHolds($operation)) {
            $this->keepWithCode($operation, 'target_changed');

            return new RetryResult(RetryResult::UNCERTAIN, 'target_changed', $id, message: self::TARGET_CHANGED);
        }

        try {
            $session = $this->sessions->forAction($records->connection());
        } catch (Throwable $error) {
            Watcher::instance()->report('retry:record_delete', $error);

            return $this->aborted($operation, 'record_delete_failed', Operations::errorText('retry:record_delete', $error));
        }

        $refusal = new RuntimeException('orrery: the delete is refused.');
        /** @var string|null $code the closure sets it by reference */
        $code = null;
        /** @var bool $done the closure sets it by reference */
        $done = false;

        try {
            $this->transactions->run($session, function (Connection $tx) use ($operation, $records, $id, $refusal, &$code, &$done): void {
                $done = false;
                $fenced = $this->fence->insertBefore($tx, $id, Fence::DELETE, Fence::DELETED, (float) $operation->record_deadline);

                if ($fenced !== Fence::INSERTED) {
                    $code = $fenced === Fence::DUPLICATE ? 'cancelled' : 'deadline_passed';

                    throw $refusal;
                }

                if ($records->delete($tx, (int) $operation->record_id, (string) $operation->record_uuid) !== 1) {
                    $code = 'record_gone';

                    throw $refusal;
                }

                $done = true;
            });
        } catch (Throwable $error) {
            if ($error === $refusal && $code !== null) {
                return $this->aborted($operation, $code, null);
            }

            $this->sessions->disconnect($session);
            Watcher::instance()->report('retry:record_delete', $error);
            $text = Operations::errorText('retry:record_delete', $error);

            if ($done) {
                // Whether the delete happened is never kept here: the abort reads the delete fence row.
                $this->handler->abort($operation, 'record_delete_uncertain', $text, true);

                return new RetryResult(RetryResult::UNCERTAIN, 'record_delete_uncertain', $id);
            }

            return $this->aborted($operation, 'record_delete_failed', $text);
        }

        Hooks::reach(self::AFTER_RECORD_DELETE);

        return null;
    }

    /**
     * Steps 5–7.
     */
    private function publish(stdClass $operation, string $payload, ?SqlFailedRecords $records, int $cycle): RetryResult
    {
        $id = (string) $operation->id;

        if (! $this->handler->queueTargetHolds($operation) || ($records !== null && ! $this->handler->recordTargetHolds($operation))) {
            $this->keepWithCode($operation, 'target_changed');

            return new RetryResult(RetryResult::UNCERTAIN, 'target_changed', $id, message: self::TARGET_CHANGED);
        }

        try {
            $session = $this->sessions->forAction($this->handler->queueDatabase($operation));
        } catch (Throwable $error) {
            Watcher::instance()->report('retry:publish', $error);

            return $this->aborted($operation, 'publish_failed', Operations::errorText('retry:publish', $error));
        }

        $outcome = $this->publication->publish($session, $operation, $payload, $records);

        switch ($outcome['state']) {
            case Publication::PUBLISHED:
                $jobId = (int) $outcome['jobId'];
                Hooks::reach(self::AFTER_COMMIT);

                try {
                    $this->handler->published($operation, $jobId);
                } catch (Throwable $error) {
                    // The publication committed; the runner finalises it.
                    Watcher::instance()->report('retry:finalize', $error);
                }

                return new RetryResult(RetryResult::ACCEPTED, null, $id, $jobId, $cycle);
            case Publication::CANCELLED:
            case Publication::DEADLINE_PASSED:
            case Publication::RECORD_GONE:
                return $this->aborted($operation, $outcome['state'], null);
            case Publication::FAILED:
                $error = $outcome['error'] ?? new RuntimeException('The publication failed.');
                Watcher::instance()->report('retry:publish', $error);

                return $this->aborted($operation, 'publish_failed', Operations::errorText('retry:publish', $error));
            default:
                $error = $outcome['error'] ?? new RuntimeException('The COMMIT of the publication failed.');
                Watcher::instance()->report('retry:publish', $error);
                $resolved = $this->handler->resolveNow($operation);

                if ($resolved['state'] === Publication::PUBLISHED) {
                    return new RetryResult(RetryResult::ACCEPTED, null, $id, $resolved['jobId'], $cycle);
                }

                if ($resolved['state'] === Publication::CANCELLED) {
                    return $this->aborted($operation, 'publish_failed', Operations::errorText('retry:publish', $error));
                }

                $this->keepWithCode($operation, 'publish_uncertain', Operations::errorText('retry:publish', $error));

                return new RetryResult(RetryResult::UNCERTAIN, 'publish_uncertain', $id);
        }
    }

    /**
     * The abort at once, as the original aborter. failed with $code when the
     * operation ends aborted; uncertain with awaiting_operator and the reason
     * when its next step waits for an operator (the operation is marked
     * awaiting at once, and keeps $code as its cause); else uncertain with
     * the code that keeps it.
     */
    private function aborted(stdClass $operation, string $code, ?string $error): RetryResult
    {
        $id = (string) $operation->id;
        $step = $this->handler->abort($operation, $code, $error, true);

        if ($step->kind === OperationStep::DECIDED) {
            return new RetryResult(RetryResult::FAILED, $code, $id);
        }

        if ($step->kind === OperationStep::AWAITING) {
            $reason = (string) $step->reason;

            try {
                $mirror = $this->sessions->mirror();
                $this->operations->await($mirror, $id, $reason, DatabaseClock::now($mirror));
            } catch (Throwable $failure) {
                // The runner marks it at its next check.
                Watcher::instance()->report('retry:await', $failure);
            }

            return new RetryResult(RetryResult::UNCERTAIN, 'awaiting_operator', $id, message: self::AWAITING_OPERATOR, awaitingReason: $reason);
        }

        if ($step->kind === OperationStep::UNDECIDED || $step->kind === OperationStep::BLOCKED) {
            $this->keepWithCode($operation, (string) ($step->errorCode ?? 'restore_failed'), $step->error);
        }

        return new RetryResult(RetryResult::UNCERTAIN, $step->errorCode ?? 'restore_failed', $id);
    }

    /**
     * The operation keeps its state; the code and the next check of the
     * runner (the backoff of one check).
     */
    private function keepWithCode(stdClass $operation, string $code, ?string $error = null): void
    {
        try {
            $mirror = $this->sessions->mirror();
            $this->operations->reschedule($mirror, (string) $operation->id, DatabaseClock::now($mirror) + OperationRunner::backoff(1), true, $code, $error);
        } catch (Throwable $failure) {
            Watcher::instance()->report('retry:reschedule', $failure);
        }
    }

    private function refused(string $code, Throwable $error): RetryResult
    {
        Degradation::error("retry:{$code}", $error);
        Watcher::instance()->report("retry:{$code}", $error);

        return new RetryResult(RetryResult::REFUSED, $code);
    }

    private function row(string $uuid): ?stdClass
    {
        $row = $this->sessions->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function rowOrNull(string $uuid): ?stdClass
    {
        try {
            return $this->row($uuid);
        } catch (Throwable) {
            return null;
        }
    }

    private function publishTimeout(): int
    {
        $timeout = $this->config->get('orrery.retry.publish_timeout', 60);

        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : 60;
    }

    private static function actor(?string $actor): ?string
    {
        return $actor === null ? null : SafeText::clean($actor);
    }

    /**
     * The claim, the delete, the abort and the finalisation commit in their
     * own mirror transactions. Inside a caller's mirror transaction they
     * would become savepoints while the publication commits on Q at once.
     */
    private function refuseOpenMirrorTransaction(): void
    {
        try {
            $open = PackageTransaction::isOpen($this->sessions->mirror());
        } catch (Throwable) {
            return;
        }

        if ($open) {
            throw new LogicException('RetryService::retry() runs its own mirror transactions, so it cannot run inside an open transaction of the mirror session.');
        }
    }
}
