<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Bulk;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Bus;
use Justrau\Orrery\Queue\BulkResolver;
use Justrau\Orrery\Runtime\Degradation;
use Justrau\Orrery\Runtime\Watcher;
use Justrau\Orrery\Storage\Clock;
use Justrau\Orrery\Tests\Feature\Bulk\Fixtures\RecordingBulkResolver;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\BatchedJob;
use Justrau\Orrery\Tests\Feature\Bulk\Jobs\NestingBulkJob;
use Justrau\Orrery\Tests\Feature\Classification\Jobs\ClassifiedJob;
use Justrau\Orrery\Tests\Feature\Jobs\BasicJob;
use Justrau\Orrery\Tests\Support\FreshSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The bulk capture with the swap on.
 * Each case runs on both paths
 * of the swapped bulk() where the path matters.
 */
#[FreshSchema]
final class BulkCaptureTest extends BulkTestCase
{
    /**
     * Rejects: no P2 rows until the reservation.
     */
    #[DataProvider('paths')]
    public function test_bulk_jobs_are_pending_before_any_reservation(string $path): void
    {
        $this->usePath($path);

        $this->bulk([new BasicJob, new BasicJob, new BasicJob]);

        self::assertCount(3, $this->qJobs(), 'The bulk inserted three jobs rows.');
        $this->assertPendingParity('Before any reservation.');
        self::assertSame(3, $this->bulkRows(), 'P2 created each row.');
        self::assertSame(0, self::unverified());
        $this->assertNothingDeferred();

        $this->workHooks(3);

        foreach ($this->mirror()->table('orrery_jobs')->get() as $row) {
            self::assertSame('completed', $row->status, 'R1 and C1 then move the P2 rows.');
            self::assertSame(1, (int) $row->attempts);
        }
    }

    /**
     * Rejects: a mirror payload built from the job object instead of the inserted payload string.
     */
    #[DataProvider('paths')]
    public function test_bulk_mirror_rows_match_the_queue_payloads_before_reservation(string $path): void
    {
        $this->usePath($path);
        $delayed = (new BasicJob)->delay(120);

        $before = Clock::now();
        $this->bulk([new ClassifiedJob(['alpha', 'beta']), new BasicJob, $delayed], 'bulk');
        $after = Clock::now();

        $rows = $this->assertPendingParity('Generation parity of a bulk.');
        self::assertCount(3, $rows);

        $jobs = $this->qJobs();
        $tagged = $rows[(int) $jobs[0]->id];
        self::assertSame('bulk', $tagged->queue);
        self::assertSame(['alpha', 'beta'], $this->mirror()->table('orrery_job_tags')->where('job_uuid', $tagged->uuid)->orderBy('tag')->pluck('tag')->all(), 'The tag rows come from the inserted payload.');

        // Laravel stores whole seconds of its own sample (floor(t) + 120), and the mirror stores
        // its observation time plus the delay with microseconds. The two samples are different
        // moments, so their difference is up to 1 s plus the time between them, which grows
        // under load. Each value is bound by the samples of the test around the bulk instead.
        $late = $rows[(int) $jobs[2]->id];
        self::assertGreaterThanOrEqual(floor($before) + 120, (int) $jobs[2]->available_at, 'Laravel stores floor(t) + 120.');
        self::assertLessThanOrEqual(floor($after) + 120, (int) $jobs[2]->available_at, 'Laravel stores floor(t) + 120.');
        self::assertGreaterThanOrEqual($before + 120 - 0.000001, (float) $late->available_at, 'The delayed job keeps its available_at.');
        self::assertLessThanOrEqual($after + 120 + 0.000001, (float) $late->available_at, 'The delayed job keeps its available_at.');
    }

    /**
     * The level rose during the call, so the bulk is ambiguous: it runs at
     * T's commit, with its P2 rows resolved.
     *
     * Rejects: a level read only before the call (the rows run at once,
     * inside T), and an ambiguous item dropped at a commit.
     */
    #[DataProvider('paths')]
    public function test_bulk_whose_transaction_began_in_the_call_runs_at_the_commit(string $path): void
    {
        $this->usePath($path);
        $resolver = $this->recordingResolver();
        $q = $this->q();
        $began = false;

        $q->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$began): void {
            if (! $began && self::isJobsInsert($query)) {
                $began = true;
                $connection->beginTransaction();
            }
        });

        $this->deferEvents(function () use ($q): void {
            $this->bulk([new BasicJob, new BasicJob]);
            self::assertSame(1, $q->transactionLevel(), 'The callback began a Q transaction for the insert.');
            $q->commit();
        }, [QueryExecuted::class]);

        $this->endUnit();

        self::assertTrue($began);
        self::assertCount(2, $this->qJobs(), 'The host insert committed.');
        self::assertSame(2, $this->bulkRows(), "The P2 rows run at T's commit.");
        $this->assertPendingParity('The ambiguous bulk ran at the commit.');
        self::assertSame(0, self::unverified());
        self::assertSame(0, self::rollbackUnverified());
        self::assertCount(1, $resolver->observed, 'observe() ran once.');
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: tag storage only at P1 and P2.
     */
    public function test_swap_off_bulk_jobs_get_their_tag_rows_at_reservation(): void
    {
        $version = 7;
        $this->mirror()->table('orrery_monitored_tags')->insert(['tag' => 'watched', 'created_at' => Clock::toDecimal(Clock::now())]);
        $this->mirror()->table('orrery_settings')->updateOrInsert(['key' => 'monitoring_version'], ['value' => (string) $version]);

        $this->bulk([new ClassifiedJob(['watched', 'other']), new ClassifiedJob(['other'])]);

        self::assertCount(2, $this->qJobs());
        self::assertSame(0, $this->mirrorCount(), 'With the swap off, bulk jobs are unseen until reserved (§13).');

        $first = $this->qJobs()[0];
        $uuid = self::uuidOf((string) $first->payload);

        $this->workHooks();

        $row = $this->rowOrFail($uuid);
        self::assertSame('completed', $row->status, 'R7 created the row at the reservation, and C1 completed it.');
        self::assertSame(['other', 'watched'], $this->mirror()->table('orrery_job_tags')->where('job_uuid', $uuid)->orderBy('tag')->pluck('tag')->all(), 'R7 wrote the tag rows.');
        self::assertFalse((bool) $row->tags_truncated);
        self::assertTrue((bool) $row->monitored, 'R7 computed the monitored flag.');
        self::assertSame($version, (int) $row->monitor_version);
        self::assertSame(1, $this->mirrorCount(), 'The second job is still unseen.');
    }

    /**
     * Rejects: a capture token that pushToDatabase() does not suspend.
     */
    #[DataProvider('paths')]
    public function test_nested_dispatch_during_classification_is_not_captured(string $path): void
    {
        $this->usePath($path);

        // The nested push keeps its arming until its JobQueued event, which
        // the deferral holds back until after the bulk: a captured nested
        // record would get a P2 row first.
        $this->deferEvents(function (): void {
            $this->bulk([new NestingBulkJob('hooks'), new BasicJob]);
        }, [JobQueued::class]);

        $jobs = $this->qJobs();
        self::assertCount(3, $jobs, 'The nested push and the two bulk jobs.');
        self::assertSame(2, $this->bulkRows(), 'P2 created exactly the rows of the bulk insert.');

        $nested = $this->rowOrFail(self::uuidOf((string) $jobs[0]->payload));
        self::assertNull($nested->bulk_floor_id, 'The nested push is a P1 row.');
        self::assertSame('push', $nested->cycle_source);
        self::assertSame((int) $jobs[0]->id, (int) $nested->job_id);

        $this->assertPendingParity('The nested push and the bulk.');
        self::assertSame(0, self::unverified());
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: P2 rows for a bulk whose insert threw.
     */
    #[DataProvider('paths')]
    public function test_parent_error_emits_nothing(string $path): void
    {
        $this->usePath($path);
        $resolver = $this->recordingResolver();
        $error = new RuntimeException('bulk insert refused');

        $this->q()->beforeExecuting(static function (string $query) use ($error): void {
            if (self::isJobsInsert($query)) {
                throw $error;
            }
        });

        try {
            $this->bulk([new BasicJob, new BasicJob]);
            self::fail('The insert error leaves bulk().');
        } catch (RuntimeException $caught) {
            self::assertSame($error, $caught, 'The caller gets the same exception object.');
        }

        self::assertSame([], $this->qJobs());
        self::assertSame(0, $this->mirrorCount(), 'Nothing is emitted when the parent throws.');
        self::assertSame([], $resolver->observed);
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: a bulk build error that keeps a root tracked, or reaches
     * the host.
     */
    #[DataProvider('paths')]
    public function test_observe_exception_leaves_nothing_tracked(string $path): void
    {
        $this->usePath($path);
        $resolver = $this->recordingResolver();
        $resolver->throw = true;
        $q = $this->q();

        $q->beginTransaction();
        $result = $this->bulk([new BasicJob, new BasicJob]);
        $q->commit();

        self::assertTrue($result, 'The bulk returns normally.');
        self::assertCount(1, $resolver->observed, 'observe() ran once and threw.');
        self::assertArrayHasKey('hook:bulk.build', Degradation::record()['errors'] ?? [], 'The watcher recorded the error.');
        self::assertSame(0, $this->deferral()->open(), 'No root stays tracked.');

        $q->beginTransaction();
        $later = $this->push(new BasicJob);
        $q->commit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($later))->status, 'A push in a later root has its row before the end of the unit.');
        self::assertSame(1, $this->mirrorCount(), 'The bulk jobs have no P2 rows.');
        $this->assertNothingDeferred();
    }

    /**
     * Rejects: a mirror error that reaches Bus::batch().
     */
    public function test_bulk_during_a_mirror_outage_keeps_the_host_outcome(): void
    {
        $outage = $this->mirrorOutage();

        $batch = Bus::batch([new BatchedJob, new BatchedJob, new BatchedJob])->onConnection(self::QUEUE)->dispatch();

        $members = $this->qJobs();
        self::assertCount(3, $members, 'Bus::batch() inserted every member.');
        self::assertSame(3, $batch->pendingJobs);
        self::assertGreaterThan(0, self::dropped(), 'The dropped observations are counted.');

        $outage->end();

        // A separate mirror fails with connection errors, which are dependency
        // errors: they open the breaker for orrery.observer.breaker_seconds,
        // and this process skips observations until it closes. The
        // recovery is after that period.
        Watcher::advanceMonotonicForTesting((float) config('orrery.observer.breaker_seconds', 30) + 1.0);
        self::assertFalse(Watcher::instance()->breakerOpen(), 'The breaker is closed after its period.');
        self::assertSame(0, $this->mirrorCount(), 'The outage dropped every P2 row: no member has a row before its reservation.');

        // The mirror row of each member just before its handler runs: the
        // reservation must have created it (R7), not the completion.
        $seen = [];
        $this->app['events']->listen(JobProcessing::class, function (JobProcessing $event) use (&$seen): void {
            $uuid = strtolower((string) $event->job->uuid());
            $seen[$uuid] = $this->row($uuid)?->status;
        });

        $this->workHooks(3);

        $expected = [];

        foreach ($members as $member) {
            $expected[self::uuidOf((string) $member->payload)] = 'reserved';
        }

        ksort($expected);
        ksort($seen);
        self::assertSame($expected, $seen, 'Each member had a reserved row (R7) before its handler ran.');

        $fresh = Bus::findBatch($batch->id);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->finished(), 'The batch finished as without the package.');
        self::assertSame(0, $fresh->failedJobs);

        foreach ($members as $member) {
            $data = json_decode((string) $member->payload, true)['data'] ?? [];
            $row = $this->rowOrFail(self::uuidOf((string) $member->payload));
            self::assertSame('completed', $row->status, 'After recovery, the reservation created the row (R7) and C1 completed it.');

            // The mirror takes batch_id from the payload's data.batchId.
            // Laravel adds that key from v12.51.0 on; an older payload has none,
            // so the row's batch_id stays null there (a documented limit).
            self::assertSame(array_key_exists('batchId', $data) ? $batch->id : null, $row->batch_id, 'The row carries the batch id that its payload carries.');
        }

        self::assertSame(3, $this->mirrorCount());
    }

    /**
     * B's rollback callback throws once, so B stays the parent of the later
     * savepoints: C's bulk commits through the stale B, and D's rollback
     * removes the committed C through it. Neither bulk has a true chain of
     * marks: both are counted, and R7 creates C's
     * rows at the reservations.
     *
     * Rejects: an incomplete-chain bulk dropped without a count, and a bulk
     * run without a proof of its commit.
     */
    #[DataProvider('paths')]
    public function test_bulk_with_an_incomplete_witness_chain_is_counted(string $path): void
    {
        $this->usePath($path);
        $q = $this->q();

        $q->beginTransaction();
        $a = $this->push(new BasicJob);
        $q->beginTransaction();
        $b = $this->push(new BasicJob);
        $bUuid = $this->uuidOfJob($b);
        $calls = 0;
        $this->record()->addCallbackForRollback(static function () use (&$calls): void {
            if ($calls++ === 0) {
                throw new RuntimeException('b rollback callback failed once');
            }
        });

        self::applicationError(static fn () => $q->rollBack(), 'b rollback callback failed once');

        $q->beginTransaction();
        $this->bulk([new BasicJob, new BasicJob]);
        $q->commit();
        $cIds = array_values(array_filter(array_map(static fn (object $job): int => (int) $job->id, $this->qJobs()), static fn (int $id): bool => $id !== $a));

        $q->beginTransaction();
        $this->bulk([new BasicJob]);
        $dJobs = array_values(array_filter($this->qJobs(), static fn (object $job): bool => ! in_array((int) $job->id, [$a, ...$cIds], true)));
        self::assertCount(1, $dJobs);
        $dUuid = self::uuidOf((string) $dJobs[0]->payload);
        // Laravel walks through the stale B and removes the committed C.
        $q->rollBack();
        self::assertSame(1, $q->transactionLevel());

        $q->commit();

        self::assertSame('pending', $this->rowOrFail($this->uuidOfJob($a))->status);
        self::assertCount(2, $cIds);

        $cUuids = array_map(fn (int $id): string => $this->uuidOfJob($id), $cIds);

        foreach ($cUuids as $uuid) {
            self::assertNull($this->row($uuid), "C's bulk job has no proof of its commit.");
        }

        self::assertNull($this->row($bUuid), "B's push leaves none.");
        self::assertNull($this->row($dUuid), "D's bulk leaves none.");
        self::assertSame(1, $this->mirrorCount());
        self::assertSame(2, self::unverified(), "C's and D's bulks are counted once each.");
        self::assertSame(0, self::rollbackUnverified());
        $this->assertNothingDeferred();

        $this->workHooks(3);

        foreach ($cUuids as $uuid) {
            self::assertSame('completed', $this->rowOrFail($uuid)->status, "R7 creates C's row at the reservation.");
        }
    }

    private function recordingResolver(): RecordingBulkResolver
    {
        $resolver = $this->app->make(RecordingBulkResolver::class);
        $this->app->instance(BulkResolver::class, $resolver);

        return $resolver;
    }
}
