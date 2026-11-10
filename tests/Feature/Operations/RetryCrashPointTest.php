<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Operations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Justrau\Orrery\Retry\Fence;
use Justrau\Orrery\Retry\Operations;
use Justrau\Orrery\Retry\Phases\OperationRetentionPhase;
use Justrau\Orrery\Retry\Publication;
use Justrau\Orrery\Retry\RetryHandler;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Runtime\Hooks;
use Justrau\Orrery\Storage\PackageSession;
use Justrau\Orrery\Tests\Feature\Operations\Fixtures\OperationCommandsProvider;
use Justrau\Orrery\Tests\Support\Barrier;
use Justrau\Orrery\Tests\Support\TestEnvironment;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use PHPUnit\Framework\Attributes\Group;

/**
 * The crash points and stale callers of a retry (and the
 * process tests of
 * the retry protocol): a child process runs the retry and is held at a hold
 * point, then killed or resumed. On a SQLite file the queue's file is a host
 * file (D17): a step that writes it is the operator's, so those cases assert
 * the awaiting state, then resolve. Kind: new behaviour.
 */
#[Group('process')]
#[Group('shared-database')]
final class RetryCrashPointTest extends RetryProtocolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->needsSharedDatabase();
    }

    /**
     * Rejects: a retry that writes before its claim: killed before the claim, nothing changed.
     */
    public function test_killed_before_the_claim_changes_nothing(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::BEFORE_CLAIM]);
        $this->awaitHold($child, RetryService::BEFORE_CLAIM);
        $child->stop();

        self::assertSame([], $this->operations());
        $row = $this->rowOrFail($uuid);
        self::assertSame(['failed', null, $occurrence], [$row->status, $row->operation_id, (int) $row->occurrence_id]);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, (int) DB::table('jobs')->count());
    }

    /**
     * Rejects: a recovery by time alone: killed after the claim; after the deadline the runner (on a host file
     * the operator) cancels; aborted, the row failed, no published fence row.
     */
    public function test_killed_after_the_claim_is_cancelled(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_CLAIM]);
        $this->awaitHold($child, RetryService::AFTER_CLAIM);
        $child->stop();

        $id = $this->onlyOperation()->id;
        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($id);

        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        self::assertSame(Fence::CANCELLED, $this->fenceRow($id, Fence::PUBLISH)?->state);
        self::assertSame('failed', $this->rowOrFail($uuid)->status);
        self::assertSame(1, $this->failedRecords($uuid));
    }

    /**
     * Rejects: a delete decided from a process-local flag: mode before, killed after the delete's COMMIT; the
     * runner, another process, reads the delete row and restores the record once with a new id on the row.
     */
    public function test_killed_after_the_record_delete_is_restored_by_another_process(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $original = (int) $this->failedRecordId($uuid);
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_RECORD_DELETE], [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($child, RetryService::AFTER_RECORD_DELETE);
        $child->stop();

        $id = $this->onlyOperation()->id;
        self::assertSame(0, $this->failedRecords($uuid));
        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($id, false);

        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        $restored = (int) $this->failedRecordId($uuid);
        self::assertNotSame($original, $restored);
        self::assertSame(['failed', $restored], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->provider_id]);
        self::assertSame(1, $this->failedRecords($uuid));
    }

    /**
     * Rejects: a publication left half done by a killed publisher: killed inside the publication's
     * transaction; the server rolls back; the runner cancels; aborted, the record present (in_tx), no jobs row.
     */
    public function test_killed_inside_the_publication_rolls_back(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [Publication::BEFORE_COMMIT]);
        $this->awaitHold($child, Publication::BEFORE_COMMIT);
        $child->stop();

        $id = $this->onlyOperation()->id;
        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($id);

        self::assertSame(Operations::ABORTED, $this->operation($id)->state);
        self::assertSame(Fence::CANCELLED, $this->fenceRow($id, Fence::PUBLISH)?->state);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame(0, $this->jobsOf($id));
    }

    /**
     * Rejects: a published retry that only the publisher can finish: killed after the COMMIT; the runner
     * finalises from the published fence row.
     */
    public function test_killed_after_the_commit_is_finalised(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_COMMIT]);
        $this->awaitHold($child, RetryService::AFTER_COMMIT);
        $child->stop();

        $id = $this->onlyOperation()->id;
        $this->moveDatabaseClocks(120);
        $this->runOnce();

        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(['pending', 2], [$this->rowOrFail($uuid)->status, (int) $this->rowOrFail($uuid)->cycle]);
        self::assertSame(1, $this->jobsOf($id));
    }

    /**
     * Rejects: a claim that reads and then writes without a guarded update: the winner claims between the loser's
     * preparation and its claim; one accepted, one operation_in_progress, one fence row, one jobs row.
     */
    public function test_two_claims_of_one_occurrence_publish_once(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $winner = $this->startRetry($uuid, $occurrence, [RetryService::BEFORE_CLAIM, RetryService::AFTER_CLAIM]);
        $this->awaitHold($winner, RetryService::BEFORE_CLAIM);
        $holds = $this->holds();
        Hooks::$callback = static function (string $point) use ($holds, $winner): void {
            if ($point === RetryService::BEFORE_CLAIM) {
                // The loser prepared; the winner claims now.
                $holds->arrive(RetryService::BEFORE_CLAIM.'.release');
                $holds->await(RetryService::AFTER_CLAIM.'.reached', 60, $winner->abandonIfExited());
            }
        };

        $loser = $this->retry($uuid, $occurrence);
        Hooks::$callback = null;
        $this->release(RetryService::AFTER_CLAIM);
        $won = $this->childOutcome($winner);

        self::assertOutcome(RetryResult::NOT_ELIGIBLE, 'operation_in_progress', $loser);
        self::assertSame([RetryResult::ACCEPTED, null], [$won['result'], $won['code']]);
        $id = (string) $won['operation_id'];
        self::assertCount(1, $this->operations());
        self::assertSame(1, (int) DB::connection(TestEnvironment::PRIMARY)->table(Fence::TABLE)->where('slot', Fence::PUBLISH)->count());
        self::assertSame(1, (int) DB::table('jobs')->count());
        self::assertSame(1, $this->jobsOf($id));
    }

    /**
     * Rejects: a deadline checked outside the fence insert, and a cancelled operation published later: the
     * publisher is held before its fence insert; the runner cancels and aborts; a second retry publishes and its job
     * fails again (a new record); the first publisher resumes, and its fence insert changes nothing.
     */
    public function test_publisher_resumed_after_a_cancel_changes_nothing(): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: 2));
        $child = $this->startRetry($uuid, $occurrence, [Publication::BEFORE_FENCE_INSERT]);
        $this->awaitHold($child, Publication::BEFORE_FENCE_INSERT);
        $first = $this->onlyOperation()->id;

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($first);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state);

        $second = $this->retry($uuid, $occurrence);
        self::assertOutcome(RetryResult::ACCEPTED, null, $second);
        $this->workAndLogFailures();
        $refailure = DB::table('failed_jobs')->where('uuid', $uuid)->sole();

        $this->release(Publication::BEFORE_FENCE_INSERT);
        $resumed = $this->childOutcome($child);

        self::assertSame(RetryResult::FAILED, $resumed['result']);
        self::assertContains($resumed['code'], ['cancelled', 'deadline_passed']);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state);
        self::assertSame(0, $this->jobsOf($first), 'The first publisher published nothing.');
        self::assertSame([(int) $refailure->id], DB::table('failed_jobs')->where('uuid', $uuid)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(), 'Exactly one failed record: the new one.');
    }

    /**
     * Rejects: a restore without the restore key, and a restore without the aborting transition: the original
     * aborter is held before its restore; the runner restores and aborts; a second retry claims and publishes; the
     * original resumes and restores nothing.
     */
    public function test_original_aborter_resumed_after_the_runner_restores_nothing(): void
    {
        [$uuid, $occurrence, $child, $first] = $this->aborterHeldBeforeRestore();

        $this->moveDatabaseClocks(4000);
        $this->finishByRunnerOrOperator($first);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state);
        $restored = (int) $this->failedRecordId($uuid);

        $second = $this->retry($uuid, $occurrence);
        self::assertOutcome(RetryResult::ACCEPTED, null, $second);

        $this->release(RetryHandler::BEFORE_RESTORE);
        $this->childOutcome($child);

        self::assertSame(Operations::ABORTED, $this->operation($first)->state, 'aborted stays terminal.');
        self::assertSame(0, $this->failedRecords($uuid), 'The second retry deleted the restored record; nothing was restored again.');
        self::assertSame($restored, (int) $this->fenceRow($first, Fence::RESTORE, self::FAILED_CONNECTION)?->job_id);
        self::assertSame(Operations::DONE, $this->operation((string) $second->operationId)->state, 'The second operation is untouched.');
    }

    /**
     * Rejects: a restore authorised by a cached decision, and per-slot fence deletion: the original
     * aborter is held before its restore; the runner restores and aborts; a second retry publishes; both databases
     * move 31 days ahead and the fence and ledger retention run; the aborter resumes and restores nothing. On a host
     * SQLite file the retention deletes nothing, and the resumed aborter finds the restore row.
     */
    public function test_stale_restorer_after_fence_and_ledger_retention(): void
    {
        [$uuid, $occurrence, $child, $first] = $this->aborterHeldBeforeRestore();

        $this->moveDatabaseClocks(4000);
        $this->finishByRunnerOrOperator($first);
        self::assertOutcome(RetryResult::ACCEPTED, null, $this->retry($uuid, $occurrence));
        self::assertSame(0, $this->failedRecords($uuid));

        $this->moveDatabaseClocks(31 * 86400 + 4000);
        $retention = $this->app->make(OperationRetentionPhase::class);
        $retention->run(new \Justrau\Orrery\Maintenance\TickBudget(\Justrau\Orrery\Storage\Clock::monotonic() + 30));

        if (! $this->queueIsHostFile()) {
            self::assertNull($this->fenceRow($first, Fence::DELETE, self::FAILED_CONNECTION), 'The fence retention deleted the finished operation\'s rows.');
            self::assertNull($this->fenceRow($first, Fence::RESTORE, self::FAILED_CONNECTION));
            self::assertSame(0, (int) $this->mirror()->table(Operations::TABLE)->where('id', $first)->count(), 'The ledger retention deleted it.');
        } else {
            self::assertNotNull($this->fenceRow($first, Fence::DELETE, self::FAILED_CONNECTION), 'No automatic write on a host SQLite file.');
        }

        $this->release(RetryHandler::BEFORE_RESTORE);
        $this->childOutcome($child);

        self::assertSame(0, $this->failedRecords($uuid), 'The stale restorer restored nothing.');
    }

    /**
     * Rejects: a published operation finalised twice: the publisher is held after its COMMIT; a worker adopts
     * and completes the job; the publisher resumes; the operation stays done with one `retried` row, and the ledger
     * retention can delete it later.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('adoptions')]
    public function test_publisher_resumed_after_worker_adoption(bool $fails): void
    {
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: $fails ? 2 : 1));
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_COMMIT]);
        $this->awaitHold($child, RetryService::AFTER_COMMIT);
        $id = $this->onlyOperation()->id;

        if ($fails) {
            // The worker fails the job again (F1 adoption): a new record, bound to the new occurrence.
            $this->workAndLogFailures();
        } else {
            $this->work();
        }

        self::assertSame(Operations::DONE, $this->operation($id)->state);

        $this->release(RetryService::AFTER_COMMIT);
        $outcome = $this->childOutcome($child);

        self::assertSame(RetryResult::ACCEPTED, $outcome['result']);
        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(1, count(array_keys($this->historyEvents($uuid), 'retried')));
        $row = $this->rowOrFail($uuid);
        self::assertSame([$fails ? 'failed' : 'completed', 2], [$row->status, (int) $row->cycle]);

        if ($fails) {
            self::assertSame((int) $this->failedRecordId($uuid), (int) $row->provider_id);
            self::assertNotSame($occurrence, (int) $row->occurrence_id);
        }

        $this->moveDatabaseClocks(31 * 86400);
        self::assertSame(1, $this->app->make(OperationRetentionPhase::class)->ledgerRun());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function adoptions(): array
    {
        return ['the worker completes the job' => [false], 'the worker fails the job again' => [true]];
    }

    /**
     * Rejects: a delete fence row inserted after the delete instead of before it: a mode-before publisher held
     * before its delete; the runner decides the delete (delete_cancelled) after record_deadline and aborts; a second
     * retry claims and publishes; the first resumes, and its delete loses on the key: no delete.
     */
    public function test_stale_deleter_loses_on_the_delete_key(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::BEFORE_RECORD_DELETE], [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($child, RetryService::BEFORE_RECORD_DELETE);
        $first = $this->onlyOperation()->id;

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($first);
        self::assertSame(Operations::ABORTED, $this->operation($first)->state);
        self::assertSame(Fence::DELETE_CANCELLED, $this->fenceRow($first, Fence::DELETE, self::FAILED_CONNECTION)?->state);
        self::assertSame(1, $this->failedRecords($uuid));

        $second = $this->retry($uuid, $occurrence);
        self::assertOutcome(RetryResult::ACCEPTED, null, $second);

        $this->release(RetryService::BEFORE_RECORD_DELETE);
        $resumed = $this->childOutcome($child);

        // Its own fence insert refuses: the key (cancelled) or, by its moved clock, the deadline.
        self::assertSame(RetryResult::FAILED, $resumed['result']);
        self::assertContains($resumed['code'], ['cancelled', 'deadline_passed']);
        self::assertSame(0, $this->failedRecords($uuid), 'Only the second retry deleted the record.');
        self::assertSame(Fence::DELETED, $this->fenceRow((string) $second->operationId, Fence::DELETE, self::FAILED_CONNECTION)?->state, 'The second operation\'s delete stays.');
        self::assertSame(Operations::DONE, $this->operation((string) $second->operationId)->state);
        self::assertSame(1, $this->jobsOf((string) $second->operationId));
        self::assertSame(0, $this->jobsOf($first));
    }

    /**
     * Rejects: a restore without the restore key: two runners behind a barrier before the restore of one
     * aborting operation; one restore, one aborted, no error left. On a host SQLite file the runners are two
     * operator resolves.
     */
    public function test_two_runners_restore_once(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $this->killAt(RetryService::AFTER_RECORD_DELETE);
        $this->expectKilled(fn () => $this->retry($uuid, $occurrence), RetryService::AFTER_RECORD_DELETE);
        $id = $this->onlyOperation()->id;
        $this->app->make(Operations::class)->markAborting($this->mirror(), $id, 'cancelled');
        $this->moveDatabaseClocks(4000);

        $arguments = $this->queueIsHostFile() ? ['orrery:resolve', $id] : ['orrery:tick'];
        $second = Barrier::forTest();
        $a = $this->startCommand($arguments, [RetryHandler::BEFORE_RESTORE], null, [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $b = $this->startCommand($arguments, [RetryHandler::BEFORE_RESTORE], $second, [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($a, RetryHandler::BEFORE_RESTORE);
        $second->await(RetryHandler::BEFORE_RESTORE.'.reached', 60, $b->abandonIfExited());

        $this->release(RetryHandler::BEFORE_RESTORE);
        $second->arrive(RetryHandler::BEFORE_RESTORE.'.release');
        $a->waitForExit(60);
        $b->waitForExit(60);

        $operation = $this->operation($id);
        self::assertSame(Operations::ABORTED, $operation->state, $a->describe().$b->describe());
        self::assertSame('cancelled', $operation->error_code, 'No restore_conflict or restore_failed is left.');
        self::assertStringNotContainsString('restore_conflict', $a->output().$b->output(), 'Neither runner met a uuid conflict.');
        self::assertSame(0, (int) $this->mirror()->table('orrery_health_events')->where('last_error_operation', 'like', 'retry:restore%')->count(), 'Neither runner recorded a restore error.');
        self::assertSame(1, $this->failedRecords($uuid), 'One restore.');
        self::assertSame((int) $this->failedRecordId($uuid), (int) $this->rowOrFail($uuid)->provider_id);
    }

    /**
     * Rejects: a restore that inserts before reading its restore row: killed right after the
     * restore's COMMIT, before the mirror transaction; the runner replays the abort from the restore row: one restored
     * record, its id on the row, aborted, never restore_conflict.
     */
    public function test_committed_restore_is_replayed_to_completion(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_RECORD_DELETE, RetryHandler::AFTER_RESTORE], [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($child, RetryService::AFTER_RECORD_DELETE);
        // Q's clock passes the deadline: the child's publication inserts no fence row, and it aborts at once.
        $this->shiftClock($this->queueClockName(), 120);
        $this->release(RetryService::AFTER_RECORD_DELETE);
        $this->awaitHold($child, RetryHandler::AFTER_RESTORE);
        $child->stop();

        $id = $this->onlyOperation()->id;
        self::assertSame(Operations::ABORTING, $this->operation($id)->state);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame('retrying', $this->rowOrFail($uuid)->status);

        $this->shiftClock($this->queueClockName(), 4000);
        $this->moveDatabaseClocks(4000);
        $this->runOnce();

        $operation = $this->operation($id);
        self::assertSame([Operations::ABORTED, 'deadline_passed'], [$operation->state, $operation->error_code]);
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame((int) $this->failedRecordId($uuid), (int) $this->rowOrFail($uuid)->provider_id);
    }

    /**
     * Rejects: a resolution decided by time, and an autocommit resolution without a lock timeout: the publisher is
     * held inside its transaction, and Q's clock passes the deadline; the resolution waits for the lock and decides
     * nothing; after the publisher's COMMIT, the next run finalises.
     */
    public function test_resolution_waits_for_a_publisher_inside_its_transaction(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [Publication::BEFORE_COMMIT, RetryService::AFTER_COMMIT]);
        $this->awaitHold($child, Publication::BEFORE_COMMIT);
        $id = $this->onlyOperation()->id;
        $this->moveDatabaseClocks(120);

        if ($this->queueIsHostFile()) {
            $this->runOnce();
            self::assertSame(Operations::SQLITE_HOST_FILE, $this->operation($id)->awaiting_reason);
            $this->resolveAsOperator($id);
        } else {
            $report = $this->runOnce();
            self::assertSame('blocked', $report['stopped'], 'The resolution met the lock timeout.');
        }

        self::assertSame(Operations::CLAIMED, $this->operation($id)->state, 'Nothing was decided while the publisher held its key.');
        self::assertNull($this->fenceRow($id, Fence::PUBLISH), 'No cancelled row.');

        $this->release(Publication::BEFORE_COMMIT);
        $this->awaitHold($child, RetryService::AFTER_COMMIT);
        $child->stop();

        // A published row is read without a write: the runner finalises. On a host file the operator's
        // resolve above changed nothing, so the operation keeps its awaiting mark, the runner leaves it,
        // and the operator's next resolve finalises it.
        $this->moveDatabaseClocks(4000);
        $this->finishByRunnerOrOperator($id);
        self::assertSame(Operations::DONE, $this->operation($id)->state);
        self::assertSame(1, $this->jobsOf($id));
    }

    /**
     * Rejects: a deadline checked only by the clock read before BEGIN (MySQL and Postgres): the publisher is held
     * before its fence insert; the runner cancels; the retention at 0 days deletes the cancelled row; the resumed
     * publisher's conditional fence insert itself affects 0 rows by its own (moved) clock, and nothing is published.
     * On a host SQLite file the operator cancels, and the resumed publisher finds the cancelled row.
     */
    public function test_publisher_resumed_after_fence_retention_publishes_nothing(): void
    {
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [Publication::BEFORE_FENCE_INSERT]);
        $this->awaitHold($child, Publication::BEFORE_FENCE_INSERT);
        $id = $this->onlyOperation()->id;

        $this->moveDatabaseClocks(120);
        $this->finishByRunnerOrOperator($id);
        self::assertSame(Operations::ABORTED, $this->operation($id)->state);

        $this->app['config']->set('orrery.retry.fence_retention_days', 0);
        $this->app->make(OperationRetentionPhase::class)->run(new \Justrau\Orrery\Maintenance\TickBudget(\Justrau\Orrery\Storage\Clock::monotonic() + 30));
        $host = $this->queueIsHostFile();
        self::assertSame($host, $this->fenceRow($id, Fence::PUBLISH) !== null, 'The cancelled row went (it stays on a host file).');

        $this->release(Publication::BEFORE_FENCE_INSERT);
        $resumed = $this->childOutcome($child);

        self::assertSame(RetryResult::FAILED, $resumed['result']);
        // Without the cancelled row only the conditional insert's own deadline check refuses.
        self::assertContains($resumed['code'], $host ? ['cancelled', 'deadline_passed'] : ['deadline_passed']);
        self::assertSame(0, $this->jobsOf($id), 'Nothing was published.');
        self::assertSame(1, $this->failedRecords($uuid));
        self::assertSame($host ? Fence::CANCELLED : null, $this->fenceRow($id, Fence::PUBLISH)?->state);
    }

    /**
     * Rejects: publish-then-delete in the split topology: the record goes before the publication, so a worker that
     * fails the retried job while the publisher is still held after its COMMIT logs a new record with a new id (no
     * unique-uuid conflict), bound to its new occurrence; the publisher then finishes.
     */
    public function test_split_topology_deletes_before_publishing_and_a_quick_refailure_logs(): void
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob($this->job(failRuns: 2));
        $original = (int) $this->failedRecordId($uuid);
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_COMMIT], [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($child, RetryService::AFTER_COMMIT);
        self::assertSame(0, $this->failedRecords($uuid), 'The record went before the publication.');

        $worker = WorkerProcess::start(['queue:work', 'database', '--once', '--sleep=0', '--tries=1'], $this->testEnvironment(), [
            'ORRERY_TEST_PROVIDERS' => OperationCommandsProvider::class,
            OperationCommandsProvider::SPLIT_VARIABLE => '1',
        ]);
        self::assertSame(0, $worker->waitForExit(60), $worker->describe());
        self::assertStringNotContainsStringIgnoringCase('unique', $worker->output(), 'No unique-uuid conflict.');

        $refailure = (int) $this->failedRecordId($uuid);
        $row = $this->rowOrFail($uuid);
        self::assertNotSame($original, $refailure);
        self::assertSame(['failed', 2, $refailure], [$row->status, (int) $row->cycle, (int) $row->provider_id], $worker->describe());
        self::assertNotSame($occurrence, (int) $row->occurrence_id);

        $this->release(RetryService::AFTER_COMMIT);
        $outcome = $this->childOutcome($child);
        self::assertSame(RetryResult::ACCEPTED, $outcome['result']);
        self::assertSame(Operations::DONE, $this->operation((string) $outcome['operation_id'])->state, 'The worker adopted the retry first.');
        self::assertSame(1, $this->failedRecords($uuid));
    }

    /**
     * A mode-before retry in a child whose publication passes its deadline after the delete, so it aborts at once,
     * decides `deleted`, and is held before its restore.
     *
     * @return array{0: string, 1: int, 2: WorkerProcess, 3: string}
     */
    private function aborterHeldBeforeRestore(): array
    {
        $this->useSplitFailedConnection();
        [$uuid, $occurrence] = $this->failedJob();
        $child = $this->startRetry($uuid, $occurrence, [RetryService::AFTER_RECORD_DELETE, RetryHandler::BEFORE_RESTORE], [OperationCommandsProvider::SPLIT_VARIABLE => '1']);
        $this->awaitHold($child, RetryService::AFTER_RECORD_DELETE);
        $this->shiftClock($this->queueClockName(), 120);
        $this->release(RetryService::AFTER_RECORD_DELETE);
        $this->awaitHold($child, RetryHandler::BEFORE_RESTORE);

        $first = $this->onlyOperation()->id;
        self::assertSame(Operations::ABORTING, $this->operation($first)->state);
        self::assertSame(Fence::DELETED, $this->fenceRow($first, Fence::DELETE, self::FAILED_CONNECTION)?->state);
        $this->shiftClock($this->queueClockName(), 0.0);

        return [$uuid, $occurrence, $child, $first];
    }
}
