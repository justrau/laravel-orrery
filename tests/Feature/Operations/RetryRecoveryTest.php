<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Closure;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Lifecycle\History;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Support\LostAckCommitPdo;
use Justrau\Orrery\Tests\Support\SessionPdo;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\ThrowingCommitPdo;
use PDO;

/**
 * The recovery of an interrupted retry in one process (its crash
 * points, in-process): the publisher stops at a
 * hold point (it throws there), or a COMMIT fails, and the runner (on a host
 * SQLite file: the operator's resolve, after the runner left the operation
 * awaiting) ends the operation from the fence. The process variants with a
 * real kill are in RetryCrashPointTest. Kind: new behaviour.
 */
final class RetryRecoveryTest extends RetryProtocolTestCase
{
    /**
     * Rejects: a recovery that guesses without the fence, or by time alone: after a claim whose publisher
     * stopped, the runner cancels past the deadline; the operation is aborted, the row failed with the same
     * occurrence and its failed tags, the record present, and no published fence row.
     */
    public function test_stopped_after_the_claim_is_cancelled_after_the_deadline(): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(['beta']));
        $this->killAt(RetryService::AFTER_CLAIM);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_CLAIM);

        $operation = $this->onlyOperation();
        self::assertSame([Operations::CLAIMED, 'retrying'], [$operation->state, $this->rowOrFail($uuid)->status]);

        // Before the deadline the runner waits, and so does the operator's resolve (Q's clock decides).
        $this->runOnce();
        self::assertSame(Operations::CLAIMED, $this->operation($operation->id)->state);
        self::assertSame('later', $this->resolveAsOperator($operation->id)['step'] ?? null);
        self::assertSame(Operations::CLAIMED, $this->operation($operation->id)->state);
        self::assertNull($this->fenceRow($operation->id, Fence::PUBLISH), 'Nothing is cancelled before the deadline.');

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($operation->id);

        $operation = $this->operation($operation->id);
        self::assertSame([Operations::ABORTED, 'cancelled'], [$operation->state, $operation->error_code]);
        self::assertSame(Fence::CANCELLED, $this->fenceRow($operation->id, Fence::PUBLISH)?->state);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence, null, 1], [$row->status, (int) $row->occurrence_id, $row->operation_id, (int) $row->cycle]);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, $this->jobsOf($operation->id));
        self::assertSame(['beta'], $this->failedTags($uuid));
        self::assertSame(['failed', 'retry_aborted'], $this->historyEvents($uuid));
        self::assertNull($operation->awaiting_since, 'A terminal operation awaits nothing.');

        // The occurrence is free again: a second retry publishes.
        self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
    }

    /**
     * Rejects: a delete decided from a process-local flag, and a restore that leaves the old id on the row (and
     * test_restore_carries_the_new_record_id): mode before, stopped after the record's delete; the runner cancels,
     * decides the delete as deleted, restores the record once (a new id, the same payload), and the row is failed
     * with provider_id = the new id. A second retry then deletes the restored record by its new id.
     */
    public function test_stopped_after_the_record_delete_restores_the_record_once(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $original = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_RECORD_DELETE);

        $operation = $this->onlyOperation();
        self::assertSame(Operations::BEFORE, $operation->mode);
        self::assertSame(0, $this->failedRecords($uuid), 'The record is deleted before the publication.');
        self::assertSame(Fence::DELETED, $this->fenceRow($operation->id, Fence::DELETE)?->state);

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($operation->id);

        $operation = $this->operation($operation->id);
        self::assertSame(Operations::ABORTED, $operation->state);
        $restored = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        self::assertNotSame((int) $original->id, (int) $restored->id, 'The restore has a new id.');
        self::assertSame($original->payload, $restored->payload);
        self::assertSame($original->exception, $restored->exception);
        self::assertSame((int) $restored->id, (int) $this->fenceRow($operation->id, Fence::RESTORE)?->job_id);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', $occurrence, (int) $restored->id], [$row->status, (int) $row->occurrence_id, (int) $row->provider_id]);
        self::assertSame(0, $this->jobsOf($operation->id));

        // Another run changes nothing: the restore happened once.
        $this->runOnce();
        self::assertSame(1, $this->failedRecords($uuid));

        $second = $this->retry($uuid, $occurrence);
        self::assertOutcome(RetryResult::ACCEPTED, null, $second);
        self::assertSame(0, $this->failedRecords($uuid), 'The second retry deleted the restored record by its new id.');
        self::assertSame((int) $restored->id, (int) $this->operation((string) $second->operationId)->record_id);
    }

    /**
     * Rejects: a restore without the restore key, and a restore that is not retried after its failure: the
     * restore fails while the failed table is unreachable; the operation stays aborting with restore_failed and the
     * row retrying; then the runner restores once and ends it aborted.
     */
    public function test_failed_restore_is_finished_once_by_the_runner(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $schema = DB::connection(TestEnvironment::PRIMARY)->getSchemaBuilder();
        $queueSession = $this->queueClockName();
        $shift = fn () => $this->shiftClock($queueSession, 120);
        Hooks::$callback = static function (string $point) use ($schema, $shift): void {
            if ($point === RetryService::AFTER_RECORD_DELETE) {
                // Q's clock passes the deadline: the publication's fence insert inserts nothing.
                $shift();
            }

            if ($point === RetryHandler::BEFORE_RESTORE) {
                $schema->rename('failed_jobs', 'failed_jobs_away');
            }
        };

        $outcome = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;
        $schema->rename('failed_jobs_away', 'failed_jobs');

        self::assertOutcome(RetryResult::UNCERTAIN, 'restore_failed', $outcome);
        $operation = $this->operation((string) $outcome->operationId);
        self::assertSame([Operations::ABORTING, 'restore_failed'], [$operation->state, $operation->error_code]);
        self::assertSame('retrying', $this->rowOrFail($uuid)->status, 'The row stays held until the restore.');
        self::assertSame(0, $this->failedRecords($uuid));

        $this->moveDatabaseClocks(4000);
        $this->finishByRunnerOrOperator($operation->id, false);

        self::assertSame(Operations::ABORTED, $this->operation($operation->id)->state, (string) $this->operation($operation->id)->error);
        $restored = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        self::assertSame(['failed', (int) $restored->id], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->provider_id]);

        $this->runOnce();
        self::assertSame(1, $this->failedRecords($uuid), 'One restored record in total.');
    }

    /**
     * Rejects: a finalisation that waits for time, and a published operation left unfinished: stopped right after
     * the publication's COMMIT, the runner finalises from the published fence row (on a host file with automatic
     * reads too: it reads the file and writes the mirror only).
     */
    public function test_stopped_after_the_commit_is_finalised_from_the_fence(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_COMMIT);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_COMMIT);

        $operation = $this->onlyOperation();
        self::assertSame(Operations::CLAIMED, $operation->state);
        self::assertSame(1, $this->jobsOf($operation->id));

        $this->moveDatabaseClocks(120);
        $this->runOnce();

        $operation = $this->operation($operation->id);
        $fence = $this->fenceRow($operation->id, Fence::PUBLISH);
        self::assertSame(Operations::DONE, $operation->state, 'The runner finalised without an operator.');
        $row = $this->rowOrFail($uuid);
        self::assertSame(['pending', 2, (int) $fence?->job_id], [$row->status, (int) $row->cycle, (int) $row->job_id]);
        self::assertSame(['failed', 'retried'], $this->historyEvents($uuid));
    }

    /**
     * Rejects: an adoption that waits for the finaliser, and a second `retried` row (the worker first): a worker
     * reserves and completes the published job before any finalisation; the operation is done and the row completed
     * in cycle 2.
     */
    public function test_worker_adopts_a_published_retry_first(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_COMMIT);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_COMMIT);
        $operation = $this->onlyOperation();

        $this->work();

        self::assertSame(Operations::DONE, $this->operation($operation->id)->state);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 2, null], [$row->status, (int) $row->cycle, $row->operation_id]);
        self::assertSame(['failed', 'retried', 'completed'], array_values(array_filter($this->historyEvents($uuid), static fn (string $event): bool => in_array($event, ['failed', 'retried', 'completed'], true))));

        $this->moveDatabaseClocks(120);
        $this->runOnce();
        self::assertSame(1, count(array_keys($this->historyEvents($uuid), 'retried')), 'No second retried row.');
    }

    /**
     * Rejects: an adoption that needs the reservation (C2 through the operation-id rule): the publisher stops after
     * its COMMIT, the worker's reservation observation is dropped, and its completion adopts the retry: done, and the
     * row completed in cycle 2.
     */
    public function test_completion_adopts_when_the_reservation_was_dropped(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_COMMIT);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_COMMIT);
        $operation = $this->onlyOperation();

        Watcher::failOperationsForTesting(['hook:pop']);

        try {
            $this->work();
        } finally {
            Watcher::failOperationsForTesting([]);
        }

        self::assertSame(Operations::DONE, $this->operation($operation->id)->state);
        $row = $this->rowOrFail($uuid);
        self::assertSame(['completed', 2, null], [$row->status, (int) $row->cycle, $row->operation_id]);
        self::assertSame(1, count(array_keys($this->historyEvents($uuid), 'retried')));
    }

    /**
     * Rejects: an operation left published forever after a failed finalisation: the finalisation fails; the
     * operation stays published and the result is accepted; the runner finalises it.
     */
    public function test_failed_finalisation_is_finished_by_the_runner(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $fail = true;
        Hooks::$callback = static function (string $point) use (&$fail): void {
            if ($fail && $point === History::AFTER_BOUNDS) {
                $fail = false;

                throw new \RuntimeException('The finalisation fails (test).');
            }
        };

        $outcome = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        $id = (string) $outcome->operationId;
        self::assertSame(Operations::PUBLISHED, $this->operation($id)->state);
        self::assertSame('retrying', $this->rowOrFail($uuid)->status);

        $this->moveDatabaseClocks(400);
        $this->runOnce();

        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(['pending', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);
    }

    /**
     * Rejects: a publication decided by the COMMIT error alone: COMMIT throws and the server did not commit; the
     * resolution at once cancels; the result is failed / publish_failed, the row failed, the record present.
     */
    public function test_commit_error_without_a_commit_is_cancelled_at_once(): void
    {
        $this->needsSharedDatabase();
        [$uuid, $occurrence] = $this->failedJob();
        $this->publicationPdo(static fn (array $config): PDO => ThrowingCommitPdo::forConfig($config));

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::FAILED, 'publish_failed', $outcome);
        $id = (string) $outcome->operationId;
        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        self::assertSame(Fence::CANCELLED, $this->fenceRow($id, Fence::PUBLISH)?->state);
        self::assertSame(['failed', $occurrence], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->occurrence_id]);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, $this->jobsOf($id));
    }

    /**
     * Rejects: a lost acknowledgement read as a failure: COMMIT throws after the server committed; the
     * resolution at once reads `published`; accepted, and the row pending in cycle 2.
     */
    public function test_lost_commit_acknowledgement_is_accepted(): void
    {
        $this->needsSharedDatabase();
        [$uuid, $occurrence] = $this->failedJob();
        $this->publicationPdo(static fn (array $config): PDO => LostAckCommitPdo::forConfig($config));

        $outcome = $this->retry($uuid, $occurrence);

        self::assertOutcome(RetryResult::ACCEPTED, null, $outcome);
        $id = (string) $outcome->operationId;
        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(1, $this->jobsOf($id));
        self::assertSame(['pending', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);
        self::assertSame(0, $this->failedRecords($uuid));
    }

    /**
     * Rejects: claim_failed with a written claim: the claim's COMMIT throws after the server committed; the
     * retry continues (or is uncertain), never claim_failed.
     */
    public function test_lost_claim_acknowledgement_continues(): void
    {
        $this->needsSharedDatabase();
        [$uuid, $occurrence] = $this->failedJob();
        $sessions = $this->app->make(PackageSession::class);
        $config = $this->mirrorConfig();
        Hooks::$callback = static function (string $point) use ($sessions, $config): void {
            if ($point === RetryService::BEFORE_CLAIM) {
                $sessions->mirror()->setPdo(SessionPdo::for($sessions->mirror(), LostAckCommitPdo::forConfig($config)));
            }
        };

        $outcome = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;

        self::assertNotSame('claim_failed', $outcome->code);
        self::assertContains([$outcome->result, $outcome->code], [[RetryResult::ACCEPTED, null], [RetryResult::UNCERTAIN, 'claim_uncertain']]);
        self::assertCount(1, $this->operations());
        self::assertContains($this->rowOrFail($uuid)->status, ['retrying', 'pending']);
    }

    /**
     * Installs a PDO from $make on Q's operator session before the publication.
     *
     * @param  Closure(array<string, mixed>): PDO  $make
     */
    private function publicationPdo(Closure $make): void
    {
        $sessions = $this->app->make(PackageSession::class);
        $config = (array) $this->app['config']->get('database.connections.'.TestEnvironment::PRIMARY);
        $done = false;
        Hooks::$callback = static function (string $point) use ($sessions, $config, $make, &$done): void {
            if (! $done && $point === Publication::BEFORE_FENCE_INSERT) {
                $done = true;
                $session = $sessions->forAction(TestEnvironment::PRIMARY);
                $session->setPdo(SessionPdo::for($session, $make($config)));
            }
        };
    }

    /**
     * The connection configuration of the mirror session's database.
     *
     * @return array<string, mixed>
     */
    private function mirrorConfig(): array
    {
        $sessions = $this->app->make(PackageSession::class);
        $sibling = $sessions->siblingPath();

        if ($sibling !== null) {
            return ['driver' => 'sqlite', 'database' => $sibling, 'prefix' => ''];
        }

        $name = (string) $sessions->mirror()->getName();
        $name = str_starts_with($name, PackageSession::NAME_PREFIX) ? substr($name, strlen(PackageSession::NAME_PREFIX)) : $name;

        return (array) $this->app['config']->get('database.connections.'.$name);
    }
}
